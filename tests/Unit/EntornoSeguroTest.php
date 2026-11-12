<?php

declare(strict_types=1);

namespace Tests\Unit;

use Horus\Local\EntornoSeguro;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/scripts/Support/EntornoSeguro.php';

/**
 * El entorno defensivo no depende de la red, de la base de datos ni de los archivos de entorno: solo de variables
 * de proceso. Las pruebas con procesos reales usan valores ficticios.
 */
class EntornoSeguroTest extends TestCase
{
    private const ESPERADO_SIEMPRE = ['OPENAI_API_KEY' => ''];

    private const ESPERADO_GEMINI = ['GEMINI_API_KEY' => ''];

    private const ESPERADO_CORREO = [
        'MAIL_MAILER' => 'array',
        'MAIL_HOST' => '127.0.0.1',
        'MAIL_PORT' => '9',
        'MAIL_USERNAME' => '',
        'MAIL_PASSWORD' => '',
        'RESEND_API_KEY' => '',
        'MATRICULA_MAIL_ENABLED' => 'false',
        'RECLAMACIONES_CORREO_ENABLED' => 'false',
        'REGISTRO_VERIFICACION_ENABLED' => 'false',
    ];

    public function test_el_entorno_neutralizado_cubre_cada_familia_y_las_claves_siempre_cerradas(): void
    {
        $this->assertSame(
            self::ESPERADO_SIEMPRE + self::ESPERADO_GEMINI + self::ESPERADO_CORREO,
            EntornoSeguro::neutralizado()
        );
    }

    public function test_las_familias_permitibles_son_solo_gemini_y_correo(): void
    {
        $this->assertSame(['gemini', 'correo'], EntornoSeguro::familias());
    }

    public function test_permitir_gemini_retira_solo_gemini(): void
    {
        $entorno = EntornoSeguro::permitiendo(['gemini']);

        $this->assertArrayNotHasKey('GEMINI_API_KEY', $entorno);
        $this->assertSame(self::ESPERADO_SIEMPRE + self::ESPERADO_CORREO, $entorno);
    }

    public function test_permitir_correo_retira_solo_el_correo(): void
    {
        $entorno = EntornoSeguro::permitiendo(['correo']);

        $this->assertSame(self::ESPERADO_SIEMPRE + self::ESPERADO_GEMINI, $entorno);
        foreach (array_keys(self::ESPERADO_CORREO) as $clave) {
            $this->assertArrayNotHasKey($clave, $entorno);
        }
    }

    public function test_openai_no_se_puede_permitir_ni_como_familia_ni_por_ninguna_combinacion(): void
    {
        $this->assertSame(
            self::ESPERADO_SIEMPRE,
            EntornoSeguro::permitiendo(['gemini', 'correo'])
        );

        $this->expectException(InvalidArgumentException::class);
        EntornoSeguro::permitiendo(['openai']);
    }

    public function test_una_familia_desconocida_se_rechaza_con_las_permitidas_en_el_mensaje(): void
    {
        try {
            EntornoSeguro::permitiendo(['gemini', 'resend']);
            $this->fail('Debía rechazar una familia desconocida.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('resend', $error->getMessage());
            $this->assertStringContainsString('gemini, correo', $error->getMessage());
        }
    }

    public function test_el_bloqueo_de_red_envia_todo_a_un_puerto_sin_servicio_y_no_exime_ningun_destino(): void
    {
        $bloqueo = EntornoSeguro::bloqueoDeRed();
        $muerto = 'http://127.0.0.1:'.EntornoSeguro::PUERTO_SIN_SERVICIO;

        foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'] as $clave) {
            $this->assertSame($muerto, $bloqueo[$clave]);
        }
        $this->assertSame('', $bloqueo['NO_PROXY']);
        $this->assertSame('', $bloqueo['no_proxy']);
    }

    public function test_el_entorno_neutralizado_no_incluye_proxies_para_no_romper_el_loopback_de_los_servicios(): void
    {
        $this->assertSame([], array_intersect_key(EntornoSeguro::neutralizado(), EntornoSeguro::bloqueoDeRed()));
    }

