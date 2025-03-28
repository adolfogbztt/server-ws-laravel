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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PythonServiceV2Job implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue, SerializesModels;

    /**
     * @var string
     */
    public string $service;

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
     * @var int
     */
    private int $maxImageSizeMB = 10;

    /**
     * @param string $service
     * @param string $photo_url
     * @param string $token
     */
    public function __construct(string $service, string $photo_url, string $token)
    {
        $this->service = $service;
        $this->photo_url = $photo_url;
        $this->token = $token;
    }

    /**
     * Handles the job of processing the image using a Python service.
     * 
     * @return void
     */
    public function handle(): void
    {
        $start = microtime(true);
        $photoUrlMd5 = md5($this->photo_url);
        $lockKey = "python_service_job:{$this->service}:{$photoUrlMd5}";

        if (!Cache::add($lockKey, true, $this->lockTime)) {
            Log::warning("El Job ya está en ejecución: {$this->service} - {$this->photo_url}");
            return;
        }

        try {
            $filename = $this->downloadImage();
            $serviceData = $this->getServiceInfo();
            $dir = $serviceData['dir'];
            $environment = $serviceData['environment'];

            $command = [
                'cmd',
                '/c',
                "cd {$dir} && {$this->pythonPath} run -n {$environment} python script.py --filename={$filename}"
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

            $output_file_path = "{$dir}\\output\\" . $response['processed_image'];
            $processed_url = $this->uploadToS3($output_file_path);

            // Clean up the tmp images
            @unlink("{$dir}\\tmp_image\\{$filename}");
            @unlink($output_file_path);

            Log::info("Procesamiento exitoso para photo_url: {$this->photo_url}");
            MessageSent::dispatch(
                $this->token,
                'service-response',
                [
                    'success' => true,
                    'message' => 'Foto procesada con éxito',
                    'data' => [
                        'service' => $this->service,
                        'time' => microtime(true) - $start,
                        'original_url' => $this->photo_url,
                        'processed_url' => $processed_url
                    ]
                ]
            );
        } catch (\Throwable $e) {
            MessageSent::dispatch(
                $this->token,
                'service-response',
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null
                ]
            );
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Returns the service information based on the service name.
     * 
     * @return array
     */
    private function getServiceInfo(): array
    {
        return match ($this->service) {
            'GFPGAN' => ['environment' => 'GFPGAN', 'dir' => 'C:\\Users\\user\\GFPGAN'],
            'REMBG' => ['environment' => 'BACKGROUND-REMOVAL', 'dir' => 'C:\\Users\\user\\REMBG'],
            default => throw new \InvalidArgumentException("Servicio no soportado: {$this->service}")
        };
    }

    /**
     * Downloads an image and validates its type and size.
     *
     * @return string
     * @throws \Exception
     */
    private function downloadImage(): string
    {
        $serviceData = $this->getServiceInfo();
        $dir = $serviceData['dir'];
        $tmpImageDir = "{$dir}\\tmp_image";

        if (!is_dir($tmpImageDir)) {
            mkdir($tmpImageDir, 0777, true);
        }

        $pathInfo = pathinfo(parse_url($this->photo_url, PHP_URL_PATH));
        $extension = $pathInfo['extension'] ?? 'jpg';
        $extension = explode('?', $extension)[0];

        $filename = Str::uuid() . ".{$extension}";
        $localPath = "{$tmpImageDir}\\{$filename}";

        try {
            // Get the headers to check if it's an image
            $headers = get_headers($this->photo_url, 1);
            if (!isset($headers['content-type']) || !str_starts_with($headers['content-type'], 'image/')) {
                throw new \Exception('El archivo descargado no es una imagen válida.');
            }

            // Validate the maximum allowed size
            $maxSize = $this->maxImageSizeMB * 1024 * 1024;
            if (isset($headers['content-length'])) {
                $fileSize = (int) $headers['content-length'];
                if ($fileSize > $maxSize) {
                    throw new \Exception("La imagen excede el tamaño máximo permitido ($this->maxImageSizeMB MB).");
                }
            } else {
                throw new \Exception('No se pudo determinar el tamaño de la imagen.');
            }

            $imageContent = file_get_contents($this->photo_url);
            if ($imageContent === false) {
                throw new \Exception("Error al descargar la imagen: {$this->photo_url}");
            }

            // Validate if the content is an image
            if (getimagesizefromstring($imageContent) === false) {
                throw new \Exception('El archivo descargado no es una imagen válida.');
            }

            file_put_contents($localPath, $imageContent);
            return $filename;
        } catch (\Throwable $e) {
            throw new \Exception('Error al descargar la imagen: ' . $e->getMessage());
        }
    }

    /**
     * Uploads the processed image to S3 and returns the URL.
     * 
     * @param string $responsePath
     * 
     * @return string
     */
    private function uploadToS3(string $responsePath): string
    {
        // Implement your S3 upload logic here
        if (!file_exists($responsePath)) {
            throw new \Exception("El archivo no existe: {$responsePath}");
        }

        $filename = basename($responsePath);

        $uploaded = Storage::disk('s3')->put($filename, file_get_contents($responsePath), 'public');

        if (!$uploaded) {
            throw new \Exception("Error al subir el archivo a S3: {$filename}");
        }

        return Storage::disk('s3')->url($filename);
    }
}
