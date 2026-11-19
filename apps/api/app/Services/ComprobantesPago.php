<?php

declare(strict_types=1);

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Capturas de comprobante de pago (Yape). Almacenamiento PRIVADO (disco `local`, fuera de `public/`): solo se sirven por el endpoint
 * autenticado con `pagos.ver`. El tipo se toma del contenido real (no del nombre del cliente), el nombre lo genera el servidor y la
 * imagen se DECODIFICA Y VUELVE A CODIFICAR (GD): el archivo guardado no conserva EXIF/GPS ni bytes añadidos tras el formato.
 */
class ComprobantesPago
{
    public const DISCO = 'local';

    public const MAX_KB = 3072;

    private const MAX_PIXELES = 25000000;

    private const CALIDAD = 85;

    /** @var array<string, string> */
    private const EXTENSIONES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PATRON = '#\Acomprobantes/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|png|webp)\z#D';

    /** @return list<string> */
    public static function reglas(): array
    {
        return ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::MAX_KB,
            'dimensions:min_width=100,min_height=100,max_width=8000,max_height=8000'];
    }

    public static function rutaValida(?string $ruta): bool
    {
        return $ruta !== null && ! str_contains($ruta, '..') && preg_match(self::PATRON, $ruta) === 1;
    }

    /** @return array{ruta: string, mime: string, tamano: int, sha256: string} */
    public function guardar(UploadedFile $archivo): array
    {
        $tipo = (string) $archivo->getMimeType();
        $extension = self::EXTENSIONES[$tipo] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages(['imagen' => 'Sube una captura en JPG, PNG o WebP.']);
        }
        $limpia = $this->recodificar((string) file_get_contents((string) $archivo->getRealPath()), $tipo);
        $ruta = 'comprobantes/'.Str::uuid()->toString().'.'.$extension;
        if (! self::rutaValida($ruta) || ! Storage::disk(self::DISCO)->put($ruta, $limpia)) {
            throw new RuntimeException('No se pudo guardar el comprobante.');
        }

        return ['ruta' => $ruta, 'mime' => $tipo, 'tamano' => strlen($limpia), 'sha256' => hash('sha256', $limpia)];
    }

    /** @return array{bytes: string, tipo: string}|null */
    public function contenido(?string $ruta): ?array
    {
        if (! self::rutaValida($ruta) || ! Storage::disk(self::DISCO)->exists((string) $ruta)) {
            return null;
        }
        $bytes = Storage::disk(self::DISCO)->get((string) $ruta);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }
        $tipo = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return isset(self::EXTENSIONES[$tipo]) ? ['bytes' => $bytes, 'tipo' => $tipo] : null;
    }

    public function borrar(?string $ruta): void
    {
        if (self::rutaValida($ruta)) {
            Storage::disk(self::DISCO)->delete((string) $ruta);
        }
    }

    private function recodificar(string $bytes, string $tipo): string
    {
        $invalida = fn (): ValidationException => ValidationException::withMessages(['imagen' => 'La imagen está dañada o no se puede procesar. Sube otra captura.']);
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info['mime'] !== $tipo || $info[0] * $info[1] > self::MAX_PIXELES) {
            throw $invalida();
        }
        $imagen = @imagecreatefromstring($bytes);
        if ($imagen === false) {
            throw $invalida();
        }
        if ($tipo !== 'image/jpeg') {
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
}
