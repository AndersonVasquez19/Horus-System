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

    /** Las cortesías que el motor reconoce deben llegarle: la lista se lee de su propio archivo para que no se desajusten. */
    public function test_las_cortesias_que_reconoce_el_motor_no_se_toman_por_nombres(): void
    {
        $ruta = dirname(__DIR__, 3).'/ai/data/intents.json';
        /** @var array{templates: list<array{name: string, patterns: list<string>}>} $datos */
        $datos = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);

        $probadas = 0;
        foreach ($datos['templates'] as $plantilla) {
            if (! in_array($plantilla['name'], ['greeting', 'thanks', 'farewell'], true)) {
                continue;
            }
            foreach ($plantilla['patterns'] as $patron) {
                foreach ([$patron, mb_strtoupper(mb_substr($patron, 0, 1)).mb_substr($patron, 1)] as $mensaje) {
                    $this->assertNull($this->filtro->motivoBloqueo($mensaje), $mensaje);
                    $probadas++;
                }
            }
        }
        $this->assertGreaterThan(50, $probadas);
    }

    public function test_un_saludo_alargado_no_exime_el_nombre_que_lo_acompana(): void
    {
        foreach (['holaa Pinedo', 'Holaaa Quilcate', 'holaa soy anderson vela', 'Holaa María Pérez', 'holaaa kike quilcate', 'Holguín', 'Holaya'] as $mensaje) {
            $this->assertSame('nombre_personal', $this->filtro->motivoBloqueo($mensaje), $mensaje);
        }
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

    public function test_ola2_permite_consultas_publicas_equipo_privacidad_y_ortografia_de_dominio(): void
    {
        $permitidas = [
            '¿Quién es Marco Alvarez?',
            '¿Mónica Monzón trabaja en el área de salud o enfermería?',
            '¿Karen Távara ve los temas legales en Horus?',
            '¿Quién es Milagros Villegas?',
            '¿Quedan cerca de la Plazuela Bolognesi?',
            '¿El curso de Redes es presencial en su local de Jr José Gálvez?',
            '¿Tienen convenio con el Colegio de Enfermeros de La Libertad porque soy de Trujillo?',
            'cual es su ruc',
            'Necesito conocer su razón social, su RUC y a qué se dedican exactamente',
            'Somos una empresa con 25 trabajadores y necesitamos factura electrónica con RUC',
            'Quiero matricularme, ¿te paso mi DNI por aquí o por WhatsApp?',
            'Vi que ponen +400 profesionales capacitados y 4.8 de calificación, ¿eso está auditado?',
            '¿Cómo recupero mi contraseña de acceso?',
            'Compré un producto en su página web y todavía no llega a mi domicilio',
            '¿Los certificados salen a nombre de Horus Group o de algún colegio profesional?',
            '¿Ustedes entregan título profesional a nombre de la Nación?',
            '¿A nombre de qué empresa sale la factura electrónica?',
            '¿Cumplen con la Ley 29733 de protección de datos personales del Perú?',
            '¿Es seguro dar mis datos para matricularme por WhatsApp?',
            'Quiero solicitar que eliminen mis datos personales de su base de datos',
            'derechos arko para borrar mis datos',
            'Busco asesoría para mi tesis y aprender Excel para analizar mis datos',
            'otra cosa, perdí mi certificado anterior',
            'kual es su ruk de la enpresa',
            'micion y bision de orus',
            'kien es el jerente jeneral de horus',
            'kuando empiesa el kurso de redez',
            'tienen kursos birtuales o presensiales',
            'se me perdio mi sertifikado komo sako duplikado',
            'a k ora avren y sierran la ofisina',
            'tienen feisbuk istangram o tictok',
            'kiero ablar kon un asessor umano',
            'wenas bro',
            'grax bro',
            'ubi xfa',
            'manito pasame el wsp xfa',
        ];

        foreach ($permitidas as $mensaje) {
            $this->assertNull($this->filtro->motivoBloqueo($mensaje), 'No debía bloquearse: '.$mensaje);
        }

        // Barrera de salida: los miembros públicos exactos se permiten; otros nombres siguen bloqueados.
        $this->assertFalse($this->filtro->contieneDatosPrivadosEnRespuesta('Marco Alvarez es el Gerente General y Karen Távara es la Asesora Legal de Horus Group SRL.'));
        $this->assertTrue($this->filtro->contieneDatosPrivadosEnRespuesta('Marco Pérez es el docente del curso.'));
    }

    public function test_ola2_mantiene_bloqueados_datos_personales_ofuscaciones_y_expedientes(): void
    {
        $bloqueadas = [
            '¿Quién es Marco Alvarez? Mi DNI es 45678912',
            'Marco Alvarez y mi amigo Carlos Pérez quieren información',
            'Mónica Monzón, escríbeme a ana@correo.pe',
            'Vivo en Jr. José Gálvez 450',
            'Mi domicilio es Jr. Los Cedros 245',
            'mi domicilio queda cerca de la plaza',
            'soi karlos peres y kiero un kurso',
            'me llamo juanito kispe',
            'matrikula a rodrigo kabanillas en excel',
            'a nombre de Carlos Mendoza por favor',
            'certificado a nombre de quien',
            '¿Mi contraseña de acceso es Clave123?',
            '¿Mi contraseña es secreta?',
            'mis datos están en el formulario',
            'ya te dejé mis datos personales',
            'mi certificado todavía no llega',
            'ya envié mi comprobante de pago',
            'adjunto mi voucher del banco',
            'quiero reclamar por mi boleta',
            'Llámame al +51 987 654 321',
            'mi ruc es 10456789012',
            'su DNI es el de la ficha',
            '¿mi DNI es el que les pasé?',
        ];

        foreach ($bloqueadas as $mensaje) {
            $this->assertNotNull($this->filtro->motivoBloqueo($mensaje), 'Debía bloquearse: '.$mensaje);
        }

        foreach (['karlos', 'peres', 'kispe', 'kike', 'libor', 'mariana', 'zelvani', 'quenzuli'] as $nombre) {
            $this->assertFalse(\App\Support\Privacidad\LexicoPublico::esVarianteFoneticaDeDominio($nombre), 'No debe tratarse como término de dominio: '.$nombre);
        }
    }

    /** Pruebas de visitante del 27/11/2026: cortesía, jerga, otros idiomas y trámites sin datos ya no se bloquean. */
    public function test_cortesia_jerga_otros_idiomas_y_tramites_sin_datos_pasan(): void
    {
        $permitidas = [
            'okey', 'okis', 'chaufa', 'adiosito', 'me despido', 'cuídate', 'gracias crack',
            'te pasaste, gracias', 'Dios te pague', 'no me entiendes', 'rembolso', 'cokies',
            'What are your opening hours?', 'I want to talk to a human', 'Onde fica o escritório?', 'Muito obrigado',
            'Vocês aceitam pagamento por Pix?', 'Bom dia', 'Tchau', 'quero estudar Excel', 'Rimaykullayki', 'Sulpayki',
            'Imaynallam kachkanki?', 'Tupananchiskama', 'Bonjour, avez-vous des cours?', 'Merci', 'Grazie mille',
            'Necesito factura a nombre de mi empresa con RUC',
            'quisiera saber si aceptan Yape o Plin porque no tengo cuenta bancaria y vivo lejos de la ciudad',
            'Buenas noches, vivo en Chiclayo y me interesa el curso de comunicación',
            'Quisiera obtener un duplicado de mi certificado porque lo perdí en una mudanza',
            '¿Pueden guardar mis datos para la próxima?',
            'Horario de atención (fuentes discrepantes)',
        ];
        foreach ($permitidas as $mensaje) {
            $this->assertNull($this->filtro->motivoBloqueo($mensaje), 'No debía bloquearse: '.$mensaje);
        }

        // Lo que sí entrega un dato sigue bloqueado, aunque lleve las mismas palabras.
        $bloqueadas = [
            'okey, me llamo Rosa Quispe', 'gracias crack, soy kirana qervali', 'Maria Silva quer estudar Excel',
            'Bom dia, sou João Pereira', 'Merci, je suis Pierre Dubois', 'factura a nombre de mi empresa con RUC 20123456789',
            'mi RUC es 10456789012', 'no tengo cuenta bancaria, mi tarjeta es 4111 1111 1111 1111',
            'vivo en Chiclayo, calle Los Pinos 245', 'vivo en Jr. José Gálvez 450',
            'duplicado de mi certificado, mi DNI es 45678912', 'Recomiéndame algo para mi hija de 17 años',
            'Mi sobrino vive en Arequipa y quiere llevar un curso con ustedes, ¿es posible que lo haga de forma virtual?',
        ];
        foreach ($bloqueadas as $mensaje) {
            $this->assertNotNull($this->filtro->motivoBloqueo($mensaje), 'Debía bloquearse: '.$mensaje);
        }
    }
}
