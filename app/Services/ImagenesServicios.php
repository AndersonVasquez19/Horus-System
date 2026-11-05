<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imágenes de servicios: solo Laravel Storage (disco local «public»). En la base se guardan
 * únicamente rutas relativas generadas por el servidor, nunca valores enviados por el usuario.
 *
 * Dos orígenes válidos:
 *  - `assets/images/...`: imágenes estáticas versionadas en public/ (contenido inicial). Nunca se borran.
 *  - `servicios/<uuid>.<ext>`: cargas del panel en storage/app/public. Se borran solo si nadie las referencia.
 */
class ImagenesServicios
{
    public const DISCO = 'public';

    public const MAX_KB = 3072;

    /** @var array<string, string> */
    private const EXTENSIONES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PATRON = '#\A(?:assets/images/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/[A-Za-z0-9_.-]+|servicios/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|png|webp))\z#D';

    /** @return list<string> */
    public static function reglas(bool $requerida): array
    {
        return [$requerida ? 'required' : 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.self::MAX_KB, 'dimensions:min_width=100,min_height=100,max_width=8000,max_height=8000'];
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
        if (str_starts_with($ruta, 'assets/')) {
            return asset($ruta);
        }

        return Storage::disk(self::DISCO)->url($ruta);
    }

    /** Guarda con nombre generado por el servidor; el tipo se toma del contenido real, no del nombre del cliente. */
    public function guardar(UploadedFile $archivo): string
    {
        $extension = self::EXTENSIONES[(string) $archivo->getMimeType()] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Formato de imagen no permitido.');
        }
        $ruta = Storage::disk(self::DISCO)->putFileAs('servicios', $archivo, Str::uuid()->toString().'.'.$extension);
        if (! is_string($ruta) || ! self::rutaValida($ruta)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }

        return $ruta;
    }

    /** Borra solo cargas del panel que ya no referencia ninguna ficha ni tarjeta. */
    public function liberarSiHuerfana(?string $ruta): void
    {
        if ($ruta === null || ! self::rutaValida($ruta) || ! str_starts_with($ruta, 'servicios/')) {
            return;
        }
        $usada = DB::table('servicio_publicaciones')->where('imagen_principal_ruta', $ruta)->exists()
            || DB::table('servicio_publicacion_elementos')->where('imagen_ruta', $ruta)->exists();
        if (! $usada) {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }
}
