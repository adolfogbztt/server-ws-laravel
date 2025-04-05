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
    private string $bgColor;

    /**
     * @var string
     */
    private string $channel;

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
     * @param string $channel
     */
    public function __construct(string $service, string $photo_url, string $bgColor = 'transparent', string $channel)
    {
        $this->service = $service;
        $this->photo_url = $photo_url;
        $this->bgColor = $bgColor;
        $this->channel = $channel;
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

            $command = $this->buildCommand($this->service, $dir, $serviceData['environment'], $filename);

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

            broadcast(new MessageSent(
                $this->channel,
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
            ));
        } catch (\Throwable $e) {
            broadcast(new MessageSent(
                $this->channel,
                'service-response',
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null
                ]
            ));
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Builds the command to execute the Python script.
     * 
     * @param string $service
     * @param string $dir
     * @param string $environment
     * @param string $filename
     * 
     * @return array
     */
    private function buildCommand(string $service, string $dir, string $environment, string $filename): array
    {
        $command = [
            'cmd',
            '/c',
            "cd {$dir} && {$this->pythonPath} run -n {$environment} python script.py --filename={$filename}"
        ];

        if ($service === 'REMBG' && isset($this->bgColor)) {
            if (!preg_match('/^\d{1,3},\d{1,3},\d{1,3},\d{1,3}$/', $this->bgColor) && $this->bgColor !== 'transparent') {
                throw new \InvalidArgumentException("El color de fondo debe ser un valor RGBA válido o 'transparent'.");
            }

            $command[2] .= " --bg_color={$this->bgColor}";
        }

        return $command;
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
            $headers = @get_headers($this->photo_url, 1);

            // Validate if the headers were retrieved successfully
            if ($headers === false || !is_array($headers)) {
                throw new \Exception("Error al obtener los encabezados de la imagen: {$this->photo_url}");
            }

            // Normalize the headers
            $headers = array_change_key_case($headers, CASE_LOWER);

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

            $fp = fopen($localPath, 'w');

            if (!$fp) {
                throw new \Exception("Error al abrir el archivo para escritura: {$localPath}");
            }

            $ch = curl_init($this->photo_url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Tiempo máximo de espera en segundos

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);
            fclose($fp);

            if ($result === false) {
                unlink($localPath);
                throw new \Exception("Error en cURL: {$error}");
            }

            if ($httpCode !== 200) {
                unlink($localPath);
                throw new \Exception("Error al descargar la imagen. Código HTTP: {$httpCode}");
            }

            // Verificar si el archivo realmente se descargó correctamente
            if (!filesize($localPath)) {
                unlink($localPath);
                throw new \Exception("El archivo descargado está vacío: {$localPath}");
            }

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

        // $uploaded = Storage::disk('s3')->put($filename, file_get_contents($responsePath), 'public');
        $uploadedPath = Storage::disk('s3')->putFileAs(
            '',
            $responsePath,
            $filename,
            ['visibility' => 'public']
        );

        if (!$uploadedPath) {
            throw new \Exception("Error al subir el archivo a S3: {$filename}");
        }

        return Storage::disk('s3')->url($filename);
        // return 'https://media.formaproducciones.com/public/media/TEST/1.JPG';
    }
}
