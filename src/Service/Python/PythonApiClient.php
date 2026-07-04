<?php

namespace App\Service\Python;

use App\Service\FastApiPortDetector;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PythonApiClient
{
    private HttpClientInterface $http;
    private FastApiPortDetector $detector;
    private ?string $baseUrl = null;

    public function __construct(HttpClientInterface $http, FastApiPortDetector $detector)
    {
        $this->http = $http;
        $this->detector = $detector;
        $this->baseUrl = $detector->getApiUrl();
    }

    public function isAvailable(): bool
    {
        return $this->baseUrl !== null;
    }

    private function buildMultipartBody(array $files): array
    {
        $boundary = uniqid('boundary_', true);
        $body = '';

        foreach ($files as $fieldName => $info) {
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"$fieldName\"; filename=\"{$info['filename']}\"\r\n";
            $body .= "Content-Type: application/octet-stream\r\n\r\n";
            $body .= file_get_contents($info['path']) . "\r\n";
        }

        $body .= "--$boundary--\r\n";
        return ['body' => $body, 'boundary' => $boundary];
    }

    public function processFiles(UploadedFile $traffic, ?UploadedFile $port, ?UploadedFile $liaison, ?UploadedFile $gps = null): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'message' => 'API Python hors ligne.'];
        }

        $files = [
            'trafic' => ['path' => $traffic->getPathname(), 'filename' => $traffic->getClientOriginalName()],
        ];
        
        if ($port) {
            $files['port'] = ['path' => $port->getPathname(), 'filename' => $port->getClientOriginalName()];
        }
        if ($liaison) {
            $files['type_liaison'] = ['path' => $liaison->getPathname(), 'filename' => $liaison->getClientOriginalName()];
        }
        if ($gps) {
            $files['gps'] = ['path' => $gps->getPathname(), 'filename' => $gps->getClientOriginalName()];
        }

        $multipart = $this->buildMultipartBody($files);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary'],
        ];

        try {
            $response = $this->http->request('POST', $this->baseUrl . '/traiter', [
                'body' => $multipart['body'],
                'headers' => $headers,
                'timeout' => 1800,
                'max_duration' => 0,
            ]);
            $data = $response->toArray();
            if (($data['status'] ?? null) === 'success') {
                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Traitement terminé avec succès.',
                    'data' => $data['data'] ?? [],
                ];
            }
            return [
                'success' => false,
                'message' => $data['message'] ?? 'Erreur inconnue pendant le traitement Python.',
                'detail' => $data['detail'] ?? null,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function importCapacite(UploadedFile $file, string $type): array
    {
        if (!$this->isAvailable()) return ['status' => 'error', 'message' => 'API indisponible'];

        $endpoint = match ($type) {
            'FO' => '/capacite/fo',
            'FH' => '/capacite/fh',
            'BACKBONE' => '/capacite/backbone',
            default => '/capacite/fo',
        };

        $multipart = $this->buildMultipartBody([
            'fichier' => ['path' => $file->getPathname(), 'filename' => $file->getClientOriginalName()],
        ]);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary'],
        ];

        try {
            $response = $this->http->request('POST', $this->baseUrl . $endpoint, [
                'body' => $multipart['body'],
                'headers' => $headers,
                'timeout' => 120,
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function importDropCong(UploadedFile $drop1, ?UploadedFile $drop2 = null): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'message' => 'API Python hors ligne.'];
        }

        $files = [
            'drop1' => ['path' => $drop1->getPathname(), 'filename' => $drop1->getClientOriginalName()],
        ];
        if ($drop2) {
            $files['drop2'] = ['path' => $drop2->getPathname(), 'filename' => $drop2->getClientOriginalName()];
        }

        $multipart = $this->buildMultipartBody($files);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary'],
        ];

        try {
            $response = $this->http->request('POST', $this->baseUrl . '/dropcong', [
                'body' => $multipart['body'],
                'headers' => $headers,
                'timeout' => 300,
                'max_duration' => 0,
            ]);
            $data = $response->toArray();
            if (($data['status'] ?? null) === 'success') {
                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'DropCong importé avec succès.',
                    'data' => $data['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Erreur inconnue pendant l’import DropCong.',
                'detail' => $data['detail'] ?? null,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
