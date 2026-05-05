<?php

namespace App\Service\Import;

use App\Service\Python\PythonApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImportDispatcher
{
    private PythonApiClient $pythonClient;
    private EntityManagerInterface $em;
    private string $uploadDir;

    public function __construct(PythonApiClient $pythonClient, EntityManagerInterface $em, string $projectDir)
    {
        $this->pythonClient = $pythonClient;
        $this->em = $em;
        $this->uploadDir = $projectDir . '/var/uploads/';
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    public function handleTransmission(UploadedFile $traffic, ?UploadedFile $port, ?UploadedFile $liaison, ?UploadedFile $gps = null): array
    {
        $timestamp = date('Ymd_His');
        
        // Envoi à l'API Python (tous les fichiers, port et liaison optionnels)
        $result = $this->pythonClient->processFiles($traffic, $port, $liaison, $gps);
        
        // Archivage des fichiers
        $this->moveFile($traffic, $timestamp . '_traffic_' . $traffic->getClientOriginalName());
        if ($port) {
            $this->moveFile($port, $timestamp . '_port_' . $port->getClientOriginalName());
        }
        if ($liaison) {
            $this->moveFile($liaison, $timestamp . '_liaison_' . $liaison->getClientOriginalName());
        }
        if ($gps) {
            $this->moveFile($gps, $timestamp . '_gps_' . $gps->getClientOriginalName());
        }
        
        return $result;
    }

    public function handleDropCong(UploadedFile $drop1, UploadedFile $drop2): array
    {
        $timestamp = date('Ymd_His');
        $this->moveFile($drop1, $timestamp . '_drop1_' . $drop1->getClientOriginalName());
        $this->moveFile($drop2, $timestamp . '_drop2_' . $drop2->getClientOriginalName());
        return ['success' => true, 'message' => 'Fichiers DropCong sauvegardés.'];
    }

    public function handleSynch(array $files): array
    {
        $timestamp = date('Ymd_His');
        $count = 0;
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->getError() === UPLOAD_ERR_OK) {
                $this->moveFile($file, $timestamp . '_synch_' . $file->getClientOriginalName());
                $count++;
            }
        }
        return ['success' => true, 'message' => "$count fichier(s) synchronisé(s)."];
    }

    private function moveFile(UploadedFile $file, string $newName): void
    {
        $file->move($this->uploadDir, $newName);
    }
}