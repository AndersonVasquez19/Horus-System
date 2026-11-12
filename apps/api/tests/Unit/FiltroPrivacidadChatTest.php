<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FiltroPrivacidadChat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FiltroPrivacidadChatTest extends TestCase
{
    public function test_consultas_legitimas_de_la_auditoria_no_son_datos_personales(): void
    {
        foreach (['¿Cómo hago mi matrícula?', 'Quiero información de mi inscripción', '¿En qué calle están?', '¿Atienden en la Av. principal?', '¿Aceptan pago con BCP?', 'Buenos Días', '¿Tienen cursos de Microsoft Excel?', '¿Hay cursos en 2026?', 'Necesito mantenimiento de 1500 equipos', '¿Dan certificado con token de verificación?'] as $mensaje) {
            $this->assertNull((new FiltroPrivacidadChat)->motivoBloqueo($mensaje), $mensaje);
        }
    }

    private FiltroPrivacidadChat $filtro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filtro = new FiltroPrivacidadChat;
    }

    public static function mensajesConDatosPersonales(): iterable
    {
        yield 'correo estandar' => ['Orientame sobre cursos, mi correo es maria.perez@gmail.com', 'correo'];
        yield 'correo ofuscado con arroba' => ['Escribeme a juan_carlos [arroba] hotmail punto com', 'correo'];
        yield 'telefono movil continuo' => ['Orientame sobre cursos al 987654321', 'telefono'];
        yield 'telefono con espacios' => ['Mi celular es 987 654 321 para información', 'telefono'];
        yield 'telefono con guiones' => ['Comunicate al 987-654-321 por favor', 'telefono'];
        yield 'telefono con prefijo internacional' => ['Llámame al +51 987 654 321 sobre el curso', 'telefono'];
        yield 'telefono fijo con codigo' => ['Mi teléfono fijo es (076) 36-12-34', 'telefono'];
        yield 'dni de ocho digitos' => ['Quiero cursos, mi DNI es 45879632', 'documento'];
        yield 'dni con espacios y guiones' => ['Documento 45 879 632 para inscripción', 'documento'];
        yield 'ruc personal 10 con guiones' => ['Mi RUC personal es 10-45879632-1', 'documento'];
        yield 'ruc 20 con espacios' => ['RUC 20 55667788 9 para facturación personal', 'documento'];
        yield 'pasaporte alfanumerico' => ['Mi pasaporte es PE1234567 y busco cursos', 'documento'];
        yield 'carnet de extranjeria' => ['Tengo carnet de extranjería 001234567', 'documento'];
        yield 'nombre completo con tildes' => ['Orientame sobre cursos para María Pérez', 'nombre_personal'];
        yield 'nombre completo con eñe y diéresis' => ['Consulta de José Ñáñez Agüero sobre redes', 'nombre_personal'];
        yield 'nombre compuesto con guion' => ['Orientame sobre cursos para Claudia Rodríguez-Sánchez', 'nombre_personal'];
        yield 'nombre en minusculas' => ['orientame sobre cursos para juan carlos gomez', 'nombre_personal'];
        yield 'presentacion personal me llamo' => ['Hola me llamo Raúl y busco cursos', 'nombre_personal'];
        yield 'direccion con jiron' => ['Vivo en Jr. Amazonas 450 y quiero internet', 'direccion'];
        yield 'direccion con avenida y departamento' => ['Mi dirección es Av. Los Héroes 123 Dpto 301', 'direccion'];
        yield 'direccion con manzana y lote' => ['Instalación en Mz B Lote 14 Urb. El Ingenio', 'direccion'];
        yield 'fecha de nacimiento con barras' => ['Nací el 15/04/1995 y quiero estudiar redes', 'fecha_nacimiento'];
        yield 'fecha de nacimiento con guiones' => ['Mi fecha de nacimiento es 15-04-1995', 'fecha_nacimiento'];
        yield 'fecha de nacimiento textual' => ['Nací el 3 de noviembre de 1998, qué curso recomiendas', 'fecha_nacimiento'];
        yield 'contrasena explicita' => ['Mi contraseña es ClaveSecreta2026!', 'credencial'];
        yield 'token de api' => ['Usa este token sk-proj1234567890abcdef para consultar', 'credencial'];
        yield 'clave compleja' => ['Intenté ingresar con P@ssw0rd123! al sistema', 'credencial'];
        yield 'cuenta bancaria cci' => ['Mi CCI es 002-191-123456789012-34 para devolución', 'bancario'];
        yield 'tarjeta con guiones' => ['Pagué con mi tarjeta 4557-8800-1234-5678', 'bancario'];
        yield 'mensaje ambiguo con iniciales' => ['Orientame sobre cursos para M. P. por favor', 'ambiguo'];
        yield 'mensaje ambiguo de expediente privado' => ['Orientame sobre el estado de mi cotización y mis datos', 'ambiguo'];
        yield 'mensaje ambiguo con identificador' => ['Consulta del usuario cliente_horus sobre cursos', 'ambiguo'];
    }

    #[DataProvider('mensajesConDatosPersonales')]
    public function test_detecta_y_bloquea_datos_personales_y_casos_dudosos(string $mensaje, string $categoriaEsperada): void
    {
        $this->assertTrue($this->filtro->contieneDatosPersonales($mensaje));
        $this->assertFalse($this->filtro->esSeguro($mensaje));
        $this->assertSame($categoriaEsperada, $this->filtro->motivoBloqueo($mensaje));
    }

    public function test_rechaza_mensajes_vacios_excesivos_o_con_caracteres_de_control(): void
    {
        $this->assertSame('vacio_o_excesivo', $this->filtro->motivoBloqueo('   '));
        $this->assertSame('vacio_o_excesivo', $this->filtro->motivoBloqueo(str_repeat('a', 2001)));
        $this->assertSame('vacio_o_excesivo', $this->filtro->motivoBloqueo("Orientame\x00sobre cursos"));
    }

    public static function mensajesInstitucionalesSeguros(): iterable
    {
        yield 'orientacion cursos' => ['Oriéntame sobre los cursos y capacitaciones de Horus Group'];
        yield 'comparacion modalidades' => ['Compara las modalidades presencial, semipresencial y virtual de los cursos'];
        yield 'orientacion camaras' => ['Oriéntame sobre el servicio de cámaras de seguridad para una oficina'];
        yield 'orientacion cableado' => ['Compara cableado estructurado y redes LAN para orientarme'];
        yield 'orientacion soporte' => ['Ayúdame a elegir un servicio de soporte y mantenimiento de computadoras'];
        yield 'consulta con tildes y eñe' => ['¿Qué enseñanza ofrecen en diseño y configuración de redes en Cajamarca?'];
        yield 'curso python' => ['Compara el curso de Python inicial y las capacitaciones empresariales'];
    }

    #[DataProvider('mensajesInstitucionalesSeguros')]
    public function test_permite_mensajes_institucionales_sin_informacion_personal(string $mensaje): void
    {
        $this->assertFalse($this->filtro->contieneDatosPersonales($mensaje));
        $this->assertTrue($this->filtro->esSeguro($mensaje));
        $this->assertNull($this->filtro->motivoBloqueo($mensaje));
    }
}
