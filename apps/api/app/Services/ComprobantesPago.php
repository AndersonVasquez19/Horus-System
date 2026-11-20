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

    public const PDF = 'application/pdf';

    /** @var array<string, string> */
    private const EXTENSIONES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', self::PDF => 'pdf'];

    private const PATRON = '#\Acomprobantes/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|png|webp|pdf)\z#D';

    /**
     * Lo que un PDF no puede traer para aceptarse: acciones que ejecutan código o abren otros archivos, archivos incrustados,
     * formularios con envío y cifrado (que impediría revisarlo). Se busca por nombre PDF, ya sin las escapadas `#xx`.
     */
    private const PDF_PROHIBIDO = '#/(?:JS|JavaScript|Launch|EmbeddedFiles?|FileAttachment|RichMedia|XFA|SubmitForm|ImportData|GoToR|GoToE|Encrypt)(?![A-Za-z0-9])#';

    private const PDF_MAX_FLUJOS = 400;

    private const PDF_MAX_DESCOMPRIMIDO = 16 * 1024 * 1024;

    /**
     * @param  bool  $permitirPdf  `false`: solo capturas (JPG, PNG o WebP); `true`: también un PDF (solo comprobantes de pago).
     * @return list<mixed>
     */
    public static function reglas(bool $permitirPdf = false): array
    {
        if (! $permitirPdf) {
            return ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::MAX_KB,
                'dimensions:min_width=100,min_height=100,max_width=8000,max_height=8000'];
        }

        return ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:'.self::MAX_KB,
            // Las dimensiones solo tienen sentido en una imagen; un PDF no se mide.
            function (string $atributo, mixed $valor, \Closure $fallar): void {
                if (! $valor instanceof UploadedFile || $valor->getMimeType() === self::PDF) {
                    return;
                }
                $medidas = @getimagesize((string) $valor->getRealPath());
                if ($medidas === false || $medidas[0] < 100 || $medidas[1] < 100 || $medidas[0] > 8000 || $medidas[1] > 8000) {
                    $fallar('La captura debe medir entre 100 y 8000 píxeles por lado.');
                }
            }];
    }

    public static function rutaValida(?string $ruta): bool
    {
        return $ruta !== null && ! str_contains($ruta, '..') && preg_match(self::PATRON, $ruta) === 1;
    }

    /** @return array{ruta: string, mime: string, tamano: int, sha256: string} */
    public function guardar(UploadedFile $archivo, bool $permitirPdf = false): array
    {
        $tipo = (string) $archivo->getMimeType();
        $extension = self::EXTENSIONES[$tipo] ?? null;
        if ($extension === null || ($tipo === self::PDF && ! $permitirPdf)) {
            throw ValidationException::withMessages(['imagen' => $permitirPdf ? 'Sube una captura en JPG, PNG o WebP, o un PDF.' : 'Sube una captura en JPG, PNG o WebP.']);
        }
        $bytes = (string) file_get_contents((string) $archivo->getRealPath());
        $limpia = $tipo === self::PDF ? $this->validarPdf($bytes) : $this->recodificar($bytes, $tipo);
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

    /**
     * Un PDF no se puede volver a codificar como una imagen, así que se acepta tal cual o se rechaza. La revisión es de MEJOR
     * ESFUERZO, no una garantía: busca acciones y archivos incrustados en lo que no es el cuerpo de un flujo (imágenes, texto) y
     * dentro de los flujos de objetos comprimidos (`/ObjStm`), donde un PDF puede esconderlos. Por eso el PDF solo se sirve como
     * descarga y nunca se muestra en el navegador.
     */
    private function validarPdf(string $bytes): string
    {
        $invalido = fn (string $mensaje): ValidationException => ValidationException::withMessages(['imagen' => $mensaje]);
        if ($bytes === '' || strlen($bytes) > self::MAX_KB * 1024 || ! str_starts_with($bytes, '%PDF-')) {
            throw $invalido('El PDF no es válido o supera los 3 MB.');
        }

        // Todo lo que no es cuerpo de un flujo, más el contenido de cada flujo de objetos descomprimido.
        $aRevisar = '';
        $descomprimido = 0;
        $desde = 0;
        for ($n = 0; $n < self::PDF_MAX_FLUJOS; $n++) {
            $palabra = strpos($bytes, 'stream', $desde);
            if ($palabra === false) {
                break;
            }
            $inicio = $palabra + 6;
            if (($bytes[$inicio] ?? '') === "\r") {
                $inicio++;
            }
            if (($bytes[$inicio] ?? '') === "\n") {
                $inicio++;
            }
            $fin = strpos($bytes, 'endstream', $inicio);
            if ($fin === false) {
                throw $invalido('El PDF está dañado o no se puede revisar.');
            }
            $aRevisar .= substr($bytes, $desde, $palabra - $desde);

            $previo = substr($bytes, max(0, $palabra - 512), min(512, $palabra));
            $objeto = strrpos($previo, 'obj');
            if (str_contains($objeto === false ? $previo : substr($previo, $objeto), '/ObjStm')) {
                $plano = @zlib_decode(substr($bytes, $inicio, $fin - $inicio), self::PDF_MAX_DESCOMPRIMIDO);
                $descomprimido += is_string($plano) ? strlen($plano) : 0;
                if (! is_string($plano) || $descomprimido > self::PDF_MAX_DESCOMPRIMIDO) {
                    throw $invalido('El PDF usa un formato que no se puede revisar. Sube una captura o un PDF simple.');
                }
                $aRevisar .= "\n".$plano;
            }
            $desde = $fin + 9;
        }
        if (strpos($bytes, 'stream', $desde) !== false) {
            throw $invalido('El PDF tiene demasiadas partes para revisarlo. Sube una captura o un PDF simple.');
        }
        $aRevisar .= substr($bytes, $desde);

        // Un nombre PDF puede escribirse con `#xx` (p. ej. `/J#53`): se normaliza antes de buscar.
        $normalizado = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $aRevisar);
        if ($normalizado === null || preg_match(self::PDF_PROHIBIDO, $normalizado) !== 0) {
            throw $invalido('El PDF contiene elementos no permitidos (scripts, archivos incrustados o cifrado). Sube una captura o un PDF simple.');
        }

        return $bytes;
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
