<?php

namespace App\Jobs;

use App\Enums\NaiadeTaskStatus;
use App\Models\NaiadeTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessNaiadeJob2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private NaiadeTask $naiadeTask;
    private string $pythonPath = 'C:\\Users\\user\\anaconda3\\Scripts\\conda';
    private string $naiadeFileServerPath = '\\\\192.168.1.99\\e\\naiade_files';

    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];

    /**
     * @param NaiadeTask $naiadeTask
     */
    public function __construct(NaiadeTask $naiadeTask)
    {
        $this->naiadeTask = $naiadeTask;
    }

    public function handle(): void
    {
        try {
            if (!$this->validations()) return;

            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::IN_PROGRESS);

            $this->moveImagesToServiceTmpDir();

            $this->createJsonStatusFile();

            $this->processImagesWithService();
        } catch (Throwable $e) {
            $msg = "Unhandled exception: {$e->getMessage()}";
            Log::error($msg, ['exception' => $e]);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            $this->markOriginalsAsFailed();
        }
    }

    private function validations(): bool
    {
        $ticketDir = $this->getTicketDirectoryPath();

        if (!is_dir($ticketDir)) {
            $msg = "Ticket directory does not exist: {$ticketDir}";
            Log::warning($msg);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            return false;
        }

        $images = $this->globImages($ticketDir);

        if (empty($images)) {
            $msg = "No images found in directory: {$ticketDir}";
            Log::info($msg);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            return false;
        }

        return true;
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

    private function updateNaiadeTaskStatus(NaiadeTaskStatus $status, ?string $message = null): void
    {
        try {
            $this->naiadeTask->status = $status;
            if (!is_null($message)) {
                $this->naiadeTask->message = mb_substr($message, 0, 1000);
            }
            $this->naiadeTask->updateQuietly();
        } catch (Throwable $e) {
            Log::error("Failed to update NaiadeTask status", ['exception' => $e]);
        }
    }

    private function moveImagesToServiceTmpDir(): void
    {
        $ticketDirectory = $this->getTicketDirectoryPath();

        $destinationDirectory = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/') .
            '\\naiade_tmp\\' . $this->naiadeTask->ticket;

        if (!is_dir($destinationDirectory)) {
            @mkdir($destinationDirectory, 0777, true);
        }

        $imageFiles = $this->globImages($ticketDirectory);

        foreach ($imageFiles as $file) {
            $fileName = basename($file);
            $dest = $destinationDirectory . '\\' . $fileName;

            if (@copy($file, $dest)) {
                Log::info("Copied image to service tmp", ['from' => $file, 'to' => $dest]);
            } else {
                Log::error("Failed to copy image to tmp", ['from' => $file, 'to' => $dest]);
            }
        }
    }

    private function createJsonStatusFile(): bool
    {
        $serviceDir = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/');

        $statusData = [
            'ticket' => $this->naiadeTask->ticket,
            'status' => 'IN_PROGRESS',
            'timestamp' => date('c'),
        ];

        $statusFilePath = $serviceDir . '\\naiade_status\\' . $this->naiadeTask->ticket . '.json';

        $jsonContent = json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($statusFilePath, $jsonContent) === false) {
            $msg = "Failed to write status.json file at {$statusFilePath}";
            Log::error($msg);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            return false;
        }

        Log::info("Created status.json file at {$statusFilePath}");
        return true;
    }

    private function buildServiceCommand(): array
    {
        $environment = $this->naiadeTask->service->value;
        $ticket = $this->naiadeTask->ticket;

        return [
            $this->pythonPath,
            'run',
            '-n',
            $environment,
            'python',
            'bucle.py',
            "--ticket={$ticket}"
        ];
    }

    private function processImagesWithService(): bool
    {
        $command = $this->buildServiceCommand();
        $serviceDir = $this->naiadeTask->service->getServiceDirectory();

        $process = new Process($command, $serviceDir);
        $process->setTimeout(null);

        // NO BLOQUEA
        $process->start();

        // Guardas el PID si quieres trackear luego
        Log::info("Started background process", [
            'task_id' => $this->naiadeTask->id,
            'pid' => $process->getPid(),
        ]);

        return true;
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;

        $files = glob($directory . '\\*') ?: [];
        foreach ($files as $file) {
            if (is_dir($file)) $this->deleteDirectory($file);
            else @unlink($file);
        }
        @rmdir($directory);
    }

    private function markOriginalsAsFailed(): void
    {
        try {
            $ticketDirectory = $this->getTicketDirectoryPath();
            if (!is_dir($ticketDirectory)) return;

            $failedDir = dirname($ticketDirectory) . '\\failed_' . $this->naiadeTask->ticket . '_' . date('Ymd_His');
            @mkdir($failedDir, 0777, true);

            $files = glob($ticketDirectory . '\\*') ?: [];
            foreach ($files as $file) {
                $dest = $failedDir . '\\' . basename($file);
                @rename($file, $dest);
            }

            $remaining = glob($ticketDirectory . '\\*');
            if (empty($remaining)) @rmdir($ticketDirectory);
        } catch (Throwable $e) {
            Log::error("Error marking originals as failed", ['exception' => $e]);
        }
    }
}
