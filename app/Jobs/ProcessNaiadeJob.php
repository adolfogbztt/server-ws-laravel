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

    // Ruta al ejecutable conda / python (ajusta según tu entorno)
    private string $pythonPath = 'C:\\Users\\user\\anaconda3\\Scripts\\conda';

    // Ruta al file server SMB (UNC)
    private string $naiadeFileServerPath = '\\\\192.168.1.99\\e\\naiade_files';

    // Timeout (segundos) para el proceso python
    private int $processTimeout = 300; // 5 minutos por defecto, ajustable

    // Reintentos para operaciones de I/O como rename/copy
    private int $ioRetries = 5;
    private int $ioRetrySleepMs = 500; // 250ms entre reintentos

    // Extensiones aceptadas (minúsculas)
    private array $imageExtensions = ['jpg','jpeg','png','JPG','JPEG','PNG'];

    public function __construct(NaiadeTask $naiadeTask)
    {
        $this->naiadeTask = $naiadeTask;
    }

    public function handle(): void
    {
        try {
            if (!$this->validations()) {
                return;
            }

            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::IN_PROGRESS);

            $this->moveImagesToServiceTmpDir();

            $success = $this->processImagesWithService();

            if ($success) {
                $this->moveProcessedImagesBackToFileServer();
                $this->updateNaiadeTaskStatus(NaiadeTaskStatus::COMPLETED);
            } else {
                // en caso de fallo, marcamos FAILED pero NO borramos originales
                $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, 'Error processing images with service (see logs)');
                Log::error("NaiadeTask {$this->naiadeTask->id}: Process returned non-success status. Check logs for details.");
                $this->markOriginalsAsFailed();
            }
        } catch (Throwable $e) {
            // captura cualquier excepción inesperada
            $msg = "Unhandled exception: {$e->getMessage()}";
            Log::error($msg, ['exception' => $e]);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            $this->markOriginalsAsFailed();
        } finally {
            // limpieza segura: sólo borra temporales dentro del serviceDir
            $this->cleanupTemporaryDirectories();
        }
    }

    /**
     * Validaciones iniciales: existe carpeta y contiene imágenes.
     */
    private function validations(): bool
    {
        $ticketDir = $this->getTicketDirectoryPath();

        if (!is_dir($ticketDir)) {
            $msg = "Ticket directory does not exist or is not accessible: {$ticketDir}";
            Log::warning($msg);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            return false;
        }

        $images = $this->globImages($ticketDir);

        if (empty($images)) {
            $msg = "No image files found in the ticket directory: {$ticketDir}";
            Log::info($msg);
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            return false;
        }

        return true;
    }

    /**
     * Construye la ruta UNC al ticket
     */
    private function getTicketDirectoryPath(): string
    {
        return rtrim($this->naiadeFileServerPath, '\\/') . '\\' . $this->naiadeTask->ticket;
    }

    /**
     * Glob de imágenes con fallback si GLOB_BRACE no existe.
     */
    private function globImages(string $directory): array
    {
        // Normaliza path sin barra final
        $directory = rtrim($directory, '\\/');

        // Intentamos con GLOB_BRACE si está disponible
        if (defined('GLOB_BRACE')) {
            $pattern = $directory . '\\*.{' . implode(',', $this->imageExtensions) . '}';
            $files = glob($pattern, GLOB_BRACE) ?: [];
        } else {
            // Fallback: multiple globs
            $files = [];
            foreach ($this->imageExtensions as $ext) {
                $files = array_merge($files, glob($directory . '\\*.' . $ext) ?: []);
            }
        }

        // Eliminamos duplicados y ordenamos
        $files = array_values(array_unique($files));

        return $files;
    }

    /**
     * Actualiza el estado de la tarea (no lanza excepciones).
     */
    private function updateNaiadeTaskStatus(NaiadeTaskStatus $status, ?string $message = null): void
    {
        try {
            $this->naiadeTask->status = $status;
            if (!is_null($message)) {
                // corta mensajes demasiado largos (por si vienen errores enormes)
                $this->naiadeTask->message = mb_substr($message, 0, 1000);
            }
            $this->naiadeTask->updateQuietly();
        } catch (Throwable $e) {
            Log::error("Failed to update NaiadeTask status", ['exception' => $e, 'task_id' => $this->naiadeTask->id]);
        }
    }

    /**
     * Mueve imágenes desde el file-server SMB hacia el directorio tmp del servicio.
     * Usa rename() y si falla, hace copy()+unlink() como fallback; con reintentos.
     */
    private function moveImagesToServiceTmpDir(): void
    {
        $ticketDirectory = $this->getTicketDirectoryPath();

        $destinationDirectory = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/') .
            '\\naiade_tmp\\' . $this->naiadeTask->ticket;

        if (!is_dir($destinationDirectory) && !@mkdir($destinationDirectory, 0777, true) && !is_dir($destinationDirectory)) {
            $msg = "Cannot create destination directory: {$destinationDirectory}";
            Log::error($msg);
            throw new \RuntimeException($msg);
        }

        $imageFiles = $this->globImages($ticketDirectory);

        foreach ($imageFiles as $file) {
            $fileName = basename($file);
            $dest = $destinationDirectory . '\\' . $fileName;

            if ($this->safeMoveFile($file, $dest) === false) {
                // si no se pudo mover el archivo, lo registramos y continuamos
                Log::error("Failed to move image to service tmp dir", ['from' => $file, 'to' => $dest, 'task_id' => $this->naiadeTask->id]);
            } else {
                Log::info("Moved image to service tmp", ['from' => $file, 'to' => $dest, 'task_id' => $this->naiadeTask->id]);
            }
        }
    }

    /**
     * Construye el comando para ejecutar el servicio (shell command).
     */
    private function buildServiceCommand(): string
    {
        $serviceDir = $this->naiadeTask->service->getServiceDirectory();
        $environment = $this->naiadeTask->service->value;
        $ticket = $this->naiadeTask->ticket;

        // Usamos línea de comando compuesta; se ejecutará por cmd.exe /c en Windows.
        // Asegúrate que paths con espacios estén entre comillas.
        $serviceDirQuoted = '"' . $serviceDir . '"';
        $baseCommand = "cd {$serviceDirQuoted} && \"{$this->pythonPath}\" run -n \"{$environment}\" python bucle.py --ticket=\"{$ticket}\"";

        return $baseCommand;
    }

    /**
     * Ejecuta el proceso Python, captura stdout/stderr y lo loggea.
     */
    private function processImagesWithService(): bool
    {
        $commandLine = $this->buildServiceCommand();

        // Ejecutamos con cmd.exe /c para Windows.
        $process = Process::fromShellCommandline("cmd.exe /c {$commandLine}");
        $process->setTimeout(null);

        $stdout = '';
        $stderr = '';

        // Ejecuta y recoge salida en tiempo real (no bloqueante)
        $process->run(function ($type, $buffer) use (&$stdout, &$stderr) {
            if ($type === Process::OUT) {
                $stdout .= $buffer;
            } else { // Process::ERR
                $stderr .= $buffer;
            }
        });

        // Guardamos logs al storage/logs y en la DB (campo message truncado)
        if (!empty($stdout)) {
            Log::info("NaiadeTask {$this->naiadeTask->id} - process stdout: " . $this->shorten($stdout, 3000));
        }
        if (!empty($stderr)) {
            Log::error("NaiadeTask {$this->naiadeTask->id} - process stderr: " . $this->shorten($stderr, 3000));
        }

        // Si falló, añadimos detalle al mensaje de la tarea
        if (!$process->isSuccessful()) {
            $msg = "Service process failed. ExitCode={$process->getExitCode()}";
            if (!empty($stderr)) {
                $msg .= " stderr: " . $this->shorten($stderr, 800);
            }
            $this->updateNaiadeTaskStatus(NaiadeTaskStatus::FAILED, $msg);
            return false;
        }

        return true;
    }

    /**
     * Mueve imágenes procesadas desde service_output de vuelta al file server.
     */
    private function moveProcessedImagesBackToFileServer(): void
    {
        $serviceOutputDirectory = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/') .
            '\\naiade_output\\' . $this->naiadeTask->ticket;

        $destinationDirectory = $this->getTicketDirectoryPath();

        if (!is_dir($serviceOutputDirectory)) {
            Log::warning("Service output dir does not exist: {$serviceOutputDirectory}");
            return;
        }

        if (!is_dir($destinationDirectory) && !@mkdir($destinationDirectory, 0777, true) && !is_dir($destinationDirectory)) {
            $msg = "Cannot create destination directory on file server: {$destinationDirectory}";
            Log::error($msg);
            throw new \RuntimeException($msg);
        }

        $imageFiles = $this->globImages($serviceOutputDirectory);

        foreach ($imageFiles as $file) {
            $fileName = basename($file);
            $dest = $destinationDirectory . '\\' . $fileName;

            if ($this->safeMoveFile($file, $dest) === false) {
                Log::error("Failed to move processed image back to file server", ['from' => $file, 'to' => $dest, 'task_id' => $this->naiadeTask->id]);
            } else {
                Log::info("Moved processed image back to file server", ['from' => $file, 'to' => $dest, 'task_id' => $this->naiadeTask->id]);
            }
        }
    }

    /**
     * Limpia directorios temporales del servicio (solo los temporales y output).
     * NO borra la carpeta original del ticket en el file server si la tarea falló.
     */
    private function cleanupTemporaryDirectories(): void
    {
        $serviceTmpDirectory = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/') .
            '\\naiade_tmp\\' . $this->naiadeTask->ticket;

        $serviceOutputDirectory = rtrim($this->naiadeTask->service->getServiceDirectory(), '\\/') .
            '\\naiade_output\\' . $this->naiadeTask->ticket;

        $this->deleteDirectory($serviceTmpDirectory);
        $this->deleteDirectory($serviceOutputDirectory);
    }

    /**
     * Borra recursivamente un directorio (con precaución).
     */
    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '\\*') ?: [];
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->deleteDirectory($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($directory);
    }

    /**
     * Intento seguro para mover un archivo: rename() con reintentos; fallback a copy+unlink.
     * Devuelve true si al final el archivo existe en destino (y no en origen).
     */
    private function safeMoveFile(string $from, string $to): bool
    {
        // Si el destino ya existe, intentamos renombrarlo con sufijo para evitar sobreescritura accidental
        $toFinal = $to;
        if (file_exists($toFinal)) {
            $toFinal = $this->uniqueDestination($toFinal);
        }

        // Reintentos para rename
        for ($attempt = 1; $attempt <= $this->ioRetries; $attempt++) {
            if (@rename($from, $toFinal)) {
                return true;
            }
            usleep($this->ioRetrySleepMs * 1000);
        }

        // Fallback: copy + unlink (útil en SMB donde rename puede fallar por distinto volume)
        for ($attempt = 1; $attempt <= $this->ioRetries; $attempt++) {
            if (@copy($from, $toFinal)) {
                // intenta eliminar el original; si no se puede eliminar, dejamos copia y reportamos
                if (@unlink($from) || !file_exists($from)) {
                    return true;
                } else {
                    Log::warning("Copied but could not unlink original", ['from' => $from, 'to' => $toFinal, 'task_id' => $this->naiadeTask->id]);
                    return true; // aun así consideramos éxito (no queremos bloquear)
                }
            }
            usleep($this->ioRetrySleepMs * 1000);
        }

        return false;
    }

    /**
     * Genera un nombre destino único agregando sufijo incremental si ya existe.
     */
    private function uniqueDestination(string $path): string
    {
        $dir = dirname($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        $i = 1;
        do {
            $candidate = $dir . '\\' . $base . "_{$i}" . ($ext ? '.' . $ext : '');
            $i++;
        } while (file_exists($candidate) && $i < 10000);

        return $candidate;
    }

    /**
     * Marca la carpeta original como failed moviéndola a una subcarpeta failed_<timestamp>
     * Esto evita borrar los originales y permite posteriores análisis manuales.
     */
    private function markOriginalsAsFailed(): void
    {
        try {
            $ticketDirectory = $this->getTicketDirectoryPath();
            if (!is_dir($ticketDirectory)) {
                return;
            }
            $failedDir = dirname($ticketDirectory) . '\\failed_' . $this->naiadeTask->ticket . '_' . date('Ymd_His');

            if (!@mkdir($failedDir, 0777, true) && !is_dir($failedDir)) {
                Log::warning("Could not create failed dir: {$failedDir}");
                return;
            }

            $files = glob($ticketDirectory . '\\*') ?: [];
            foreach ($files as $file) {
                $dest = $failedDir . '\\' . basename($file);
                if ($this->safeMoveFile($file, $dest) === false) {
                    Log::error("Failed to move original to failed dir", ['from' => $file, 'to' => $dest, 'task_id' => $this->naiadeTask->id]);
                }
            }

            // si la carpeta original quedó vacía intentamos borrarla
            $remaining = glob($ticketDirectory . '\\*');
            if (empty($remaining)) {
                @rmdir($ticketDirectory);
            }
        } catch (Throwable $e) {
            Log::error("Error marking originals as failed", ['exception' => $e, 'task_id' => $this->naiadeTask->id]);
        }
    }

    /**
     * Trunca una cadena manteniendo comienzo y final si es demasiado larga.
     */
    private function shorten(string $text, int $maxLen = 1000): string
    {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }
        $half = (int)floor($maxLen / 2);
        return mb_substr($text, 0, $half) . ' ... ' . mb_substr($text, -$half);
    }
}
