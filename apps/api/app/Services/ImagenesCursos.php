<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imágenes referenciales de cursos publicados: solo Laravel Storage (disco local «public»).
 * En la base se guardan únicamente rutas relativas generadas por el servidor (`cursos/<uuid>.<ext>`).
 */
class ImagenesCursos
{
    public const DISCO = 'public';

    public const MAX_KB = 2048;

    /** Tope de píxeles a decodificar (≈ 100 MB de memoria en GD). */
    private const MAX_PIXELES = 25000000;

    private const CALIDAD = 85;

    /** @var array<string, string> */
    private const EXTENSIONES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PATRON = '#\Acursos/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|png|webp)\z#D';

    /** @return list<string> */
    public static function reglas(bool $requerida): array
    {
        return [
            $requerida ? 'required' : 'nullable',
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.self::MAX_KB,
            'dimensions:min_width=100,min_height=100,max_width=8000,max_height=8000',
        ];
    }

    public static function rutaValida(?string $ruta): bool
    {
        return $ruta !== null && ! str_contains($ruta, '..') && preg_match(self::PATRON, $ruta) === 1;
    }

    public static function url(?string $ruta): ?string
    {
        if ($ruta === null || ! self::rutaValida($ruta)) {
            return null;
        }

        return Storage::disk(self::DISCO)->url($ruta);
    }

    /**
     * Decodifica y vuelve a codificar con GD para descartar metadatos EXIF/GPS y bytes añadidos.
     */
    public function guardar(UploadedFile $archivo): string
    {
        $extension = self::EXTENSIONES[(string) $archivo->getMimeType()] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Formato de imagen no permitido.');
        }

        $original = (string) file_get_contents((string) $archivo->getRealPath());
        $limpia = $this->recodificar($original, (string) $archivo->getMimeType());
        $ruta = 'cursos/'.Str::uuid()->toString().'.'.$extension;

        if (! Storage::disk(self::DISCO)->put($ruta, $limpia) || ! self::rutaValida($ruta)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }

        return $ruta;
    }

    /** Borra del disco solo si ninguna ficha de curso referencia la ruta. */
    public function liberarSiHuerfana(?string $ruta): void
    {
        if ($ruta === null || ! self::rutaValida($ruta)) {
            return;
        }

        $usada = DB::table('curso_publicaciones')->where('imagen_ruta', $ruta)->exists();
        if (! $usada) {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }

    private function recodificar(string $bytes, string $tipo): string
    {
        $invalida = fn (): ValidationException => ValidationException::withMessages([
            'imagen' => 'La imagen está dañada o no se puede procesar. Sube otra.',
        ]);

        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info['mime'] !== $tipo || $info[0] * $info[1] > self::MAX_PIXELES) {
            throw $invalida();
        }

        $imagen = @imagecreatefromstring($bytes);
        if ($imagen === false) {
            throw $invalida();
        }

        if ($tipo === 'image/jpeg') {
            $imagen = $this->aplicarOrientacion($imagen, $bytes);
        } else {
            imagepalettetotruecolor($imagen);
            imagealphablending($imagen, false);
            imagesavealpha($imagen, true);
        }

        ob_start();
        try {
            $ok = match ($tipo) {
                'image/jpeg' => imagejpeg($imagen, null, self::CALIDAD),
                'image/png' => imagepng($imagen, null, 6),
                default => imagewebp($imagen, null, self::CALIDAD),
            };
        } finally {
            $salida = (string) ob_get_clean();
        }

        if (! $ok || $salida === '') {
            throw $invalida();
        }

        return $salida;
    }

    private function aplicarOrientacion(\GdImage $imagen, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $imagen;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes), 'IFD0');
        $giro = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($giro === 0) {
            return $imagen;
        }

        $girada = imagerotate($imagen, $giro, 0);

        return $girada === false ? $imagen : $girada;
    }
}
