<?php

namespace App\Enums;

enum NaiadeTaskService: string
{
    case GFPGAN = 'GFPGAN';
    case REMBG = 'REMBG';
    // case ESRGAN = 'ESRGAN';
    // case DENOISE = 'DENOISE';
    // case COLORIZATION = 'COLORIZATION';

    /**
     * @return array
     */
    public static function getAllServices(): array
    {
        return array_map(fn($service) => $service->value, self::cases());
    }

    /**
     * @return string
     */
    public function getServiceDirectory(): string
    {
        return match ($this) {
            self::GFPGAN => 'C:\\Users\\user\\GFPGAN',
            self::REMBG => 'C:\\Users\\user\\REMBG',
        };
    }
}
