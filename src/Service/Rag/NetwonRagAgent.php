<?php

namespace App\Service\Rag;

use App\Repository\TicketRepository;
use App\Service\SiteCapacityResolver;
use App\Service\StatisticsService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NetwonRagAgent
{
    private const CACHE_TTL = 300;

    private Connection $connection;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly StatisticsService $statisticsService,
        private readonly TicketRepository $ticketRepository,
        private readonly SiteCapacityResolver $siteCapacityResolver,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
        $this->connection = $entityManager->getConnection();
    }

    /**
     * @param array{expert?: bool} $options
     */
    public function answer(string $question, array $history = [], array $options = []): array
    {
        $expert = $this->resolveExpertMode($question, $options);
        $question = $this->normalizeQuestion($question);
        if ($question === '') {
            return $this->buildResponse(
                'Veuillez saisir une question sur le réseau, les sites, le trafic ou les tickets.',
                [],
                'local',
                true
            );
        }

        $resolvedQuestion = $this->resolveQuestionWithHistory($question, $history);
        $prepared = $this->prepareAnswer($question, $resolvedQuestion);
        if ($prepared !== null) {
            return $this->applyExpertMode($prepared, $expert, $this->retrieveDocuments($resolvedQuestion));
        }

        $documents = $this->retrieveDocuments($resolvedQuestion);
        $context = $this->formatContext($documents);
        $prompt = $this->buildPrompt($question, $context, $history, $expert);
        $sources = array_column($documents, 'title');

        try {
            return $this->applyExpertMode(
                $this->buildResponse($this->askOllama($prompt), $sources, 'ollama', false),
                $expert,
                $documents
            );
        } catch (\Throwable) {
            return $this->applyExpertMode(
                $this->buildResponse($this->buildFallbackAnswer($question, $documents), $sources, 'fallback', true),
                $expert,
                $documents
            );
        }
    }

    /**
     * @param array<int, array{role?: string, content?: string}> $history
     *
     * @return array<int, string>
     */
    public function getContextualSuggestions(array $history): array
    {
        $sites = [];
        foreach (array_reverse($history) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $sites = array_merge($sites, $this->extractSiteTokens((string) ($message['content'] ?? '')));
        }

        $sites = array_values(array_unique($sites));
        $site = $sites[0] ?? null;

        if ($site !== null) {
            return [
                sprintf('Quelle est la capacite de %s ?', strtoupper($site)),
                sprintf('Le site %s est-il critique ?', strtoupper($site)),
                sprintf('Trafic max et occurrences pour %s', strtoupper($site)),
                '/top',
            ];
        }

        return [
            'Quels sont les sites critiques sur la derniere analyse ?',
            '/stats',
            '/tickets',
            'Resume les congestions les plus importantes',
        ];
    }

    /**
     * @param callable(string): void $onChunk
     *
     * @return array{answer: string, sources: array, model: string, fallback: bool, source: string}
     */
    /**
     * @param array{expert?: bool} $options
     */
    public function answerWithStream(string $question, array $history, callable $onChunk, array $options = []): array
    {
        $expert = $this->resolveExpertMode($question, $options);
        $question = $this->normalizeQuestion($question);
        if ($question === '') {
            $payload = $this->buildResponse(
                'Veuillez saisir une question sur le réseau, les sites, le trafic ou les tickets.',
                [],
                'local',
                true
            );
            $onChunk($payload['answer']);

            return $payload;
        }

        $resolvedQuestion = $this->resolveQuestionWithHistory($question, $history);
        $prepared = $this->prepareAnswer($question, $resolvedQuestion);
        if ($prepared !== null) {
            $payload = $this->applyExpertMode($prepared, $expert, $this->retrieveDocuments($resolvedQuestion));
            $onChunk($payload['answer']);

            return $payload;
        }

        $documents = $this->retrieveDocuments($resolvedQuestion);
        $context = $this->formatContext($documents);
        $prompt = $this->buildPrompt($question, $context, $history, $expert);
        $sources = array_column($documents, 'title');

        try {
            $answer = $this->askOllamaStream($prompt, $onChunk);

            return $this->applyExpertMode($this->buildResponse($answer, $sources, 'ollama', false), $expert, $documents);
        } catch (\Throwable) {
            $answer = $this->buildFallbackAnswer($question, $documents);
            $onChunk($answer);

            return $this->applyExpertMode($this->buildResponse($answer, $sources, 'fallback', true), $expert, $documents);
        }
    }

    private function prepareAnswer(string $question, string $resolvedQuestion): ?array
    {

        $slashAnswer = $this->tryBuildSlashCommandAnswer($question);
        if ($slashAnswer !== null) {
            return $slashAnswer;
        }

        if ($this->isOutOfScopeQuestion($question)) {
            return $this->buildResponse(
                "Je suis spécialisé dans la supervision du réseau 5G Orange (trafic, capacité, congestion, anomalies IA, tickets).\n\n"
                . "Cette question semble hors de mon domaine.\n\n"
                . "Je peux vous aider avec :\n"
                . "- L'état des sites réseau (congestion, bridage, capacité)\n"
                . "- Les anomalies détectées par l'IA\n"
                . "- Les tickets et workflows en cours\n\n"
                . "Essayez **/stats**, **/top** ou **/tickets** pour commencer.",
                [],
                'out_of_scope',
                true
            );
        }

        $definitionAnswer = $this->tryBuildDefinitionAnswer($question);
        if ($definitionAnswer !== null) {
            return $definitionAnswer;
        }

        $fastAnswer = $this->tryBuildFastAnswer($question, $resolvedQuestion);
        if ($fastAnswer !== null) {
            return $fastAnswer;
        }

        $documents = $this->retrieveDocuments($resolvedQuestion);
        if ($this->isDirectSiteMetricQuestion($question)) {
            $directAnswer = $this->buildSiteFallbackAnswer($question, $documents);
            if ($directAnswer !== null) {
                return $this->buildResponse(
                    $directAnswer,
                    array_column($documents, 'title'),
                    'database',
                    false
                );
            }
        }

        return null;
    }

    /**
     * @param array{expert?: bool} $options
     */
    private function resolveExpertMode(string $question, array $options): bool
    {
        if (($options['expert'] ?? false) === true) {
            return true;
        }

        return (bool) preg_match('/^\[Mode:\s*Expert/iu', trim($question));
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     *
     * @param array{answer: string, sources: array, model: string, fallback: bool, source: string} $response
     *
     * @return array{answer: string, sources: array, model: string, fallback: bool, source: string, raw_data?: string}
     */
    private function applyExpertMode(array $response, bool $expert, array $documents): array
    {
        if (!$expert) {
            return $response;
        }

        $rawData = $this->formatContext($documents);
        if ($rawData === '') {
            return $response;
        }

        $response['raw_data'] = $rawData;

        return $response;
    }

    private function normalizeQuestion(string $question): string
    {
        $question = preg_replace('/^\[Mode:\s*[^\]]+\]\s*/u', '', trim($question)) ?? trim($question);

        return trim(mb_substr($question, 0, 1200));
    }

    /**
     * @param array<int, string> $sources
     *
     * @return array{answer: string, sources: array, model: string, fallback: bool, source: string}
     */
    private function buildResponse(string $answer, array $sources, string $source, bool $fallback): array
    {
        return [
            'answer' => $answer,
            'sources' => $sources,
            'model' => $this->getModel(),
            'fallback' => $fallback,
            'source' => $source,
        ];
    }

    private function retrieveDocuments(string $question): array
    {
        if (!$this->shouldUseFullRag($question)) {
            return $this->retrieveLeanDocuments($question);
        }

        $documents = [
            [
                'title' => 'Statistiques globales réseau',
                'content' => $this->buildGlobalStatsDocument(),
            ],
        ];

        $siteMatches = $this->findRelevantSites($question);
        if ($siteMatches !== []) {
            $documents[] = [
                'title' => 'Sites correspondants à la question',
                'rows' => $siteMatches,
                'content' => $this->formatRows($siteMatches, [
                    'site',
                    'classification',
                    'type_trans',
                    'site_tdd',
                    'site_fdd',
                    'capacite_tdd_mbps',
                    'capacite_fdd_mbps',
                    'max_trafic',
                    'capacite_mbps',
                    'nombre_occurrences',
                    'duree_jours',
                    'date_analyse',
                ]),
            ];
        }

        $criticalSites = $this->findCriticalSitesCached();
        if ($criticalSites !== []) {
            $documents[] = [
                'title' => 'Top sites critiques',
                'rows' => $criticalSites,
                'content' => $this->formatRows($criticalSites, [
                    'site',
                    'classification',
                    'type_trans',
                    'max_trafic',
                    'capacite_mbps',
                    'nombre_occurrences',
                    'duree_jours',
                ]),
            ];
        }

        $healthRisks = $this->statisticsService->getTopSiteHealthRisks(8);
        if ($healthRisks !== []) {
            $documents[] = [
                'title' => 'Top risques santé réseau',
                'rows' => $healthRisks,
                'content' => $this->formatRows($healthRisks, [
                    'site',
                    'etat',
                    'trafic_j',
                    'capacite_mbps',
                    'taux_utilisation',
                    'taux_utilisation_tdd',
                    'taux_utilisation_fdd',
                    'variation_j1',
                    'variation_j7',
                    'type_trans',
                    'classification',
                    'nombre_occurrences',
                ]),
            ];
        }

        $ticketDocument = $this->buildTicketDocument();
        if ($ticketDocument !== '') {
            $documents[] = [
                'title' => 'Tickets et workflow',
                'content' => $ticketDocument,
            ];
        }

        return array_slice($documents, 0, 6);
    }

    private function tryBuildSlashCommandAnswer(string $question): ?array
    {
        $command = mb_strtolower(trim($question));

        return match (true) {
            str_starts_with($command, '/stats') => $this->buildAgentResponse(
                $this->buildGlobalStatsAnswer(),
                ['Statistiques globales réseau'],
                'fastpath'
            ),
            str_starts_with($command, '/top') => $this->buildAgentResponse(
                $this->buildCriticalSitesAnswer($this->findCriticalSitesCached()),
                ['Top sites critiques'],
                'fastpath'
            ),
            str_starts_with($command, '/tickets') => $this->buildAgentResponse(
                $this->buildTicketStatsAnswer(),
                ['Tickets et workflow'],
                'fastpath'
            ),
            str_starts_with($command, '/rapport') => $this->buildGovernorateReportResponse(),
            str_starts_with($command, '/aide'), str_starts_with($command, '/help') => $this->buildAgentResponse(
                "Commandes disponibles:\n"
                . "/stats — statistiques globales du réseau\n"
                . "/top — top sites critiques (dernière analyse)\n"
                . "/tickets — état des tickets workflow\n"
                . "/rapport — rapport interactif par gouvernorat (graphe + PDF)\n"
                . "/aide — cette aide\n\n"
                . "Vous pouvez aussi poser une question naturelle sur un site (ex: NAB_0187), sa capacité, son trafic ou sa classification.",
                ['Aide NetWON'],
                'fastpath'
            ),
            default => null,
        };
    }

    private function tryBuildDefinitionAnswer(string $question): ?array
    {
        if (!$this->isDefinitionQuestion($question)) {
            return null;
        }

        $normalized = mb_strtolower($question);

        if (preg_match('/\bswap\b/u', $normalized)) {
            return $this->buildAgentResponse(
                "Le swap désigne le remplacement ou la montée en capacité d'une carte ou d'un équipement réseau (souvent passage 1G → 10G).\n"
                . "Dans NetWON, un site peut être marqué « swap requis » lorsque la carte actuelle ne couvre plus le trafic attendu.\n"
                . "Action: vérifier swap_required / swap_comment dans l'UMPT ou le ticket associé avant planification terrain.",
                ['Glossaire métier'],
                'fastpath'
            );
        }

        if (preg_match('/cotrans|co-trans/u', $normalized)) {
            return $this->buildAgentResponse(
                "Un site cotrans partage la même infrastructure entre liaisons TDD et FDD (classification COTRANS).\n"
                . "La capacité et le trafic doivent être lus côté TDD et FDD séparément quand capacite_tdd_mbps / capacite_fdd_mbps sont renseignés.\n"
                . "NO_COTRANS signifie l'inverse: pas de partage cotrans sur ce site.",
                ['Glossaire métier'],
                'fastpath'
            );
        }

        if (preg_match('/\bfdd\b.*\btdd\b|\btdd\b.*\bfdd\b|diff[eé]rence.*(fdd|tdd)/u', $normalized)) {
            return $this->buildAgentResponse(
                "TDD (Time Division Duplex) et FDD (Frequency Division Duplex) sont deux modes radio LTE.\n"
                . "- TDD: même bande, partage temporel montée/descente.\n"
                . "- FDD: bandes distinctes pour montée et descente.\n"
                . "Dans NetWON: utilisez capacite_tdd_mbps et capacite_fdd_mbps; ONLY_FDD / FDD indique un site principalement côté FDD.",
                ['Glossaire métier'],
                'fastpath'
            );
        }

        if (preg_match('/occurrence.*critique|critique.*occurrence/u', $normalized)) {
            return $this->buildAgentResponse(
                "Une occurrence critique correspond à un nombre d'occurrences de congestion élevé sur la période analysée "
                . "(seuil dynamique : base 50 occurrences / 7 jours, proportionnel à duree_jours).\n"
                . "Un site au-dessus du seuil doit être priorisé pour action réseau (swap, optimisation, extension capacité).",
                ['Glossaire métier'],
                'fastpath'
            );
        }

        if (preg_match('/taux.*utilisation|utilisation.*taux/u', $normalized)) {
            return $this->buildAgentResponse(
                "Le taux d'utilisation = trafic max / capacité effective (affichage type 95,76 %).\n"
                . "- Taux GLOBAL: utilise la capacité FDD (si ONLY_FDD) ou TDD+FDD (sinon).\n"
                . "- Taux TDD: max_tdd / capacite_tdd_mbps.\n"
                . "- Taux FDD: max_fdd / capacite_fdd_mbps.\n"
                . "- Criticité (CRITIQUE / SOUS OBSERVATION) : uniquement selon les occurrences (seuils dynamiques 50 et 20 / 7 j × durée).\n"
                . "- Un taux > 90% est critique, > 70% est élevé.",
                ['Glossaire métier'],
                'fastpath'
            );
        }

        return null;
    }

    private function isDefinitionQuestion(string $question): bool
    {
        return (bool) preg_match(
            '/\b(c\'est quoi|qu\'est-ce|qu est ce|définition|definition|explique|signifie|veut dire|différence|difference)\b/ui',
            $question
        );
    }

    private function shouldUsePreviousSiteContext(string $question): bool
    {
        if ($this->isDefinitionQuestion($question)) {
            return false;
        }

        if ($this->isSmallTalkQuestion($question) || str_starts_with(mb_strtolower(trim($question)), '/')) {
            return false;
        }

        return (bool) preg_match('/\b(il|elle|ce site|celui-ci|son|sa|leur|capacit|trafic|classification|occurrence|fdd|tdd)\b/ui', $question);
    }

    private function tryBuildFastAnswer(string $question, string $resolvedQuestion): ?array
    {
        if ($this->isSmallTalkQuestion($question)) {
            return $this->buildAgentResponse(
                'Bonjour. Posez une question sur un site, la capacité, le trafic, les sites critiques ou les tickets.',
                ['Assistant local'],
                'fastpath'
            );
        }

        if ($this->isDirectSiteMetricQuestion($question) && $this->extractSiteTokens($resolvedQuestion) !== []) {
            $siteMatches = $this->findRelevantSites($resolvedQuestion);
            if ($siteMatches !== []) {
                $documents = [[
                    'title' => 'Sites correspondants à la question',
                    'rows' => $siteMatches,
                    'content' => $this->formatRows($siteMatches, [
                        'site',
                        'classification',
                        'type_trans',
                        'site_tdd',
                        'site_fdd',
                        'capacite_tdd_mbps',
                        'capacite_fdd_mbps',
                        'max_trafic',
                        'capacite_mbps',
                        'nombre_occurrences',
                        'duree_jours',
                        'date_analyse',
                    ]),
                ]];
                $answer = $this->buildSiteFallbackAnswer($question, $documents);

                if ($answer !== null) {
                    return $this->buildAgentResponse($answer, array_column($documents, 'title'));
                }
            }
        }

        if ($this->isTicketStatsQuestion($question)) {
            return $this->buildAgentResponse(
                $this->buildTicketStatsAnswer(),
                ['Tickets et workflow']
            );
        }

        if ($this->isGlobalStatsQuestion($question)) {
            return $this->buildAgentResponse(
                $this->buildGlobalStatsAnswer(),
                ['Statistiques globales reseau']
            );
        }

        if ($this->isCriticalListQuestion($question)) {
            return $this->buildAgentResponse(
                $this->buildCriticalSitesAnswer($this->findCriticalSitesCached()),
                ['Top sites critiques'],
                'database'
            );
        }

        if ($this->isReportRequest($question)) {
            return $this->buildGovernorateReportResponse();
        }

        return null;
    }

    private function retrieveLeanDocuments(string $question): array
    {
        $documents = [];
        $siteMatches = $this->findRelevantSites($question);

        if ($siteMatches !== []) {
            $documents[] = [
                'title' => 'Sites correspondants à la question',
                'rows' => $siteMatches,
                'content' => $this->formatRows($siteMatches, [
                    'site',
                    'classification',
                    'type_trans',
                    'site_tdd',
                    'site_fdd',
                    'capacite_tdd_mbps',
                    'capacite_fdd_mbps',
                    'max_trafic',
                    'capacite_mbps',
                    'nombre_occurrences',
                    'duree_jours',
                    'date_analyse',
                ]),
            ];
        }

        if ($this->shouldIncludeGlobalStats($question) || $documents === []) {
            $documents[] = [
                'title' => 'Statistiques globales réseau',
                'content' => $this->buildGlobalStatsDocument(),
            ];
        }

        if ($this->shouldIncludeCriticalSites($question)) {
            $criticalSites = $this->findCriticalSitesCached();
            if ($criticalSites !== []) {
                $documents[] = [
                    'title' => 'Top sites critiques',
                    'rows' => $criticalSites,
                    'content' => $this->formatRows($criticalSites, [
                        'site',
                        'classification',
                        'type_trans',
                        'max_trafic',
                        'capacite_mbps',
                        'nombre_occurrences',
                        'duree_jours',
                    ]),
                ];
            }
        }

        if ($this->shouldIncludeTickets($question)) {
            $ticketDocument = $this->buildTicketDocument();
            if ($ticketDocument !== '') {
                $documents[] = [
                    'title' => 'Tickets et workflow',
                    'content' => $ticketDocument,
                ];
            }
        }

        return array_slice($documents, 0, 3);
    }

    private function buildAgentResponse(string $answer, array $sources, string $source = 'database'): array
    {
        return $this->buildResponse($answer, $sources, $source, false);
    }

    private function buildGlobalStatsDocument(): string
    {
        return $this->cache->get('rag_global_stats_doc', function (ItemInterface $item): string {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->buildGlobalStatsDocumentUncached();
        });
    }

    private function findCriticalSitesCached(): array
    {
        return $this->findCriticalSites();
    }

    private function buildGlobalStatsAnswer(): string
    {
        $stats = $this->statisticsService->getGlobalStats();
        $health = $this->statisticsService->getSiteHealthStats();
        $lastDate = $this->statisticsService->getLastAnalyseDate() ?? 'aucune analyse';

        return sprintf(
            "Derniere analyse: %s\nSites: total=%d, critiques=%d, congestionnes=%d\nTypes: FO=%d, FH=%d, Backhaul Sharing=%d, sans type=%d\nOccurrences totales: %d\nSante reseau: total=%d, congestionnes=%d, brides=%d, OK=%d, taux moyen=%s%%.",
            $lastDate,
            $stats['total_sites'] ?? 0,
            $this->statisticsService->getTotalSitesCritiquesCount(),
            $stats['sites_congestionnes'] ?? 0,
            $stats['fo'] ?? 0,
            $stats['fh'] ?? 0,
            $stats['backbone'] ?? 0,
            $stats['sans_type'] ?? 0,
            $stats['total_occurrences'] ?? 0,
            $health['total'] ?? 0,
            $health['congestionnes'] ?? 0,
            $health['brides'] ?? 0,
            $health['ok'] ?? 0,
            $health['avg_taux'] ?? 0
        );
    }

    private function buildCriticalSitesAnswer(array $sites): string
    {
        if ($sites === []) {
            return 'Aucun site critique trouve pour la derniere analyse.';
        }

        $lines = ['Top sites critiques:'];
        foreach (array_slice($sites, 0, 8) as $site) {
            $lines[] = sprintf(
                '- %s: occurrences=%s, trafic max=%s Mbps, capacite=%s Mbps, type=%s, classification=%s',
                $site['site'] ?? '-',
                $site['nombre_occurrences'] ?? '-',
                isset($site['max_trafic']) ? $this->formatNumber($site['max_trafic']) : '-',
                isset($site['capacite_mbps']) ? $this->formatNumber($site['capacite_mbps']) : '-',
                $site['type_trans'] ?? '-',
                $site['classification'] ?? '-'
            );
        }
        $lines[] = 'Action: prioriser les premiers sites avec forte occurrence et trafic proche ou superieur a la capacite.';

        return implode("\n", $lines);
    }

    private function buildTicketStatsAnswer(): string
    {
        try {
            $stats = $this->ticketRepository->getStatistics();
        } catch (\Throwable) {
            return 'Les statistiques tickets ne sont pas disponibles actuellement.';
        }

        return sprintf(
            'Tickets: total=%d, ouverts=%d, en cours=%d, validation=%d, clotures=%d, en retard=%d.',
            $stats['total'] ?? 0,
            $stats['open'] ?? 0,
            $stats['in_progress'] ?? 0,
            $stats['pending'] ?? 0,
            $stats['closed'] ?? 0,
            $stats['overdue'] ?? 0
        );
    }

    private function shouldUseFullRag(string $question): bool
    {
        if ($this->isDirectSiteMetricQuestion($question)) {
            return false;
        }

        return (bool) preg_match('/analyse|rapport|synthese|action|priorit|recommand|diagnostic|cause|pourquoi|zone|gouvernorat/i', $question);
    }

    private function shouldIncludeGlobalStats(string $question): bool
    {
        return (bool) preg_match('/global|stat|nombre|combien|total|repartition|resume|synthese|analyse|rapport|dashboard|reseau/i', $question);
    }

    private function shouldIncludeCriticalSites(string $question): bool
    {
        return (bool) preg_match('/critique|congestion|risque|priorit|top|plus important|sature/i', $question);
    }

    private function shouldIncludeHealthRisks(string $question): bool
    {
        return (bool) preg_match('/sante|health|risque|bride|bridage|variation|taux/i', $question);
    }

    private function shouldIncludeTickets(string $question): bool
    {
        return $this->isTicketStatsQuestion($question);
    }

    private function isSmallTalkQuestion(string $question): bool
    {
        return (bool) preg_match('/^\s*(bonjour|salut|hello|hi|merci|ca va|cava)\s*[.!?]*\s*$/i', $question);
    }

    private function isCriticalListQuestion(string $question): bool
    {
        return (bool) preg_match('/\b(top|liste|affiche|montre|donne|quels?)\b.*\b(site|sites|critique|critiques|congestion|congestionnes|risque|risques)\b|\b(site|sites)\b.*\b(critique|critiques|congestion|congestionnes|risque|risques)\b/i', $question);
    }

    private function isGlobalStatsQuestion(string $question): bool
    {
        if ($this->isTicketStatsQuestion($question)) {
            return false;
        }
        $hasStatsKeyword = (bool) preg_match('/\b(stat|stats|statistique|statistiques|combien|nombre|total|repartition|resume global|dashboard)\b/i', $question);
        if (!$hasStatsKeyword) {
            return false;
        }
        // "combien/nombre/total" seuls sont trop larges — exiger aussi un mot réseau
        $hasNetworkKeyword = (bool) preg_match('/\b(site|sites|reseau|r[ée]seau|trafic|capacit[ée]|congestion|bridage|fo|fh|backhaul|backbone|alerte|liaison|occurrence|kpi|mbps|anomalie)\b/i', $question);
        return $hasNetworkKeyword;
    }

    private function isOutOfScopeQuestion(string $question): bool
    {
        // Considéré hors-domaine si aucun mot réseau/supervision n'est présent
        return !(bool) preg_match('/\b(site|sites|reseau|r[ée]seau|trafic|capacit[ée]|congestion|bridage|fo|fh|backhaul|backbone|alerte|liaison|occurrence|kpi|mbps|anomalie|ticket|workflow|analyse|signal|orange|5g)\b/i', $question);
    }

    private function isTicketStatsQuestion(string $question): bool
    {
        return (bool) preg_match('/ticket|workflow|tache|incident|ouvert|retard/i', $question);
    }

    private function buildGlobalStatsDocumentUncached(): string
    {
        $stats = $this->statisticsService->getGlobalStats();
        $health = $this->statisticsService->getSiteHealthStats();
        $governorates = $this->statisticsService->getSiteHealthByGovernorate();
        $lastDate = $this->statisticsService->getLastAnalyseDate() ?? 'aucune analyse';

        $lines = [
            sprintf('Dernière analyse: %s', $lastDate),
            sprintf('Sites total: %d', $stats['total_sites'] ?? 0),
            sprintf('Sites critiques: %d', $this->statisticsService->getTotalSitesCritiquesCount()),
            sprintf('Sites congestionnés: %d', $stats['sites_congestionnes'] ?? 0),
            sprintf('Répartition: FO=%d, FH=%d, Backhaul Sharing=%d, Sans type=%d', $stats['fo'] ?? 0, $stats['fh'] ?? 0, $stats['backbone'] ?? 0, $stats['sans_type'] ?? 0),
            sprintf('Occurrences totales: %d', $stats['total_occurrences'] ?? 0),
            sprintf('Santé réseau: total=%d, congestionnés=%d, bridés=%d, OK=%d, taux moyen=%s%%', $health['total'] ?? 0, $health['congestionnes'] ?? 0, $health['brides'] ?? 0, $health['ok'] ?? 0, $health['avg_taux'] ?? 0),
        ];

        if ($governorates !== []) {
            $lines[] = 'Gouvernorats à risque: ' . implode('; ', array_map(
                static fn (array $row): string => sprintf(
                    '%s total=%d congestionnés=%d bridés=%d max_taux=%s%%',
                    $row['gouvernorat'] ?? '-',
                    $row['total'] ?? 0,
                    $row['congestionnes'] ?? 0,
                    $row['brides'] ?? 0,
                    $row['max_taux'] ?? 0
                ),
                $governorates
            ));
        }

        return implode("\n", $lines);
    }

    private function findRelevantSites(string $question): array
    {
        $tokens = $this->extractSiteTokens($question);
        if ($tokens === []) {
            return [];
        }

        $lastDate = $this->statisticsService->getLastAnalyseDate();
        if (!$lastDate) {
            return [];
        }

        $conditions = [];
        $params = ['date' => $lastDate];
        foreach (array_slice($tokens, 0, 6) as $index => $token) {
            $paramName = 'token' . $index;
            $conditions[] = "LOWER(site) LIKE :$paramName";
            $params[$paramName] = '%' . mb_strtolower($token) . '%';
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                ar.site,
                ar.classification,
                ar.type_trans,
                ar.site_tdd,
                ar.site_fdd,
                (
                    SELECT cs.capacite_mbps
                    FROM capacite_site cs
                    WHERE UPPER(cs.site) = UPPER(ar.site_tdd)
                    ORDER BY cs.date_mise_ajour DESC, cs.id DESC
                    LIMIT 1
                ) AS capacite_tdd_mbps,
                (
                    SELECT cs.capacite_mbps
                    FROM capacite_site cs
                    WHERE UPPER(cs.site) = UPPER(ar.site_fdd)
                    ORDER BY cs.date_mise_ajour DESC, cs.id DESC
                    LIMIT 1
                ) AS capacite_fdd_mbps,
                ar.max_trafic,
                ar.capacite_mbps,
                ar.nombre_occurrences,
                COALESCE(NULLIF(ar.duree_jours, 0), 7) AS duree_jours,
                ar.date_analyse
             FROM analyse_resultat ar
             WHERE ar.date_analyse = :date AND (' . implode(' OR ', $conditions) . ')
             ORDER BY ar.nombre_occurrences DESC, ar.max_trafic DESC
             LIMIT 12',
            $params
        );

        return $this->applyDynamicCapacities($rows);
    }

    private function findCriticalSites(): array
    {
        $lastDate = $this->statisticsService->getLastAnalyseDate();
        if (!$lastDate) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT site, classification, type_trans, site_tdd, site_fdd, max_trafic, capacite_mbps, nombre_occurrences, COALESCE(NULLIF(duree_jours, 0), 7) AS duree_jours, date_analyse
             FROM analyse_resultat
             WHERE date_analyse = :date
               AND nombre_occurrences >= CEIL(50.0 * COALESCE(NULLIF(duree_jours, 0), 7) / 7.0)
             ORDER BY nombre_occurrences DESC, max_trafic DESC
             LIMIT 10',
            ['date' => $lastDate]
        );

        return $this->applyDynamicCapacities($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyDynamicCapacities(array $rows): array
    {
        foreach ($rows as &$row) {
            $site = trim((string) ($row['site'] ?? ''));
            if ($site === '') {
                continue;
            }

            $capacity = $this->siteCapacityResolver->resolveForCourbe(
                $this->connection,
                $site,
                $row,
                $this->guessSiteVariant($site, 'TF'),
                $this->siteVariantOrFallback($row['site_fdd'] ?? null, $site, 'LM'),
                $this->siteVariantOrFallback($row['site_tdd'] ?? null, $site, 'TC'),
                $site
            );

            $row['capacite_tdd_mbps'] = $capacity['capacite_tdd_mbps'];
            $row['capacite_fdd_mbps'] = $capacity['capacite_fdd_mbps'];
            $row['capacite_mbps'] = $capacity['capacityMbps'];
            $row['capacite_source'] = 'dernier_import';
        }
        unset($row);

        return $rows;
    }

    private function guessSiteVariant(string $site, string $suffix): string
    {
        $base = preg_replace('/_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$/i', '', trim($site)) ?: trim($site);

        return $base . '_' . $suffix;
    }

    private function siteVariantOrFallback(mixed $candidate, string $site, string $suffix): string
    {
        $candidate = trim((string) $candidate);

        return $candidate !== '' ? $candidate : $this->guessSiteVariant($site, $suffix);
    }

    private function buildTicketDocument(): string
    {
        try {
            $stats = $this->ticketRepository->getStatistics();
            $recentTickets = $this->connection->fetchAllAssociative(
                'SELECT t.id, t.title, t.status, t.action_type, t.progress, t.created_at, t.deadline_at
                 FROM ticket t
                 ORDER BY t.created_at DESC
                 LIMIT 8'
            );
        } catch (\Throwable) {
            return '';
        }

        $lines = [
            sprintf(
                'Tickets: total=%d, ouverts=%d, en cours=%d, validation=%d, clôturés=%d, en retard=%d',
                $stats['total'] ?? 0,
                $stats['open'] ?? 0,
                $stats['in_progress'] ?? 0,
                $stats['pending'] ?? 0,
                $stats['closed'] ?? 0,
                $stats['overdue'] ?? 0
            ),
        ];

        if ($recentTickets !== []) {
            $lines[] = 'Tickets récents:';
            $lines[] = $this->formatRows($recentTickets, ['id', 'title', 'status', 'action_type', 'progress', 'created_at', 'deadline_at']);
        }

        return implode("\n", $lines);
    }

    private function askOllama(string $prompt): string
    {
        $response = $this->httpClient->request('POST', $this->getOllamaUrl() . '/api/generate', [
            'json' => [
                'model' => $this->getModel(),
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.2,
                    'top_p' => 0.9,
                    'num_ctx' => 2048,
                    'num_predict' => 320,
                ],
                'keep_alive' => '10m',
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        $answer = trim((string) ($data['response'] ?? ''));

        if ($answer === '') {
            throw new \RuntimeException('Réponse vide retournée par Ollama.');
        }

        return $answer;
    }

    private function askOllamaStream(string $prompt, callable $onChunk): string
    {
        $response = $this->httpClient->request('POST', $this->getOllamaUrl() . '/api/generate', [
            'json' => [
                'model' => $this->getModel(),
                'prompt' => $prompt,
                'stream' => true,
                'options' => [
                    'temperature' => 0.2,
                    'top_p' => 0.9,
                    'num_ctx' => 2048,
                    'num_predict' => 320,
                ],
                'keep_alive' => '10m',
            ],
            'timeout' => 90,
        ]);

        $full = '';
        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }

            $buffer .= $chunk->getContent();
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }

                $piece = (string) ($data['response'] ?? '');
                if ($piece !== '') {
                    $full .= $piece;
                    $onChunk($piece);
                }
            }
        }

        if (trim($full) === '') {
            throw new \RuntimeException('Réponse vide retournée par Ollama.');
        }

        return trim($full);
    }

    private function buildPrompt(string $question, string $context, array $history = [], bool $expert = false): string
    {
        $conversation = $this->formatHistory($history);
        $expertRules = $expert
            ? "- Mode expert: donne d'abord une synthese courte, puis liste les faits bruts du contexte (sites, chiffres, dates) en puces.\n"
            : '';

        return <<<PROMPT
Tu es Assistant NetWON, un agent RAG pour supervision réseau Orange Tunisie.
Modèle cible: Qwen2.5:7B.

Règles:
- Réponds en français clair, professionnel et concis.
- Base ta réponse uniquement sur le CONTEXTE fourni.
- Si l'information n'existe pas dans le contexte, dis-le explicitement.
- Pour les questions opérationnelles, donne une synthèse puis des actions prioritaires.
- Ne crée pas de chiffres, dates, sites ou tickets non présents dans le contexte.
- Si la question est une question de suivi, utilise l'historique pour garder le même site.
- Pour FDD/TDD: utilise d'abord capacite_tdd_mbps et capacite_fdd_mbps quand ces champs existent.
- Ne déduis pas le côté TDD/FDD uniquement depuis la classification si les capacités séparées existent.
- ONLY_FDD ou FDD signifie côté FDD seulement quand aucune capacité séparée TDD/FDD n'est fournie.
- Les taux d'utilisation disponibles: taux_utilisation (global), taux_utilisation_tdd, taux_utilisation_fdd.
- Un taux > 90% est critique, > 70% est élevé.
{$expertRules}
HISTORIQUE COURT:
$conversation

CONTEXTE RAG:
$context

QUESTION UTILISATEUR:
$question

RÉPONSE:
PROMPT;
    }

    private function resolveQuestionWithHistory(string $question, array $history): string
    {
        if ($this->extractSiteTokens($question) !== [] || !$this->shouldUsePreviousSiteContext($question)) {
            return $question;
        }

        $lastSite = $this->findLastSiteInHistory($history);
        if ($lastSite === null) {
            return $question;
        }

        return $question . ' ' . $lastSite;
    }

    private function findLastSiteInHistory(array $history): ?string
    {
        for ($index = count($history) - 1; $index >= 0; $index--) {
            $content = is_array($history[$index] ?? null) ? (string) ($history[$index]['content'] ?? '') : '';
            $tokens = $this->extractSiteTokens($content);
            if ($tokens !== []) {
                return $tokens[0];
            }
        }

        return null;
    }

    private function formatHistory(array $history): string
    {
        $lines = [];
        foreach (array_slice($history, -4) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = ($message['role'] ?? '') === 'assistant' ? 'Assistant' : 'Utilisateur';
            $content = trim(mb_substr((string) ($message['content'] ?? ''), 0, 300));
            if ($content !== '') {
                $lines[] = $role . ': ' . $content;
            }
        }

        return $lines !== [] ? implode("\n", $lines) : 'Aucun historique.';
    }

    private function buildFallbackAnswer(string $question, array $documents): string
    {
        $siteAnswer = $this->buildSiteFallbackAnswer($question, $documents);
        if ($siteAnswer !== null) {
            return $siteAnswer;
        }

        $summary = $this->buildCompactFallbackSummary($documents);
        if ($summary !== '') {
            return "Le service d'analyse IA est temporairement indisponible.\n\n"
                . "Voici les données disponibles dans NetWON :\n\n"
                . $summary
                . "\n\nVeuillez réessayer dans quelques instants.";
        }

        return "Le service d'analyse IA est temporairement indisponible pour « {$question} ». "
            . 'Réessayez dans quelques instants ou utilisez /stats, /top ou /tickets.';
    }

    private function isDirectSiteMetricQuestion(string $question): bool
    {
        return (bool) preg_match('/capacit[eé]|capacite|capacity|\b(tdd|fdd)\b|trafic|occurrence|classification|type/i', $question);
    }

    private function buildSiteFallbackAnswer(string $question, array $documents): ?string
    {
        $siteRows = $this->getRowsFromDocument($documents, 'Sites correspondants à la question');
        if ($siteRows === []) {
            return null;
        }

        $site = $siteRows[0];
        $siteName = (string) ($site['site'] ?? 'site demandé');
        $capacity = $this->resolveEffectiveCapacity($site);
        $tddCapacity = $site['capacite_tdd_mbps'] ?? null;
        $fddCapacity = $site['capacite_fdd_mbps'] ?? null;
        $siteTdd = $site['site_tdd'] ?? null;
        $siteFdd = $site['site_fdd'] ?? null;
        $maxTraffic = $site['max_trafic'] ?? null;
        $occurrences = $site['nombre_occurrences'] ?? null;
        $type = $site['type_trans'] ?? null;
        $classification = $site['classification'] ?? null;
        $capacitySide = $this->resolveCapacitySide($classification);
        $date = $site['date_analyse'] ?? $this->statisticsService->getLastAnalyseDate();
        
        // Récupérer les taux d'utilisation si disponibles
        $tauxGlobal = $site['taux_utilisation'] ?? null;
        $tauxTdd = $site['taux_utilisation_tdd'] ?? null;
        $tauxFdd = $site['taux_utilisation_fdd'] ?? null;

        $asksCapacity = (bool) preg_match('/capacit[eé]|capacite|capacity/i', $question);
        $asksSide = (bool) preg_match('/\b(tdd|fdd)\b|c[oô]t[eé]|side/i', $question);
        $asksTaux = (bool) preg_match('/taux|utilisation/i', $question);

        if ($asksTaux && ($tauxGlobal !== null || $tauxTdd !== null || $tauxFdd !== null)) {
            $parts = ["Site $siteName:"];
            if ($tauxGlobal !== null) {
                $parts[] = sprintf("taux global = %s%%", $this->formatNumber($tauxGlobal));
            }
            if ($tauxTdd !== null) {
                $parts[] = sprintf("taux TDD = %s%%", $this->formatNumber($tauxTdd));
            }
            if ($tauxFdd !== null) {
                $parts[] = sprintf("taux FDD = %s%%", $this->formatNumber($tauxFdd));
            }
            return implode(", ", $parts) . ".";
        }

        if ($asksSide && ($this->hasNumericCapacity($tddCapacity) || $this->hasNumericCapacity($fddCapacity))) {
            return $this->buildCapacitySideAnswer($capacity, $tddCapacity, $fddCapacity);
        }

        if ($asksCapacity && $capacity !== null && $capacity !== '') {
            return sprintf(
                "%s Mbps",
                $this->formatNumber($capacity)
            );
        }

        return sprintf(
            "Site %s: type=%s, classification=%s, capacité=%s Mbps, côté capacité=%s, trafic max=%s Mbps, occurrences=%s, dernière analyse=%s.",
            $siteName,
            $type ?: '-',
            $classification ?: '-',
            $capacity !== null ? $this->formatNumber($capacity) : '-',
            $capacitySide,
            $maxTraffic !== null ? $this->formatNumber($maxTraffic) : '-',
            $occurrences ?? '-',
            $this->stringifyValue($date)
        );
    }

    private function buildCompactFallbackSummary(array $documents): string
    {
        $parts = [];
        foreach ($documents as $document) {
            $content = trim((string) ($document['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $parts[] = sprintf(
                "%s:\n%s",
                $document['title'] ?? 'Source RAG',
                mb_substr($content, 0, 900)
            );
        }

        return implode("\n\n", array_slice($parts, 0, 3));
    }

    private function resolveEffectiveCapacity(array $site): mixed
    {
        $classification = strtoupper(trim((string) ($site['classification'] ?? '')));
        $global = is_numeric($site['capacite_mbps'] ?? null) ? (float) $site['capacite_mbps'] : null;
        $tdd = $this->hasNumericCapacity($site['capacite_tdd_mbps'] ?? null) ? (float) $site['capacite_tdd_mbps'] : null;
        $fdd = $this->hasNumericCapacity($site['capacite_fdd_mbps'] ?? null) ? (float) $site['capacite_fdd_mbps'] : null;

        if (in_array($classification, ['TF', 'ONLY_FDD', 'FDD', '-'], true)) {
            if ($fdd !== null) {
                return $fdd;
            }

            return $global;
        }

        if ($tdd !== null && $fdd !== null) {
            return $tdd + $fdd;
        }

        if ($tdd !== null) {
            return $tdd;
        }

        if ($fdd !== null) {
            return $fdd;
        }

        return $global;
    }

    private function resolveCapacitySide(?string $classification): string
    {
        $classification = strtoupper(trim((string) $classification));

        return match ($classification) {
            'ONLY_FDD', 'FDD', '-' => 'FDD',
            'TF' => 'TF (site TF; séparation TDD/FDD non détaillée dans cette ligne)',
            'COTRANS', 'NO_COTRANS' => 'TDD/FDD selon les sites associés; séparation exacte non détaillée dans cette ligne',
            'NON_TDD' => 'FDD / non TDD',
            default => 'non précisé',
        };
    }

    private function buildCapacitySideAnswer(mixed $globalCapacity, mixed $tddCapacity, mixed $fddCapacity): string
    {
        $global = is_numeric($globalCapacity) ? (float) $globalCapacity : null;
        $tdd = $this->hasNumericCapacity($tddCapacity) ? (float) $tddCapacity : null;
        $fdd = $this->hasNumericCapacity($fddCapacity) ? (float) $fddCapacity : null;

        if ($global !== null && $tdd !== null && abs($global - $tdd) < 0.001) {
            return sprintf("C'est une capacité TDD (%s Mbps).", $this->formatNumber($tdd));
        }

        if ($global !== null && $fdd !== null && abs($global - $fdd) < 0.001) {
            return sprintf("C'est une capacité FDD (%s Mbps).", $this->formatNumber($fdd));
        }

        if ($tdd !== null && $fdd !== null) {
            return sprintf("TDD: %s Mbps, FDD: %s Mbps.", $this->formatNumber($tdd), $this->formatNumber($fdd));
        }

        if ($tdd !== null) {
            return sprintf("C'est une capacité TDD (%s Mbps).", $this->formatNumber($tdd));
        }

        if ($fdd !== null) {
            return sprintf("C'est une capacité FDD (%s Mbps).", $this->formatNumber($fdd));
        }

        return 'Le côté TDD/FDD n’est pas renseigné.';
    }

    private function getRowsFromDocument(array $documents, string $title): array
    {
        foreach ($documents as $document) {
            if (($document['title'] ?? null) === $title && isset($document['rows']) && is_array($document['rows'])) {
                return $document['rows'];
            }
        }

        foreach ($documents as $document) {
            if (isset($document['rows']) && is_array($document['rows'])) {
                return $document['rows'];
            }
        }

        return [];
    }

    private function formatContext(array $documents): string
    {
        $chunks = [];
        foreach ($documents as $document) {
            $chunks[] = sprintf("[Source: %s]\n%s", $document['title'], $document['content']);
        }

        return implode("\n\n---\n\n", $chunks);
    }

    private function formatRows(array $rows, array $columns): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($columns as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
                    $parts[] = $column . '=' . $this->stringifyValue($row[$column]);
                }
            }
            if ($parts !== []) {
                $lines[] = '- ' . implode(', ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        return trim((string) $value);
    }

    private function formatNumber(mixed $value): string
    {
        if (!is_numeric($value)) {
            return trim((string) $value);
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function hasNumericCapacity(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }

    private function extractSiteTokens(string $question): array
    {
        $normalized = mb_strtolower($question);
        preg_match_all('/\b[a-z]{3}_\d{3,5}(?:_[a-z0-9]+)?\b/i', $normalized, $matches);
        $tokens = $matches[0] ?? [];

        return array_values(array_unique($tokens));
    }

    private function isReportRequest(string $question): bool
    {
        return (bool) preg_match(
            '/\b(g[eé]n[eè]re|g[eé]n[eé]rer|cr[eé]er|produire|affiche|montre)\b.*\brapport\b|\brapport\b.*\b(gouvernorat|sites?|[eé]tat|r[eé]seau)\b/iu',
            $question
        );
    }

    private function buildGovernorateReportResponse(): array
    {
        $data = $this->buildGovernorateReportData();
        $text = $this->formatReportTextSummary($data);
        $response = $this->buildAgentResponse($text, ['Rapport réseau par gouvernorat'], 'database');
        $response['report'] = $data;

        return $response;
    }

    private function buildGovernorateReportData(): array
    {
        $lastDate = $this->statisticsService->getLastAnalyseDate();

        if (!$lastDate) {
            return [
                'title'             => 'Rapport Réseau Complet',
                'date'              => date('d/m/Y H:i'),
                'periode'           => 'Aucune analyse disponible',
                'total_sites'       => 0,
                'summary'           => ['ok' => ['count' => 0, 'pct' => 0.0], 'congestion' => ['count' => 0, 'pct' => 0.0], 'bridage' => ['count' => 0, 'pct' => 0.0], 'a_verifier' => ['count' => 0, 'pct' => 0.0]],
                'gouvernorats'      => [],
                'status'            => ['critique' => 0, 'sous_observation' => 0, 'securise' => 0, 'total' => 0],
                'types'             => ['FO' => 0, 'FH' => 0, 'Backhaul Sharing' => 0, 'Autre' => 0],
                'tickets'           => ['total' => 0, 'open' => 0, 'in_progress' => 0, 'pending' => 0, 'closed' => 0, 'overdue' => 0, 'sites_corriges' => 0, 'by_action' => []],
                'executive_summary' => 'Aucune donnée disponible.',
            ];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT site,
                        COALESCE(max_trafic, 0)             AS max_trafic,
                        COALESCE(capacite_mbps, 0)          AS capacite_mbps,
                        COALESCE(nombre_occurrences, 0)     AS nombre_occurrences,
                        COALESCE(NULLIF(duree_jours, 0), 7) AS duree_jours
                 FROM analyse_resultat
                 WHERE date_analyse = :date",
                ['date' => $lastDate]
            );
        } catch (\Throwable) {
            return ['title' => 'Rapport Réseau Complet', 'date' => date('d/m/Y H:i'), 'periode' => 'Erreur lecture BD', 'total_sites' => 0, 'summary' => [], 'gouvernorats' => [], 'status' => [], 'types' => [], 'tickets' => [], 'executive_summary' => ''];
        }

        $gouvernoratMap = [
            'TUN' => 'Tunis',    'ARI' => 'Ariana',   'BEN' => 'Ben Arous',  'MAN' => 'Manouba',
            'NAB' => 'Nabeul',   'ZAG' => 'Zaghouan', 'BIZ' => 'Bizerte',    'BEJ' => 'Béja',
            'JEN' => 'Jendouba', 'KEF' => 'Le Kef',   'SIL' => 'Siliana',    'SOU' => 'Sousse',
            'MON' => 'Monastir', 'MAH' => 'Mahdia',   'SFA' => 'Sfax',       'KAI' => 'Kairouan',
            'KAS' => 'Kasserine','SIB' => 'Sidi Bouzid','GAB' => 'Gabès',    'MED' => 'Médenine',
            'TAT' => 'Tataouine','GAF' => 'Gafsa',    'TOZ' => 'Tozeur',     'KEB' => 'Kébili',
        ];

        $stats = [];
        $total = ['total' => 0, 'ok' => 0, 'congestion' => 0, 'bridage' => 0, 'a_verifier' => 0];

        foreach ($rows as $row) {
            $prefix      = strtoupper(substr(trim((string) ($row['site'] ?? '')), 0, 3));
            $gouvernorat = $gouvernoratMap[$prefix] ?? 'Autres';

            if (!isset($stats[$gouvernorat])) {
                $stats[$gouvernorat] = ['name' => $gouvernorat, 'total' => 0, 'ok' => 0, 'congestion' => 0, 'bridage' => 0, 'a_verifier' => 0];
            }

            $capacite = (float) $row['capacite_mbps'];
            $trafic   = (float) $row['max_trafic'];
            $occ      = (int)   $row['nombre_occurrences'];
            $duree    = max(1, (int) $row['duree_jours']);
            $seuil    = (int) ceil(50.0 * $duree / 7.0);
            $taux     = $capacite > 0 ? ($trafic / $capacite) * 100 : 0.0;

            if ($capacite > 0 && $taux > 105.0) {
                $etat = 'a_verifier';
            } elseif ($taux >= 90.0 && $occ >= $seuil) {
                $etat = 'congestion';
            } elseif ($occ >= $seuil) {
                $etat = 'bridage';
            } else {
                $etat = 'ok';
            }

            $stats[$gouvernorat]['total']++;
            $stats[$gouvernorat][$etat]++;
            $total['total']++;
            $total[$etat]++;
        }

        uasort($stats, static fn ($a, $b) => $b['total'] <=> $a['total']);

        foreach ($stats as &$g) {
            $t = max(1, $g['total']);
            $g['ok_pct']         = round($g['ok']         / $t * 100, 1);
            $g['congestion_pct'] = round($g['congestion'] / $t * 100, 1);
            $g['bridage_pct']    = round($g['bridage']    / $t * 100, 1);
            $g['a_verifier_pct'] = round($g['a_verifier'] / $t * 100, 1);
        }
        unset($g);

        $t = max(1, $total['total']);

        try {
            $periodeLabel = 'Dernière analyse : ' . (new \DateTimeImmutable($lastDate))->format('d/m/Y H:i');
        } catch (\Throwable) {
            $periodeLabel = 'Dernière analyse : ' . $lastDate;
        }

        $gouvernoratsList = array_values($stats);
        $statusData       = $this->buildStatusData($lastDate);
        $typeData         = $this->buildTypeData($lastDate);
        $ticketData       = $this->buildTicketData();

        return [
            'title'             => 'Rapport Réseau Complet',
            'date'              => date('d/m/Y H:i'),
            'periode'           => $periodeLabel,
            'total_sites'       => $total['total'],
            'summary'           => [
                'ok'         => ['count' => $total['ok'],         'pct' => round($total['ok']         / $t * 100, 1)],
                'congestion' => ['count' => $total['congestion'], 'pct' => round($total['congestion'] / $t * 100, 1)],
                'bridage'    => ['count' => $total['bridage'],    'pct' => round($total['bridage']    / $t * 100, 1)],
                'a_verifier' => ['count' => $total['a_verifier'], 'pct' => round($total['a_verifier'] / $t * 100, 1)],
            ],
            'gouvernorats'      => $gouvernoratsList,
            'status'            => $statusData,
            'types'             => $typeData,
            'tickets'           => $ticketData,
            'executive_summary' => $this->buildExecutiveSummary($total, $gouvernoratsList, $statusData, $ticketData),
        ];
    }

    private function buildStatusData(string $lastDate): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT
                    COUNT(CASE WHEN nombre_occurrences >= CEIL(50.0 * COALESCE(NULLIF(duree_jours,0),7) / 7.0) THEN 1 END) AS critique,
                    COUNT(CASE WHEN nombre_occurrences >= CEIL(20.0 * COALESCE(NULLIF(duree_jours,0),7) / 7.0)
                                AND nombre_occurrences  < CEIL(50.0 * COALESCE(NULLIF(duree_jours,0),7) / 7.0) THEN 1 END) AS sous_observation,
                    COUNT(CASE WHEN nombre_occurrences  < CEIL(20.0 * COALESCE(NULLIF(duree_jours,0),7) / 7.0) THEN 1 END) AS securise,
                    COUNT(*) AS total
                 FROM analyse_resultat
                 WHERE date_analyse = :date",
                ['date' => $lastDate]
            );
        } catch (\Throwable) {
            return ['critique' => 0, 'sous_observation' => 0, 'securise' => 0, 'total' => 0];
        }

        return [
            'critique'         => (int) ($row['critique']         ?? 0),
            'sous_observation' => (int) ($row['sous_observation'] ?? 0),
            'securise'         => (int) ($row['securise']         ?? 0),
            'total'            => (int) ($row['total']            ?? 0),
        ];
    }

    private function buildTypeData(string $lastDate): array
    {
        $types = ['FO' => 0, 'FH' => 0, 'Backhaul Sharing' => 0, 'Autre' => 0];

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT UPPER(COALESCE(type_trans, '')) AS type_raw, COUNT(*) AS nb
                 FROM analyse_resultat
                 WHERE date_analyse = :date
                 GROUP BY type_raw",
                ['date' => $lastDate]
            );
        } catch (\Throwable) {
            return $types;
        }

        foreach ($rows as $row) {
            $t  = strtoupper((string) ($row['type_raw'] ?? ''));
            $nb = (int) $row['nb'];

            if (str_starts_with($t, 'FO') || str_starts_with($t, 'FIBRE')) {
                $types['FO'] += $nb;
            } elseif (str_starts_with($t, 'FH') || str_starts_with($t, 'MICRO')) {
                $types['FH'] += $nb;
            } elseif (str_contains($t, 'BACKBONE') || str_contains($t, 'IP') || str_contains($t, 'CORE')) {
                $types['Backhaul Sharing'] += $nb;
            } else {
                $types['Autre'] += $nb;
            }
        }

        return $types;
    }

    private function buildTicketData(): array
    {
        $stats = $this->ticketRepository->getStatistics();

        try {
            $actionRows = $this->connection->fetchAllAssociative(
                'SELECT action_type, COUNT(*) AS nb FROM ticket GROUP BY action_type'
            );
        } catch (\Throwable) {
            $actionRows = [];
        }

        $byAction = [];
        foreach ($actionRows as $row) {
            $byAction[(string) ($row['action_type'] ?? 'inconnu')] = (int) $row['nb'];
        }

        try {
            $corrigesRow   = $this->connection->fetchAssociative(
                "SELECT COUNT(DISTINCT site_id) AS nb FROM ticket WHERE status = 'closed' AND site_id IS NOT NULL"
            );
            $sitesCorriges = (int) ($corrigesRow['nb'] ?? 0);
        } catch (\Throwable) {
            $sitesCorriges = 0;
        }

        return [
            'total'          => (int) ($stats['total']       ?? 0),
            'open'           => (int) ($stats['open']        ?? 0),
            'in_progress'    => (int) ($stats['in_progress'] ?? 0),
            'pending'        => (int) ($stats['pending']     ?? 0),
            'closed'         => (int) ($stats['closed']      ?? 0),
            'overdue'        => (int) ($stats['overdue']     ?? 0),
            'sites_corriges' => $sitesCorriges,
            'by_action'      => $byAction,
        ];
    }

    private function buildExecutiveSummary(array $total, array $gouvernorats, array $status, array $tickets): string
    {
        $t       = max(1, $total['total']);
        $okPct   = round($total['ok']                  / $t * 100, 1);
        $congPct = round($total['congestion']          / $t * 100, 1);
        $bridPct = round($total['bridage']             / $t * 100, 1);
        $avPct   = round(($total['a_verifier'] ?? 0)   / $t * 100, 1);

        $level = match (true) {
            $congPct > 5 => 'préoccupante',
            $congPct > 2 => 'modérée',
            default      => 'satisfaisante',
        };

        $text = sprintf(
            'Sur %d sites analysés, la situation réseau est globalement %s : %s%% OK, %s%% en congestion, %s%% en bridage, %s%% à vérifier.',
            $total['total'], $level, $okPct, $congPct, $bridPct, $avPct
        );

        $critiques = array_filter($gouvernorats, static fn ($g) => $g['congestion_pct'] > 5.0);
        usort($critiques, static fn ($a, $b) => $b['congestion_pct'] <=> $a['congestion_pct']);
        if (!empty($critiques)) {
            $items = implode(', ', array_map(
                static fn ($g) => sprintf('%s (%s%%)', $g['name'], $g['congestion_pct']),
                array_slice(array_values($critiques), 0, 3)
            ));
            $text .= sprintf(' Zones critiques : %s.', $items);
        }

        if (($status['critique'] ?? 0) > 0) {
            $st = max(1, $status['total'] ?? 1);
            $text .= sprintf(' %d sites en statut CRITIQUE (%s%%).', $status['critique'], round($status['critique'] / $st * 100, 1));
        }

        if (($tickets['total'] ?? 0) > 0) {
            $text .= sprintf(
                ' Tickets : %d actifs, %d clôturés, %d en retard — %d sites corrigés.',
                ($tickets['open'] ?? 0) + ($tickets['in_progress'] ?? 0) + ($tickets['pending'] ?? 0),
                $tickets['closed']      ?? 0,
                $tickets['overdue']     ?? 0,
                $tickets['sites_corriges'] ?? 0
            );
        }

        $recommendation = match (true) {
            $congPct > 5 => "Action immédiate requise : déclencher une analyse capacitaire d'urgence sur les zones critiques.",
            $congPct > 2 => 'Surveillance renforcée recommandée : planifier des audits de capacité avant saturation.',
            $congPct > 0 => 'Situation sous contrôle. Poursuivre la surveillance standard et anticiper les pics de trafic.',
            default      => 'Réseau en bonne santé. Aucune action corrective immédiate requise.',
        };

        return $text . ' ' . $recommendation;
    }

    private function formatReportTextSummary(array $data): string
    {
        if ($data['total_sites'] === 0) {
            return "Aucune donnée d'analyse disponible. Importez d'abord un fichier de trafic.";
        }

        return sprintf(
            "Rapport généré le %s — %d sites analysés. Le tableau de bord interactif est affiché ci-dessous ↓",
            $data['date'],
            $data['total_sites']
        );
    }

    private function getOllamaUrl(): string
    {
        $url = $_ENV['OLLAMA_URL'] ?? $_SERVER['OLLAMA_URL'] ?? getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434';

        return rtrim((string) $url, '/');
    }

    private function getModel(): string
    {
        return (string) ($_ENV['OLLAMA_MODEL'] ?? $_SERVER['OLLAMA_MODEL'] ?? getenv('OLLAMA_MODEL') ?: 'qwen2.5:7b');
    }
}