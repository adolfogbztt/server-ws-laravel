<?php

namespace App\Jobs;

use App\Events\MessageSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PythonLocalServiceJob implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue, SerializesModels;

    /**
     * @var string
     */
    private string $service;

    /**
     * @var string
     */
    private string $base64Photo;

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
     * @param string $service
     * @param string $base64Photo
     * @param string $bgColor
     * @param string $channel
     */
    public function __construct(string $service, string $base64Photo, string $bgColor, string $channel)
    {
        $this->service = $service;
        $this->base64Photo = $base64Photo;
        $this->bgColor = $bgColor;
        $this->channel = $channel;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $start = microtime(true);
            $serviceInfo = $this->getServiceInfo();
            $dir = $serviceInfo['dir'];
            $environmentDir = $serviceInfo['dir'];
            $filename = $this->saveImage($this->base64Photo, $environmentDir);

            $command = $this->buildCommand($this->service, $dir, $serviceInfo['environment'], $filename);

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
            $processedBase64Image = $this->imageToBase64($output_file_path);

            @unlink("{$dir}\\tmp_image\\{$filename}");
            @unlink($output_file_path);

            broadcast(new MessageSent(
                $this->channel,
                'service-response',
                [
                    'success' => true,
                    'message' => 'Foto procesada con éxito',
                    'data' => [
                        'service' => $this->service,
                        'time' => microtime(true) - $start,
                        'processed_image' => $processedBase64Image
                    ]
                ]
            ));
        } catch (\Exception $e) {
            broadcast(new MessageSent(
                $this->channel,
                'service-response',
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null
                ]
            ));
        }
    }

    /**
     * Saves the base64 image to a temporary file.
     * 
     * @param string $base64Photo
     * @param string $environmentDir
     * @return string
     * @throws \Exception
     */
    private function saveImage(string $base64Photo, string $environmentDir): string
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Photo, $matches)) {
            $extension = strtolower($matches[1]); // png, jpeg, jpg, gif, etc.
            $base64Photo = substr($base64Photo, strpos($base64Photo, ',') + 1);
        } else {
            throw new \Exception('Invalid base64 image format');
        }

        if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
            throw new \Exception('Invalid image extension');
        }

        $image = base64_decode($base64Photo);

        if ($image === false) {
            throw new \Exception('Decoding image failed.');
        }

        $fileName = Str::uuid() . '.' . $extension;
        $filePath = $environmentDir . '\\' . $fileName;

        file_put_contents($filePath, $image);

        return $fileName;
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

    /**
     * Converts an image to a base64 string.
     * 
     * @param string $imagePath
     * 
     * @return string
     */
    private function imageToBase64(string $imagePath): string
    {
        $imageData = file_get_contents($imagePath);
        return base64_encode($imageData);
    }
}