    public function test_el_ejecutor_entrega_claves_vacias_aunque_el_proceso_padre_las_tenga(): void
    {
        $proceso = new Process(
            [PHP_BINARY, 'scripts/entorno-seguro.php', 'ejecutar', '--', PHP_BINARY, '-r', $this->codigoQueImprimeLasClaves()],
            dirname(__DIR__, 2),
            ['GEMINI_API_KEY' => 'clave-ficticia-padre', 'RESEND_API_KEY' => 'resend-ficticia-padre', 'OPENAI_API_KEY' => 'openai-ficticia-padre',
                'MATRICULA_MAIL_ENABLED' => 'true', 'MAIL_MAILER' => 'smtp']
        );
        $proceso->run();

        $this->assertSame(0, $proceso->getExitCode(), $proceso->getErrorOutput());
        $this->assertSame(
            ['GEMINI_API_KEY' => '', 'RESEND_API_KEY' => '', 'OPENAI_API_KEY' => '', 'MATRICULA_MAIL_ENABLED' => 'false', 'MAIL_MAILER' => 'array'],
            json_decode($proceso->getOutput(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function test_el_ejecutor_con_permitir_deja_pasar_solo_la_familia_pedida(): void
    {
        $proceso = new Process(
            [PHP_BINARY, 'scripts/entorno-seguro.php', 'ejecutar', '--permitir=gemini', '--', PHP_BINARY, '-r', $this->codigoQueImprimeLasClaves()],
            dirname(__DIR__, 2),
            ['GEMINI_API_KEY' => 'clave-ficticia-padre', 'RESEND_API_KEY' => 'resend-ficticia-padre']
        );
        $proceso->run();

        $this->assertSame(0, $proceso->getExitCode(), $proceso->getErrorOutput());
        $resultado = json_decode($proceso->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('clave-ficticia-padre', $resultado['GEMINI_API_KEY']);
        $this->assertSame('', $resultado['RESEND_API_KEY']);
    }

    public function test_mostrar_no_imprime_ningun_valor_heredado_del_proceso_padre(): void
    {
        $proceso = new Process(
            [PHP_BINARY, 'scripts/entorno-seguro.php', 'mostrar'],
            dirname(__DIR__, 2),
            ['GEMINI_API_KEY' => 'clave-ficticia-padre', 'RESEND_API_KEY' => 'resend-ficticia-padre']
        );
        $proceso->run();

        $this->assertSame(0, $proceso->getExitCode());
        $this->assertStringContainsString('GEMINI_API_KEY = <vacía>', $proceso->getOutput());
        $this->assertStringNotContainsString('ficticia', $proceso->getOutput());
    }

    public function test_el_ejecutor_rechaza_una_familia_desconocida_sin_ejecutar_nada(): void
    {
        $proceso = new Process(
            [PHP_BINARY, 'scripts/entorno-seguro.php', 'ejecutar', '--permitir=openai', '--', PHP_BINARY, '-r', 'echo "no debe ejecutarse";'],
            dirname(__DIR__, 2)
        );
        $proceso->run();

        $this->assertSame(2, $proceso->getExitCode());
        $this->assertStringNotContainsString('no debe ejecutarse', $proceso->getOutput());
    }

    public function test_los_scripts_no_acceden_a_archivos_de_entorno(): void
    {
        foreach (['scripts/Support/EntornoSeguro.php', 'scripts/entorno-seguro.php'] as $ruta) {
            $codigo = (string) file_get_contents(dirname(__DIR__, 2).'/'.$ruta);
            $this->assertStringNotContainsString('Dotenv', $codigo, $ruta);
            $this->assertStringNotContainsString('file_get_contents', $codigo, $ruta);
            $this->assertStringNotContainsString('file_put_contents', $codigo, $ruta);
            $this->assertStringNotContainsString('parse_ini', $codigo, $ruta);
        }
    }

    private function codigoQueImprimeLasClaves(): string
    {
        return 'echo json_encode(array_map(static fn (string $k): string|false => getenv($k), '
            .'array_combine(["GEMINI_API_KEY","RESEND_API_KEY","OPENAI_API_KEY","MATRICULA_MAIL_ENABLED","MAIL_MAILER"], '
            .'["GEMINI_API_KEY","RESEND_API_KEY","OPENAI_API_KEY","MATRICULA_MAIL_ENABLED","MAIL_MAILER"])));';
    }
}
