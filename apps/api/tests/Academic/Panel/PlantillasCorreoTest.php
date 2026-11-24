<?php

declare(strict_types=1);

use App\Support\MarcaCorreo;

/*
 * Base compartida de los correos transaccionales (bienvenida de matrícula, certificado, recuperar contraseña): logo, botón y
 * pie. Solo renderiza vistas: no envía nada ni toca la base de datos.
 */

const LOGO_PUBLICO = 'https://api.horus.test.example/assets/images/email/logo-horus-email.png';

function correoBienvenida(): string
{
    return view('emails.matricula-welcome', ['studentName' => 'Ana Torres', 'courseName' => 'Comunicación efectiva'])->render();
}

function correoCertificado(?string $url = 'https://web.example/verificar/HORUS-2026-0001'): string
{
    return view('emails.certificado-emitido', [
        'studentName' => 'Ana', 'courseName' => 'Comunicación efectiva', 'codigo' => 'HORUS-2026-0001', 'urlVerificacion' => $url,
    ])->render();
}

function correoRecuperacion(): string
{
    return view('auth.emails.reset-link', [
        'user' => (object) ['name' => 'Anderson'], 'url' => 'https://web.example/restablecer-contrasena?token=abc',
    ])->render();
}

describe('logo', function (): void {
    it('el archivo ligero existe, es un PNG con transparencia y pesa poco', function (): void {
        $ruta = public_path('assets/images/email/logo-horus-email.png');
        expect(is_file($ruta))->toBeTrue()->and(filesize($ruta))->toBeLessThan(40 * 1024);

        $imagen = imagecreatefrompng($ruta);
        expect($imagen)->not->toBeFalse();
        // La esquina del Ojo de Horus es transparente: sin canal alfa se vería un recuadro negro sobre la cabecera azul.
        expect(imagecolorsforindex($imagen, imagecolorat($imagen, 2, 2))['alpha'])->toBe(127);
    });

    it('sale de APP_URL solo si es una dirección https pública', function (): void {
        config(['app.url' => 'https://api.horus.test.example', 'mail.logo_url' => null]);
        expect(MarcaCorreo::logoUrl())->toBe(LOGO_PUBLICO);

        foreach (['http://localhost', 'https://localhost', 'https://127.0.0.1', 'http://api.horus.example', 'https://horus.test', '', 'sin-url'] as $mala) {
            config(['app.url' => $mala]);
            expect(MarcaCorreo::logoUrl())->toBeNull("APP_URL={$mala}");
        }
    });

    it('MAIL_LOGO_URL manda sobre APP_URL, con las mismas reglas', function (): void {
        config(['app.url' => 'http://localhost', 'mail.logo_url' => 'https://cdn.example/logo.png']);
        expect(MarcaCorreo::logoUrl())->toBe('https://cdn.example/logo.png');

        config(['mail.logo_url' => 'http://cdn.example/logo.png']);
        expect(MarcaCorreo::logoUrl())->toBeNull();
    });

    it('los tres correos muestran el logo cuando hay una dirección pública', function (): void {
        config(['app.url' => 'https://api.horus.test.example', 'mail.logo_url' => null]);

        foreach ([correoBienvenida(), correoCertificado(), correoRecuperacion()] as $html) {
            expect($html)->toContain('<img src="'.LOGO_PUBLICO.'"')->toContain('alt="Horus Group"');
        }
    });

    it('sin dirección pública no hay imagen rota: queda el nombre en texto', function (): void {
        config(['app.url' => 'http://localhost', 'mail.logo_url' => null]);

        foreach ([correoBienvenida(), correoCertificado(), correoRecuperacion()] as $html) {
            expect($html)->not->toContain('<img')->toContain('HORUS GROUP');
        }
    });
});

describe('contenido', function (): void {
    it('la bienvenida conserva sus textos, escapa los datos y el pie trae el contacto', function (): void {
        $html = view('emails.matricula-welcome', ['studentName' => '<b>Ana</b>', 'courseName' => 'Redes & "Seguridad"'])->render();

        expect($html)
            ->toContain('¡Te damos la bienvenida!')
            ->toContain('No constituye una confirmación de pago.')
            ->toContain('Próximos pasos')
            ->toContain('&lt;b&gt;Ana&lt;/b&gt;')->not->toContain('<b>Ana</b>')
            ->toContain('Redes &amp; &quot;Seguridad&quot;')
            ->toContain(MarcaCorreo::TELEFONO)->toContain(MarcaCorreo::CORREO)->toContain(MarcaCorreo::WHATSAPP);
    });

    it('el certificado muestra el código, el botón de verificación y la dirección en texto', function (): void {
        $html = correoCertificado();

        expect($html)
            ->toContain('Tu certificado está listo')
            ->toContain('HORUS-2026-0001')
            ->toContain('href="https://web.example/verificar/HORUS-2026-0001"')
            ->toContain('Verificar certificado');
        // La dirección también va como texto, por si el cliente de correo bloquea el botón.
        expect(substr_count($html, 'https://web.example/verificar/HORUS-2026-0001'))->toBeGreaterThanOrEqual(2);
    });

    it('el certificado sin enlace de verificación no pinta botón ni enlace vacío', function (): void {
        $html = correoCertificado(null);

        expect($html)->toContain('HORUS-2026-0001')->not->toContain('Verificar certificado')->not->toContain('href=""');
    });

    it('la recuperación trae el botón, el aviso de 60 minutos y la dirección en texto', function (): void {
        $html = correoRecuperacion();

        expect($html)
            ->toContain('Hola Anderson')
            ->toContain('expirará en 60 minutos')
            ->toContain('Restablecer contraseña')
            ->toContain('href="https://web.example/restablecer-contrasena?token=abc"')
            ->toContain('Seguridad de acceso administrativo');
        expect(substr_count($html, 'restablecer-contrasena?token=abc'))->toBeGreaterThanOrEqual(2);
    });

    it('ninguno incluye scripts, formularios ni estilos externos (los clientes de correo los bloquean)', function (): void {
        foreach ([correoBienvenida(), correoCertificado(), correoRecuperacion()] as $html) {
            expect(strtolower($html))->not->toContain('<script')->not->toContain('<form')->not->toContain('<link ')->not->toContain('@import');
        }
    });
});
