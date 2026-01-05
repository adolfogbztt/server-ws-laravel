<?php

namespace App\Services;

use App\Enums\NaiadeTaskStatus;
use App\Models\NaiadeTask;
use Illuminate\Support\Facades\Log;
use Throwable;

class NaiadeTaskStatusService
{
    private NaiadeTask $naiadeTask;
    private string $naiadeFileServerPath = '\\\\192.168.1.99\\e\\naiade_files';
    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];

    public function checkStatus(string $ticket): ?NaiadeTask
    {
        $this->naiadeTask = NaiadeTask::firstWhere('ticket', $ticket);

        if (!$this->naiadeTask) {
            return null;
        }

        $status = $this->readJsonStatusFile();

        if (!$status) {
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, 'Status file not found or unreadable');
            return $this->naiadeTask;
        }

        if ($status === 'IN_PROGRESS') {
            return $this->naiadeTask;
        }

        # Si el status es COMPLETED
        $this->copyProcessedImagesToResultFolder();
        $this->updateNaiadeTaskStatus(NaiadeTaskStatus::COMPLETED, 'Processing completed successfully');

        return $this->naiadeTask;
    }

    private function readJsonStatusFile(): ?string
    {
        $serviceDir = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/');

        $statusFilePath = $serviceDir . '\\naiade_status\\' . $this->naiadeTask->ticket . '.json';

        if (!is_file($statusFilePath)) {
            return null;
        }

        $jsonContent = @file_get_contents($statusFilePath);

        if ($jsonContent === false) {
            return null;
        }

        $data = json_decode($jsonContent, true);

        return $data['status'] ?? null;
    }

    private function getTicketDirectoryPath(): string
    {
        return rtrim($this->naiadeFileServerPath, '\\/') . '\\' . $this->naiadeTask->ticket;
    }

    private function globImages(string $directory): array
    {
        $directory = rtrim($directory, '\\/');

        if (defined('GLOB_BRACE')) {
            $pattern = $directory . '\\*.{' . implode(',', $this->imageExtensions) . '}';
            $files = glob($pattern, GLOB_BRACE) ?: [];
        } else {
            $files = [];
            foreach ($this->imageExtensions as $ext) {
                $files = array_merge($files, glob($directory . '\\*.' . $ext) ?: []);
            }
        }

        return array_values(array_unique($files));
    }

    private function copyProcessedImagesToResultFolder(): void
    {
        $serviceOutputDirectory = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/') .
            '\\naiade_output\\' . $this->naiadeTask->ticket;

        $ticketDirectory = $this->getTicketDirectoryPath();
        $resultDirectory = $ticketDirectory . '\\result';

        if (!is_dir($serviceOutputDirectory)) {
            Log::warning("Output dir does not exist: {$serviceOutputDirectory}");
            return;
        }

        if (!is_dir($resultDirectory)) {
            @mkdir($resultDirectory, 0777, true);
        }

        $imageFiles = $this->globImages($serviceOutputDirectory);

        foreach ($imageFiles as $file) {
            $fileName = basename($file);
            $dest = $resultDirectory . '\\' . $fileName;

            if (@copy($file, $dest)) {
                Log::info("Copied processed image to result folder", ['from' => $file, 'to' => $dest]);
            } else {
                Log::error("Failed to copy processed image", ['from' => $file, 'to' => $dest]);
            }
        }
    }

    private function updateNaiadeTaskStatus(NaiadeTaskStatus $status, ?string $message = null): void
    {
        try {
            $this->naiadeTask->status = $status;
            $this->naiadeTask->service_status = $status;
            if (!is_null($message)) {
                $this->naiadeTask->message = mb_substr($message, 0, 1000);
            }
            $this->naiadeTask->updateQuietly();
        } catch (Throwable $e) {
            Log::error("Failed to update NaiadeTask status", ['exception' => $e]);
        }
    }
}
