<?php

namespace App\Jobs;

use App\Enums\NaiadeTaskStatus;
use App\Models\NaiadeTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

class ProcessNaiadeJob implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue, SerializesModels;

    /**
     * @var NaiadeTask
     */
    private NaiadeTask $naiadeTask;

    /**
     * @var string
     */
    private string $pythonPath = 'C:\\Users\\user\\anaconda3\\Scripts\\conda';

    /**
     * @var string
     */
    private string $naiadeFileServerPath = '\\\\192.168.1.99\\e\\naiade_files';

    /**
     * Create a new job instance.
     * 
     * @param NaiadeTask $naiadeTask
     */
    public function __construct(NaiadeTask $naiadeTask)
    {
        $this->naiadeTask = $naiadeTask;
    }

    /**
     * Execute the job.
     * 
     * @return void
     */
    public function handle(): void
    {
        if (!$this->validations()) {
            return;
        }

        $this->updateNaiadeTaskStatus(NaiadeTaskStatus::IN_PROGRESS);

        $this->moveImagesToServiceTmpDir();

        if ($this->processImagesWithService()) {
            $this->moveProcessedImagesBackToFileServer();
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::COMPLETED);
        } else {
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, 'Error processing images with service');
        }

        $this->cleanupTemporaryDirectories();
    }

    /**
     * @return bool
     */
    private function validations(): bool
    {
        if (!$this->verifyNaiadeTaskDirectoryExists()) {
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, 'Ticket directory does not exist.');
            return false;
        }

        if (!$this->verifyNaiadeTaskDirectoryHasImages()) {
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, 'No image files found in the ticket directory.');
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    private function verifyNaiadeTaskDirectoryExists(): bool
    {
        return is_dir($this->naiadeFileServerPath . '\\' . $this->naiadeTask->ticket);
    }

    /**
     * @return bool
     */
    private function verifyNaiadeTaskDirectoryHasImages(): bool
    {
        $ticketDirectory = $this->naiadeFileServerPath . '\\' . $this->naiadeTask->ticket;

        $imageFiles = glob($ticketDirectory . '\\*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE);

        return !empty($imageFiles);
    }

    /**
     * @param NaiadeTaskStatus $status
     * @param string|null $message
     * 
     * @return void
     */
    private function updateNaiadeTaskStatus(NaiadeTaskStatus $status, ?string $message = null): void
    {
        $this->naiadeTask->status = $status;
        if (!is_null($message)) {
            $this->naiadeTask->message = $message;
        }
        $this->naiadeTask->updateQuietly();
    }

    /**
     * @return void
     */
    private function moveImagesToServiceTmpDir(): void
    {
        $ticketDirectory = $this->naiadeFileServerPath . '\\' . $this->naiadeTask->ticket;
        $destinationDirectory = $this->naiadeTask->service->getServiceDirectory()
            . '\\naiade_tmp\\'
            . $this->naiadeTask->ticket;

        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0777, true);
        }

        $imageFiles = glob($ticketDirectory . '\\*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE);

        foreach ($imageFiles as $file) {
            $fileName = basename($file);
            rename($file, $destinationDirectory . '\\' . $fileName);
        }
    }

    /**
     * @return array
     */
    private function buildServiceCommand(): array
    {
        $serviceDir = $this->naiadeTask->service->getServiceDirectory();
        $environment = $this->naiadeTask->service->value;
        $ticket = $this->naiadeTask->ticket;

        $baseCommand = "cd {$serviceDir} && {$this->pythonPath} run -n {$environment} python bucle.py --ticket={$ticket}";

        return [
            'cmd.exe',
            '/c',
            $baseCommand
        ];
    }

    /**
     * @return bool
     */
    private function processImagesWithService(): bool
    {
        $command = $this->buildServiceCommand();

        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return void
     */
    private function moveProcessedImagesBackToFileServer(): void
    {
        $serviceOutputDirectory = $this->naiadeTask->service->getServiceDirectory()
            . '\\naiade_output\\'
            . $this->naiadeTask->ticket;

        $destinationDirectory = $this->naiadeFileServerPath . '\\' . $this->naiadeTask->ticket;

        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0777, true);
        }

        $imageFiles = glob($serviceOutputDirectory . '\\*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE);

        foreach ($imageFiles as $file) {
            $fileName = basename($file);
            rename($file, $destinationDirectory . '\\' . $fileName);
        }
    }

    /**
     * @return void
     */
    private function cleanupTemporaryDirectories(): void
    {
        $serviceTmpDirectory = $this->naiadeTask->service->getServiceDirectory()
            . '\\naiade_tmp\\'
            . $this->naiadeTask->ticket;

        $serviceOutputDirectory = $this->naiadeTask->service->getServiceDirectory()
            . '\\naiade_output\\'
            . $this->naiadeTask->ticket;

        $this->deleteDirectory($serviceTmpDirectory);
        $this->deleteDirectory($serviceOutputDirectory);

        if ($this->naiadeTask->status === NaiadeTaskStatus::FAILED) {
            $ticketDirectory = $this->naiadeFileServerPath . '\\' . $this->naiadeTask->ticket;
            $this->deleteDirectory($ticketDirectory);
        }
    }

    /**
     * @param string $directory
     * 
     * @return void
     */
    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '\\*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->deleteDirectory($file);
            } else {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }
}
