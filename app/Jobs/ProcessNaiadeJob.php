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

class ProcessNaiadeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private NaiadeTask $naiadeTask;

    private string $pythonPath = 'C:\\Users\\user\\anaconda3\\Scripts\\conda';
    private string $naiadeFileServerPath = '\\\\192.168.1.99\\e\\naiade_files';

    private int $processTimeout = 300;
    private int $ioRetries = 5;
    private int $ioRetrySleepMs = 500;

    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];

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

            $success = $this->processImagesWithService();

            if ($success) {
                $this->copyProcessedImagesToResultFolder();
                $this->updateNaiadeTaskStatus(NaiadeTaskStatus::COMPLETED);
            } else {
                $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, 'Error processing images (check logs)');
                Log::error("NaiadeTask {$this->naiadeTask->id}: Process returned non-success status.");
                $this->markOriginalsAsFailed();
            }
        } catch (Throwable $e) {
            $msg = "Unhandled exception: {$e->getMessage()}";
            Log::error($msg, ['exception' => $e]);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            $this->markOriginalsAsFailed();
        } finally {
            $this->cleanupTemporaryDirectories();
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

        $stdout = '';
        $stderr = '';

        $process->run(function ($type, $buffer) use (&$stdout, &$stderr) {
            if ($type === Process::OUT) $stdout .= $buffer;
            else $stderr .= $buffer;
        });

        if (!empty($stdout)) Log::info("Task {$this->naiadeTask->id} stdout: " . $this->shorten($stdout, 3000));
        if (!empty($stderr)) Log::error("Task {$this->naiadeTask->id} stderr: " . $this->shorten($stderr, 3000));

        if (!$process->isSuccessful()) {
            $msg = "Process failed. ExitCode={$process->getExitCode()}";
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            return false;
        }

        return true;
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

    private function cleanupTemporaryDirectories(): void
    {
        $serviceBase = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/');

        $tmpTicket = $serviceBase . '\\naiade_tmp\\' . $this->naiadeTask->ticket;
        $outTicket = $serviceBase . '\\naiade_output\\' . $this->naiadeTask->ticket;

        $this->deleteDirectory($tmpTicket);
        $this->deleteDirectory($outTicket);
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

    private function shorten(string $text, int $maxLen = 1000): string
    {
        if (mb_strlen($text) <= $maxLen) return $text;
        $half = (int)floor($maxLen / 2);
        return mb_substr($text, 0, $half) . ' ... ' . mb_substr($text, -$half);
    }
}
