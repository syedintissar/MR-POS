<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Product pictures are re-encoded as JPEG (this removes anything hidden in the upload), scaled down, given a random
 * name and stored in the private files folder, which is included in every backup and is never served directly.
 */
class ProductImageService
{
    private const MAX_SIDE = 800;

    public function store(UploadedFile $file): string
    {
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($img === false) {
            throw ValidationException::withMessages(['image' => 'That file could not be read as a picture.']);
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        // Flatten onto white so transparent PNGs do not turn black as JPEG.
        $canvas = imagecreatetruecolor($nw, $nh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $dir = $this->root().DIRECTORY_SEPARATOR.'products';
        File::ensureDirectoryExists($dir);
        $name = Str::lower(Str::random(24)).'.jpg';
        imagejpeg($canvas, $dir.DIRECTORY_SEPARATOR.$name, 85);

        return 'products/'.$name;
    }

    public function delete(?string $path): void
    {
        if ($path && str_starts_with($path, 'products/')) {
            @unlink($this->absolute($path));
        }
    }

    public function absolute(string $path): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function root(): string
    {
        return rtrim((string) config('mrpos.files_path'), '\\/');
    }
}
