<?php

namespace App\Jobs;

use App\Events\MessageSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PythonServiceV2Job implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue, SerializesModels;

    /**
     * @var string
     */
    private string $model;

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
    private string $pythonPath = 'C:\\Users\\user\\anaconda3\\Scripts\\conda';
    
    /**
     * @var int
     */
    private int $lockTime = 10;

    /**
     * @param string $model
     * @param string $photo_url
     * @param string $token
     */
    public function __construct(string $model, string $photo_url, string $token)
    {
        $this->model = $model;
        $this->photo_url = $photo_url;
        $this->token = $token;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        $photoUrlMd5 = md5($this->photo_url);
        $lockKey = "python_service_job:{$this->model}:{$photoUrlMd5}";

        if (!Cache::add($lockKey, true, $this->lockTime)) {
            Log::warning("El Job ya está en ejecución: {$this->model} - {$this->photo_url}");
            return;
        }

        try {
            $modelData = $this->getModelInfo();
            $dir = $modelData['dir'];
            $environment = $modelData['environment'];
            $outputName = (string) Str::uuid();
            $url = escapeshellarg($this->photo_url);

            $command = [
                'cmd', '/c', "cd {$dir} && {$this->pythonPath} run -n {$environment} python script.py --url={$url} --output_name={$outputName}"
            ];

            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = trim($process->getOutput());
            $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($response['success']) || !$response['success']) {
                throw new \Exception($response['message'] ?? 'Error desconocido en la ejecución del script.');
            }

            $processed_url = $this->uploadToS3($response['url']);
            unlink($response['url']);

            Log::info("Procesamiento exitoso para photo_url: {$this->photo_url}");
            MessageSent::dispatch($this->token, [
                'success' => true,
                'message' => 'Foto procesada con éxito',
                'data' => [
                    'original_url' => $this->photo_url,
                    'processed_url' => $processed_url,
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error("Error en PythonServiceJob: " . $e->getMessage());
            MessageSent::dispatch($this->token, [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ]);
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * @return array
     */
    private function getModelInfo(): array
    {
        return match ($this->model) {
            'GFPGAN' => ['environment' => 'GFPGAN', 'dir' => 'C:\\Users\\user\\GFPGAN'],
            'REMBG' => ['environment' => 'BACKGROUND-REMOVAL', 'dir' => 'C:\\Users\\user\\REMBG'],
            default => throw new \InvalidArgumentException("Modelo no soportado: {$this->model}")
        };
    }

    /**
     * @param string $responsePath
     * 
     * @return string
     */
    private function uploadToS3(string $responsePath): string
    {
        return 'https://media.formaproducciones.com/public/media/2024/PARROQUIA/SAGRADO_CORAZON/ARTISTICAS/9041-JADE-CHAMSSEDDINE-TORRES/watermark/ST2_3221.JPG';
    }
}
