<?php

namespace App\Jobs;

use App\Events\MessageSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PythonServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * @var string
     */
    private string $model;

    /**
     * @var string
     */
    private string $version;

    /**
     * @var string
     */
    private string $photo_url;

    /**
     * @var string
     */
    private string $token;

    /**
     * @var string
     */
    private string $pythonPath = '"C:\Users\user\anaconda3\Scripts\conda"';

    /**
     * Tiempo de bloqueo en segundos.
     * 
     * @var string
     */
    private int $lockTime = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(string $model, string $photo_url, string $token)
    {
        $this->model = $model;
        $this->photo_url = $photo_url;
        $this->token = $token;
    }

    /**
     * Execute the job.
     */

    public function handle(): void
    {
        $photoUrlMd5 = md5($this->photo_url);
        // Definir un identificador único para el lock basado en el tipo y photo_url
        $lockKey = "python_service_job:{$this->model}:{$photoUrlMd5}";

        // Intentar obtener el bloqueo antes de ejecutar el job
        if (!Cache::add($lockKey, true, $this->lockTime)) {
            \Log::warning("El Job ya está en ejecución: {$this->model} - {$this->photo_url}");
            return;
        }

        // Hacer la petición a la API correspondiente
        try {
            $pythonPath = '"C:\Users\user\anaconda3\Scripts\conda"'; // Ruta de Conda entre comillas
            // $environment = 'GFPGAN';
            // $scriptPath = '"C:\Users\user\GFPGAN\script.py"'; // Ruta del script entre comillas

            $modelData = $this->getModelInfo();
            $dir = $modelData['dir'];
            $environment = $modelData['environment'];

            $url = escapeshellarg($this->photo_url);
            $outputName = (string) Str::uuid();

            // Construcción del comando
            // $command = "cmd /c \"cd C:\\Users\\user\\GFPGAN && {$pythonPath} run -n {$environment} python script.py --url={$url} --output_name={$outputName}\"";
            $command = "cmd /c \"cd {$dir} && {$pythonPath} run -n {$environment} python script.py --url={$url} --output_name={$outputName}\"";

            // dd($command);

            // Ejecutar comando y capturar la salida
            $output = shell_exec($command);
            
            if (is_null($output)) {
                \Log::error("Error al ejecutar el comando para photo_url: {$this->photo_url}");
                throw new \Exception("Error en la ejecución del comando");
            }

            $response = json_decode(trim(string: $output));

            // dd($response->success);

            if ($response->success) {
                $responsePath = $response->url;

                // Upload File s3

                \Log::info("Comando ejecutado de manera exitosa para photo_url: {$this->photo_url}");
                MessageSent::dispatch($this->token, ['message' => 'Comando ejecutado de manera exitosa para photo_url: ' . $this->photo_url]);
            } else {
                \Log::error("Error al ejecutar el comando para photo_url: {$this->photo_url}");
                throw new \Exception($response->message);
            }
        } catch (\Exception $e) {
            \Log::error("error: " . $e->getMessage());
        } finally {
            // Liberar el bloqueo después de ejecutar el Job
            Cache::forget($lockKey);
        }
    }

    /**
     * @return array
     */
    private function getModelInfo(): array
    {
        return [
            'environment' => match ($this->model) {
                'GFPGAN' => 'GFPGAN',
                'REMBG' => 'BACKGROUND-REMOVAL'
            },
            'dir' => match ($this->model) {
                'GFPGAN' => 'C:\\Users\\user\GFPGAN',
                'REMBG' => 'C:\\Users\\user\\REMBG'
            },
        ];

    }
}
