<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class PythonServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    private string $type;
    private string $url;

    /**
     * Tiempo de bloqueo en segundos.
     */
    private int $lockTime = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, string $url)
    {
        $this->type = $type;
        $this->url = $url;
    }

    /**
     * Execute the job.
     */

    public function handle(): void
    {
        // Definir un identificador único para el lock basado en el tipo y URL
        $lockKey = "python_service_job:{$this->type}:{$this->url}";

        // Intentar obtener el bloqueo antes de ejecutar el job
        if (!Cache::add($lockKey, true, $this->lockTime)) {
            \Log::warning("El Job ya está en ejecución: {$this->type} - {$this->url}");
            return;
        }

        // Definir las URLs de las API según el tipo
        $apis = [
            'type1' => 'http://localhost:5001/delayed',
            'type2' => 'http://localhost:5002/delayed',
            // 'type3' => 'https://api.example.com/endpoint3',
        ];

        // Verificar si el tipo de API es válido
        if (!isset($apis[$this->type])) {
            \Log::error("Tipo de API inválido: {$this->type}");
            return;
        }

        // Hacer la petición a la API correspondiente
        try {
            $response = Http::get($apis[$this->type], ['source_url' => $this->url]);

            if ($response->successful()) {
                \Log::info("Petición a {$apis[$this->type]} exitosa para URL: {$this->url}");
            } else {
                \Log::error("Error en la petición a {$apis[$this->type]}: " . $response->body());
            }

            // Esperar 1 segundo antes de liberar el lock
            sleep(1);
        } catch (\Exception $e) {
            \Log::error("Error en la solicitud: " . $e->getMessage());
        } finally {
            // Liberar el bloqueo después de ejecutar el Job
            Cache::forget($lockKey);
        }
    }
}
