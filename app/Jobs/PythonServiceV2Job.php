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
    private int $maxImageSizeMB = 5;

    /**
     * @var int
     */
    private int $canvasIndex;

    /**
     * @var int
     */
    private int $elementIndex;

    /**
     * @param string $service
     * @param string $photo_url
     * @param string|null $bgColor | RGBA color or 'transparent'
     * @param string $channel
     */
    public function __construct(string $service, string $photo_url, ?string $bgColor = 'transparent', string $channel, int $canvasIndex, int $elementIndex)
    {
        $this->service = $service;
        $this->photo_url = $photo_url;
        $this->bgColor = $bgColor;
        $this->channel = $channel;
        $this->canvasIndex = $canvasIndex;
        $this->elementIndex = $elementIndex;
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
            Log::info('' . $processed_url);

            // Clean up the tmp images
            @unlink("{$dir}\\tmp_image\\{$filename}");
            @unlink($output_file_path);

            Log::info('pre cast');
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
                        'processed_url' => $processed_url,
                        'canvasIndex' => $this->canvasIndex,
                        'elementIndex' => $this->elementIndex
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
                    'data' => [
                        'canvasIndex' => $this->canvasIndex,
                        'elementIndex' => $this->elementIndex
                    ]
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
        $baseCommand = "cd {$dir} && {$this->pythonPath} run -n {$environment} python script.py --filename={$filename}";

        if ($service === 'REMBG' && isset($this->bgColor)) {
            $normalizedColor = $this->normalizeBgColor($this->bgColor);
            $baseCommand .= " --bg_color={$normalizedColor}";
        }

        return [
            'cmd',
            '/c',
            $baseCommand
        ];
    }

    /**
     * Normalizes the background color to a valid RGBA format.
     * 
     * @param string $bgColor
     * 
     * @return string
     */
    private function normalizeBgColor(string $bgColor): string
    {
        $bgColor = strtolower(trim($bgColor));

        if ($bgColor === 'transparent') {
            return 'transparent';
        }

        $parts = explode(',', $bgColor);

        if (count($parts) !== 4) {
            throw new \InvalidArgumentException("The background color must have 4 RGBA components or be 'transparent'.");
        }

        [$r, $g, $b, $a] = $parts;

        foreach ([$r, $g, $b, $a] as $component) {
            if (!is_numeric($component) || (int) $component < 0 || (int) $component > 255) {
                throw new \InvalidArgumentException('The components R, G, B must be between 0 and 255.');
            }
        }

        // Check if $a is numeric and between 0 and 1
        if (is_numeric($a) && floatval($a) <= 1) {
            $a = intval(round(floatval($a) * 255));
        } elseif (is_numeric($a) && ($a < 0 || $a > 255)) {
            // $a = 255; // Default to 255 if out of range
            throw new \InvalidArgumentException('The Alpha component must be between 0 and 1 or between 0 and 255.');
        }

        return implode(',', [$r, $g, $b, $a]);
    }

    private function codificarURL(string $url): string
    {
        // parse_url separa los componentes de la URL
        $partes = parse_url($url);
        // Codificamos cada parte individualmente
        $esquema = isset($partes['scheme']) ? $partes['scheme'] . '://' : '';
        $host = isset($partes['host']) ? $partes['host'] : '';
        $puerto = isset($partes['port']) ? ':' . $partes['port'] : '';
        $ruta = isset($partes['path']) ? implode('/', array_map('rawurlencode', explode('/', $partes['path']))) : '';
        $query = isset($partes['query']) ? '?' . http_build_query(parse_str($partes['query'], $output), '', '&', PHP_QUERY_RFC3986) : '';
        $fragmento = isset($partes['fragment']) ? '#' . rawurlencode($partes['fragment']) : '';
        return $esquema . $host . $puerto . $ruta . $query . $fragmento;
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
            //
            $url = $this->codificarURL($this->photo_url);

            // Get the headers to check if it's an image
            $headers = @get_headers($url, 1);

            // Validate if the headers were retrieved successfully
            if ($headers === false || !is_array($headers)) {
                throw new \Exception("Error al obtener los encabezados de la imagen: {$this->photo_url}");
            }

            // Normalize the headers
            $headers = array_change_key_case($headers, CASE_LOWER);

            // if (!isset($headers['content-type']) || !str_starts_with($headers['content-type'], 'image/')) {
            //     throw new \Exception('El archivo descargado no es una imagen válida.');
            // }

            if (!isset($headers['content-type'])) {
                throw new \Exception('El archivo descargado no es una imagen válida.');
            }

            $contentType = $headers['content-type'];

            if (is_array($contentType)) {
                $contentType = end($contentType);
            }

            if (!str_starts_with($contentType, 'image/')) {
                throw new \Exception('El archivo descargado no es una imagen válida.');
            }

            // Validate the maximum allowed size
            $maxSize = $this->maxImageSizeMB * 1024 * 1024;
            if (isset($headers['content-length'])) {
                $contentLength = $headers['content-length'];

                if (is_array($contentLength)) {
                    $contentLength = end($contentLength);
                }

                $fileSize = (int) $contentLength;
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

            $ch = curl_init($url);
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

        if (!file_exists($responsePath)) {
            throw new \Exception("El archivo no existe: {$responsePath}");
        }

        $filename = basename($responsePath);

        $file = file_get_contents($responsePath);

        $uploadedPath = Storage::disk('s3')->put($filename, $file, ['visibility' => 'public']);

        if (!$uploadedPath) {
            throw new \Exception("Error al subir el archivo a S3: {$responsePath} | {$filename}");
        }

        return Storage::disk('s3')->url($filename);
        //throw new \Exception("Error al subir el archivo a S3");
        // return 'https://media.formaproducciones.com/public/media/TEST/1.JPG';
    }
}
