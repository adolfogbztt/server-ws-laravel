<?php

namespace App\Jobs;

use App\Events\MessageSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PythonServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    private string $model;
    private string $version;
    private string $photo_url;
    private string $token;

    /**
     * Tiempo de bloqueo en segundos.
     */
    private int $lockTime = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(string $model, string $version, string $photo_url, string $token)
    {
        $this->model = $model;
        $this->version = $version;
        $this->photo_url = $photo_url;
        $this->token = $token;
    }

    /**
     * Execute the job.
     */

    public function handle(): void
    {
        // Definir un identificador único para el lock basado en el tipo y photo_url
        $lockKey = "python_service_job:{$this->model}:{$this->version}:{$this->photo_url}";

        // Intentar obtener el bloqueo antes de ejecutar el job
        if (!Cache::add($lockKey, true, $this->lockTime)) {
            \Log::warning("El Job ya está en ejecución: {$this->model} - {$this->photo_url}");
            return;
        }



        // Hacer la petición a la API correspondiente
        try {
            // $response = Http::get($apis[$this->type], ['photo_url' => $this->photo_url]);
            // ejecutar comando de consola
            $command = 'notepad.exe '; // Reemplaza "dir" con tu comando

            $process = Process::fromShellCommandline($command);
            $process->run();

            if ($process->isSuccessful()) {

                \Log::info("comando ejecutado de manera exitosa para photo_url: {$this->photo_url}");
                
                MessageSent::dispatch($this->token, ['message' => 'comando ejecutado de manera exitosa para photo_url: ' . $this->photo_url]);

            } else {
                throw new ProcessFailedException($process);
                // \Log::error("Error en la petición ");
            }

            // Esperar 1 segundo antes de liberar el lock
            sleep(1);
        } catch (\Exception $e) {
            \Log::error("error: " . $e->getMessage());
        } finally {
            // Liberar el bloqueo después de ejecutar el Job
            Cache::forget($lockKey);
        }
    }
}
