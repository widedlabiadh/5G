// public/js/navbar.js
// Navbar horizontale NetWON – recherche, notifications, profil, API status

(function() {
    // Éléments DOM
    const searchInput    = document.getElementById('navbarSearchInput');
    const searchResults  = document.getElementById('navbarSearchResults');
    const notifBtn       = document.getElementById('notifBtn');
    const notifDropdown  = document.getElementById('notifDropdown');
    const profileBtn     = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    const profileCaret   = document.getElementById('profileCaret');
    const overlay        = document.getElementById('navbarOverlay');
    const apiDot         = document.getElementById('apiDot');
    const apiLabel       = document.getElementById('apiLabel');

    // Données des sites (transmises par le contrôleur)
    // Si la variable n'existe pas (ex: page autre que dashboard), on utilise un tableau vide
    const SITES = (typeof window.sitesData !== 'undefined' && Array.isArray(window.sitesData))
                  ? window.sitesData
                  : [];

    // ── Helper : échappement HTML (sécurité) ────────────────────
    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Fermeture de tous les overlays / dropdowns ──────────────
    function closeAll() {
        if (notifDropdown)    notifDropdown.classList.remove('open');
        if (profileDropdown)  profileDropdown.classList.remove('open');
        if (profileCaret)     profileCaret.classList.remove('open');
        if (searchResults)    searchResults.classList.remove('open');
        if (overlay)          overlay.classList.remove('open');
    }

    // ── Raccourci Ctrl+K / Cmd+K pour focus sur la recherche ────
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
        if (e.key === 'Escape') {
            closeAll();
            if (searchInput) searchInput.blur();
        }
    });

    // ── Fonction pour ouvrir la page de courbe de trafic ─────────
    function openTraficCourbe(siteName) {
        // Si le contrôleur principal a défini une fonction globale, on l'utilise
        if (typeof window.ouvrirCourbe === 'function') {
            window.ouvrirCourbe(siteName);
            return;
        }
        // Fallback : redirection vers la page des courbes avec le nom du site en paramètre
        const url = `/courbe/trafic?site=${encodeURIComponent(siteName)}`;
        window.location.href = url;
    }

    // ── RECHERCHE DE SITES ───────────────────────────────────────
    if (searchInput && searchResults) {
        searchInput.addEventListener('input', function() {
            const term = this.value.trim().toLowerCase();
            if (term.length < 2) {
                searchResults.classList.remove('open');
                return;
            }

            const filtered = SITES.filter(site =>
                (site.nom || site.code || '').toLowerCase().includes(term) ||
                (site.typeTrans || '').toLowerCase().includes(term)
            ).slice(0, 8);

            if (!filtered.length) {
                searchResults.innerHTML = `<div class="search-empty">🔍 Aucun site trouvé pour "<strong>${escHtml(term)}</strong>"</div>`;
            } else {
                searchResults.innerHTML = filtered.map(site => {
                    const statut = site.statut || 'non_defini';
                    let dotClass = 'sri-dot-gray';
                    let badgeClass = 'sri-badge-ok';
                    let badgeText = 'OK';
                    if (statut === 'critique') {
                        dotClass = 'sri-dot-red';
                        badgeClass = 'sri-badge-crit';
                        badgeText = 'Critique';
                    } else if (statut === 'sous_observation') {
                        dotClass = 'sri-dot-orange';
                        badgeClass = 'sri-badge-obs';
                        badgeText = 'Obs.';
                    } else if (statut === 'securise') {
                        dotClass = 'sri-dot-green';
                        badgeClass = 'sri-badge-ok';
                        badgeText = 'Sécurisé';
                    }

                    return `
                        <div class="search-result-item" data-site="${escHtml(site.nom || site.code || '')}">
                            <span class="sri-dot ${dotClass}"></span>
                            <span class="sri-name">${escHtml(site.nom || site.code || '—')}</span>
                            <span class="sri-meta">${site.nbOccurrences || 0} congest. · ${site.typeTrans || '—'}</span>
                            <span class="sri-badge ${badgeClass}">${badgeText}</span>
                        </div>
                    `;
                }).join('');
            }
            searchResults.classList.add('open');

            // Ajout des événements de clic sur chaque résultat
            document.querySelectorAll('.search-result-item').forEach(el => {
                el.removeEventListener('click', resultClickHandler);
                el.addEventListener('click', resultClickHandler);
            });
        });

        function resultClickHandler(e) {
            // Évite de remonter jusqu'au parent si on clique sur un enfant
            const item = e.currentTarget;
            const siteName = item.dataset.site;
            if (siteName) {
                openTraficCourbe(siteName);
            }
            closeAll();
            if (searchInput) searchInput.value = '';
        }

        // Au focus, réaffiche les résultats si déjà une recherche en cours
        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2 && searchResults.innerHTML.trim() !== '') {
                searchResults.classList.add('open');
            }
        });
    }

    // ── TOGGLE NOTIFICATIONS ─────────────────────────────────────
    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = notifDropdown.classList.contains('open');
            closeAll();
            if (!isOpen) {
                notifDropdown.classList.add('open');
                if (overlay) overlay.classList.add('open');
            }
        });
    }

    // ── TOGGLE PROFIL ────────────────────────────────────────────
    if (profileBtn && profileDropdown && profileCaret) {
        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = profileDropdown.classList.contains('open');
            closeAll();
            if (!isOpen) {
                profileDropdown.classList.add('open');
                profileCaret.classList.add('open');
                if (overlay) overlay.classList.add('open');
            }
        });
    }

    // ── OVERLAY (ferme tout au clic externe) ─────────────────────
    if (overlay) {
        overlay.addEventListener('click', closeAll);
    }

    // ── STATUT API PYTHON ────────────────────────────────────────
    function checkApiStatus() {
        if (!apiDot || !apiLabel) return;
        fetch('/api/python/health', {
            signal: AbortSignal.timeout(4000),
            cache: 'no-store'
        })
        .then(response => response.json())
        .then(data => {
            const ok = (data.status === 'ok');
            apiDot.className = 'api-dot ' + (ok ? 'ok' : 'error');
            apiLabel.textContent = ok ? 'API OK' : 'API HS';
            apiLabel.style.color = ok ? '#10b981' : '#ef4444';
        })
        .catch(() => {
            apiDot.className = 'api-dot error';
            apiLabel.textContent = 'API HS';
            apiLabel.style.color = '#ef4444';
        });
    }

    // Vérification immédiate puis toutes les 30 secondes
    if (apiDot) checkApiStatus();
    setInterval(checkApiStatus, 30000);
})();