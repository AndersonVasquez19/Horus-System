<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Privacidad\LexicoPublico;

/**
 * Filtro conservador de privacidad para mensajes dirigidos a Horus AI Advisor.
 *
 * Evalúa localmente el texto del visitante antes de transmitirlo al servicio Python.
 * Si detecta datos personales, credenciales, identificadores sensibles o coincidencias
 * dudosas, bloquea el mensaje completo (nunca recorta ni transmite fragmentos parciales).
 *
 * Detección de nombres de personas (entrada del visitante), por capas:
 * 1. Anclas explícitas («me llamo», «mi nombre es», «a nombre de»…).
 * 2. Nombres y apellidos frecuentes adyacentes entre sí o tras una preposición personal.
 * 3. Contexto sin mayúsculas: presentación, matrícula/inscripción de terceros, relación, atención.
 * 4. COMPLEMENTO DEL LÉXICO: dos o más palabras consecutivas que el vocabulario público
 *    (Privacidad\LexicoPublico) no explica forman una secuencia con forma de nombre, sin importar
 *    mayúsculas, escritura (latina, CJK, cirílica, árabe) ni contexto. Es la capa que no depende
 *    de listas de nombres: un nombre nunca visto sigue siendo «desconocido».
 *
 * Limitaciones documentadas:
 * - Ningún filtro léxico garantiza detección absoluta. Quedan fuera: un nombre de pila suelto y
 *   desconocido en minúsculas dentro de una frase («hola kirana»), nombres cuyas dos palabras
 *   coincidan con vocabulario común, y datos codificados de forma no convencional.
 * - Falsos positivos esperables: dos palabras legítimas poco frecuentes seguidas (el léxico no
 *   cubre todo el español); se prefiere bloquear y pedir que se reformule.
 * - Mitigación adicional: bloqueo preventivo ante patrones ambiguos (iniciales personales,
 *   cuatro o más dígitos, referencias a expedientes o datos propios, pares clave-valor,
 *   identificadores con guiones bajos y enlaces).
 * - No registra ni devuelve el texto original ni el fragmento detectado.
 */
final class FiltroPrivacidadChat
{
    /** @var list<string> */
    private const TERMINOS_INSTITUCIONALES = [
        'horus', 'group', 'srl', 'sac', 'eirl', 'isam', 'peru', 'cajamarca',
        'curso', 'cursos', 'capacitacion', 'capacitaciones', 'formacion', 'programa', 'programas',
        'servicio', 'servicios', 'tecnologia', 'tecnologias', 'soluciones', 'tecnologicas',
        'modalidad', 'modalidades', 'presencial', 'semipresencial', 'virtual', 'online', 'hibrido',
        'camara', 'camaras', 'seguridad', 'electronica', 'videovigilancia', 'cctv',
        'cableado', 'estructurado', 'red', 'redes', 'lan', 'utp', 'stp', 'wifi',
        'soporte', 'mantenimiento', 'reparacion', 'tecnico', 'preventivo', 'correctivo',
        'asesoramiento', 'asesoria', 'convenio', 'convenios', 'certificado', 'certificados',
        'certificacion', 'certificaciones', 'python', 'excel', 'word', 'office', 'windows',
        'microsoft', 'buenos', 'dias', 'buenas', 'tardes', 'noches',
        'linux', 'cisco', 'mikrotik', 'robotica', 'inicial', 'basico', 'intermedio', 'avanzado',
        'empresarial', 'personalizada', 'disponibles', 'adaptables', 'horario', 'horarios',
        'atencion', 'contacto', 'contactos', 'orientame', 'compara', 'comparar', 'ayudame',
        'elegir', 'informacion', 'consulta', 'requisitos', 'inscripcion', 'matricula',
        'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'sabados', 'domingo',
    ];

    /**
     * Palabras que se escriben con mayúscula (inicio de frase, productos, lugares o
     * instituciones) y no son nombres personales. Normalizadas, sin tildes.
     *
     * @var list<string>
     */
    private const PALABRAS_NO_PERSONALES = [
        'que', 'cual', 'cuales', 'cuanto', 'cuanta', 'cuantos', 'cuantas', 'como', 'donde', 'cuando', 'por', 'para',
        'hola', 'gracias', 'tienen', 'tiene', 'tengo', 'hay', 'hacen', 'hace', 'dan', 'dictan', 'ofrecen', 'brindan',
        'ensenan', 'quiero', 'quisiera', 'necesito', 'busco', 'deseo', 'puedo', 'pueden', 'podrian', 'instalan',
        'atienden', 'venden', 'reparan', 'arreglan', 'configuran', 'informes', 'info', 'ayuda', 'el', 'la', 'los',
        'las', 'un', 'una', 'en', 'con', 'sin', 'taller', 'talleres', 'clases', 'diplomado', 'diplomados',
        'power', 'point', 'powerpoint', 'bi', 'google', 'sheets', 'drive', 'docs', 'gmail', 'base', 'bases', 'datos',
        'access', 'outlook', 'teams', 'zoom', 'autocad', 'photoshop', 'illustrator', 'java', 'javascript', 'php',
        'sql', 'html', 'css', 'android', 'apple', 'mac', 'hp', 'dell', 'lenovo', 'asus', 'acer', 'epson', 'canon',
        'hikvision', 'dahua', 'ubiquiti', 'router', 'switch', 'fibra', 'optica', 'internet', 'data', 'science',
        'machine', 'learning', 'inteligencia', 'artificial', 'ofimatica', 'basica', 'digital', 'marketing', 'diseno',
        'grafico', 'programacion', 'contabilidad', 'administracion', 'gestion', 'proyectos', 'project', 'sap',
        'banos', 'inca', 'cajabamba', 'celendin', 'chota', 'cutervo', 'jaen', 'contumaza', 'hualgayoc', 'bambamarca',
        'namora', 'llacanora', 'encanada', 'magdalena', 'asuncion', 'otuzco', 'lima', 'trujillo', 'chiclayo',
        'piura', 'libertad', 'universidad', 'nacional', 'privada', 'instituto', 'colegio', 'escuela',
        'municipalidad', 'gobierno', 'regional', 'ministerio', 'sunat', 'reniec', 'sunedu', 'minedu', 'essalud',
        'wi', 'fi', 'facebook', 'instagram', 'tiktok', 'youtube', 'whatsapp', 'yape', 'plin', 'bcp', 'interbank', 'bbva',
        'libro', 'reclamaciones', 'ciencia', 'ciencias', 'administrativas', 'economica', 'economicas', 'aceptan',
        'economistas', 'licenciados', 'enfermeros', 'trabajan',
    ];

    /**
     * Vocabulario común (normalizado, sin tildes) que nunca forma parte de un nombre
     * detectado por contexto en minúsculas. Un nombre exige dos o más palabras seguidas
     * fuera de este vocabulario, o presentes en las listas de nombres y apellidos.
     *
     * @var list<string>
     */
    private const VOCABULARIO_COMUN = [
        'a', 'al', 'el', 'la', 'los', 'las', 'lo', 'un', 'una', 'unos', 'unas', 'de', 'del', 'en', 'con', 'sin', 'por',
        'para', 'y', 'e', 'o', 'u', 'ni', 'que', 'se', 'me', 'te', 'le', 'les', 'nos', 'mi', 'mis', 'tu', 'tus', 'su', 'sus',
        'nuestro', 'nuestra', 'nuestros', 'nuestras', 'este', 'esta', 'esto', 'estos', 'estas', 'ese', 'esa', 'eso', 'yo',
        'usted', 'ustedes', 'el', 'ella', 'ellos', 'ellas', 'todo', 'todos', 'toda', 'todas', 'otro', 'otra', 'otros',
        'muy', 'mas', 'menos', 'ya', 'aun', 'tambien', 'solo', 'ahora', 'hoy', 'manana', 'pronto', 'urgente', 'aqui', 'alli',
        'si', 'no', 'favor', 'porfavor', 'gracias', 'hola', 'saludos', 'buen', 'bueno', 'buena', 'buenos', 'buenas',
        'dia', 'dias', 'tarde', 'tardes', 'noche', 'noches', 'quiero', 'quisiera', 'queria', 'quiere', 'quieren', 'necesito',
        'necesita', 'necesitan', 'necesitamos', 'desea', 'deseo', 'desean', 'busca', 'busco', 'buscamos', 'pide', 'pido',
        'solicita', 'solicito', 'requiere', 'requiero', 'tiene', 'tengo', 'tienen', 'tenemos', 'es', 'son', 'soy', 'somos',
        'esta', 'estoy', 'estan', 'va', 'voy', 'van', 'puede', 'puedo', 'pueden', 'podria', 'hay', 'hace', 'hacen',
        'aprender', 'estudiar', 'saber', 'conocer', 'capacitar', 'llevar', 'tomar', 'empezar', 'iniciar', 'matricularme',
        'matricularse', 'inscribirme', 'inscribirse', 'registrarme', 'urgentemente', 'nuevo', 'nueva', 'nuevos', 'nuevas',
        'estudiante', 'estudiantes', 'alumno', 'alumna', 'alumnos', 'alumnas', 'docente', 'docentes', 'profesor',
        'profesora', 'profesores', 'maestro', 'maestra', 'ingeniero', 'ingeniera', 'tecnica', 'empresario', 'empresaria',
        'emprendedor', 'emprendedora', 'dueno', 'duena', 'gerente', 'contador', 'contadora', 'enfermero', 'enfermera',
        'enfermeros', 'administrador', 'administradora', 'trabajador', 'trabajadora', 'trabajadores', 'colaborador',
        'colaboradores', 'personal', 'cliente', 'clientes', 'usuario', 'usuarios', 'persona', 'personas', 'participante',
        'participantes', 'principiante', 'principiantes', 'universitario', 'universitaria', 'egresado', 'egresada',
        'joven', 'jovenes', 'adulto', 'adultos', 'nino', 'nina', 'ninos', 'ninas', 'hijo', 'hija', 'hijos', 'hijas',
        'padre', 'madre', 'padres', 'papa', 'mama', 'familia', 'interesado', 'interesada', 'pequeno', 'pequena',
        'grande', 'reciente', 'empresa', 'empresas', 'oficina', 'oficinas', 'negocio', 'negocios', 'tienda', 'local',
        'casa', 'hogar', 'domicilio', 'institucion', 'instituciones', 'area', 'areas', 'equipo', 'equipos', 'laptop',
        'laptops', 'computadora', 'computadoras', 'pc', 'pcs', 'impresora', 'impresoras', 'escritorio', 'servidor',
        'celulares', 'sistema', 'sistemas', 'local', 'sede', 'sedes', 'aula', 'aulas', 'grupo', 'grupos', 'curso', 'clase',
        'asistencia', 'certificado', 'constancia', 'pago', 'pagos', 'precio', 'costo', 'fecha', 'fechas', 'semana', 'mes',
        'vez', 'veces', 'forma', 'manera', 'opcion', 'opciones', 'algo', 'alguien', 'alguno', 'alguna', 'ningun',
        'tambien', 'ofimatica', 'informatica', 'computacion', 'programacion', 'senor', 'senora', 'senorita', 'senores',
        'secretaria', 'secretario', 'asistente', 'ejecutivo', 'ejecutiva', 'vendedor', 'vendedora', 'chofer', 'operario',
        'practicante', 'bachiller', 'freelancer', 'community', 'manager', 'developer', 'promedio', 'planta', 'baja', 'alta',
        'piso', 'debe', 'deben', 'traer', 'trae', 'aprende', 'aprenden', 'estudia', 'estudian', 'sabe', 'saben', 'usa',
        'usan', 'rapido', 'rapida', 'lento', 'mejor', 'peor', 'primero', 'primera', 'segundo', 'segunda', 'ultimo',
        'ultima', 'total', 'gratis', 'gratuito', 'gratuita', 'remoto', 'remota', 'medio', 'media', 'mitad', 'zona',
        'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'veinte', 'cien', 'mil', 'varios',
        'varias', 'muchos', 'muchas', 'pocos', 'pocas', 'recien', 'guia', 'socio', 'socia', 'pantalla', 'tablet', 'tablets',
        'samsung', 'xiaomi', 'huawei', 'motorola', 'lg', 'sony', 'toshiba', 'logitech', 'tplink', 'intel', 'amd', 'nvidia',
        'kingston', 'comunidad', 'comunidades', 'once', 'doce', 'quince', 'treinta', 'cuarenta', 'cincuenta', 'sesenta',
        'setenta', 'ochenta', 'noventa', 'ano', 'anos', 'meses', 'edad', 'obstetra', 'albanil', 'sastre', 'piloto', 'poeta',
        'atleta', 'juez', 'militar', 'mozo', 'disco', 'aire', 'teclado', 'mouse', 'cable', 'puerto', 'memoria', 'procesador',
    ];

    /** Precede a un nombre propio: presentación personal o saludo firmado. Las variantes de «soy» se reconocen aparte. */
    private const ANCLAS_PRESENTACION = ['soy', 'soi', 'presento', 'saluda', 'saludan', 'escribe', 'habla'];

    /** Marca de puntuación en la secuencia de palabras normalizadas. */
    private const LIMITE = '|';

    /** Tras «me …» identifican a la persona con un solo término («me dicen kike»). */
    private const ANCLAS_APODO = ['dicen', 'conocen', 'llaman', 'apodan'];

    /** Relación con una persona identificada a continuación. */
    private const ANCLAS_PERSONA = [
        'hijo', 'hija', 'sobrino', 'sobrina', 'nieto', 'nieta', 'esposo', 'esposa', 'hermano', 'hermana', 'primo', 'prima',
        'tio', 'tia', 'amigo', 'amiga', 'companero', 'companera', 'alumno', 'alumna', 'estudiante', 'participante',
        'trabajador', 'trabajadora', 'colaborador', 'colaboradora', 'cliente', 'senor', 'senora', 'senorita', 'joven',
        'menor', 'papa', 'mama', 'jefe', 'jefa', 'colega', 'nino', 'nina',
    ];

    /** Títulos y tratamientos que pueden preceder a un nombre («soy el ingeniero …»); se saltan, no bloquean solos. */
    private const TITULOS = [
        'ingeniero', 'ingeniera', 'ing', 'licenciado', 'licenciada', 'lic', 'profesor', 'profesora', 'prof', 'doctor',
        'doctora', 'dr', 'dra', 'tecnico', 'tecnica', 'arquitecto', 'arquitecta', 'abogado', 'abogada', 'contador',
        'contadora', 'enfermero', 'enfermera', 'magister', 'bachiller', 'don', 'dona', 'sr', 'sra', 'srta', 'docente',
        'director', 'directora', 'gerente', 'coordinador', 'coordinadora', 'asistente', 'secretario', 'secretaria',
        'guia', 'socio', 'socia',
    ];

    /**
     * Nombres de pila cuya terminación coincide con la de un oficio o adjetivo (-or, -ica, -ente, -ario, -ado, -ción…).
     * Solo se usan en la detección contextual; no se añaden a NOMBRES_COMUNES para no ampliar la regla de proximidad.
     *
     * @var list<string>
     */
    private const NOMBRES_MORFOLOGIA_AMBIGUA = [
        'victor', 'hector', 'nestor', 'leonor', 'flor', 'salvador', 'amador', 'aurora', 'dora', 'flora', 'nora', 'teodora',
        'eleonora', 'monica', 'erica', 'angelica', 'federico', 'americo', 'dante', 'vicente', 'clemente', 'rosario', 'hilario',
        'macario', 'amado', 'conrado', 'aida', 'guido', 'candida', 'adelaida', 'zoraida', 'ada', 'concepcion', 'asuncion',
        'encarnacion', 'purificacion', 'ascension', 'soledad', 'piedad', 'trinidad', 'caridad', 'vera', 'mercedes', 'lourdes',
        'dolores', 'rolando', 'orlando', 'bautista',
    ];

    /** Servicio o atención solicitada para una persona («soporte para …»). */
    private const ANCLAS_ATENCION = [
        'soporte', 'atencion', 'asistencia', 'ayuda', 'visita', 'cita', 'servicio', 'mantenimiento', 'reparacion',
        'instalacion', 'cotizacion', 'nombre',
    ];

    /** Verbos que siguen a un sujeto personal al inicio del mensaje («brayan cuzco necesita …»). */
    private const VERBOS_SUJETO = [
        'necesita', 'quiere', 'desea', 'solicita', 'requiere', 'busca', 'pide', 'tiene', 'va', 'esta', 'estudia', 'trabaja',
        'consulta', 'pregunta', 'quisiera', 'necesitaria', 'podria', 'puede', 'se', 'ya', 'fue', 'era', 'llevo', 'llevara',
    ];

    /** Palabras de relleno entre un ancla y el nombre («matricula a la señorita …»). */
    private const RELLENO = ['a', 'al', 'la', 'el', 'los', 'las', 'mi', 'mis', 'su', 'sus', 'de', 'del', 'para', 'como', 'me'];

    /** @var list<string> */
    private const NOMBRES_COMUNES = [
        'maria', 'jose', 'juan', 'carlos', 'luis', 'ana', 'rosa', 'pedro', 'jorge', 'miguel',
        'carmen', 'jesus', 'manuel', 'francisco', 'antonio', 'javier', 'daniel', 'david',
        'alejandro', 'fernando', 'ricardo', 'roberto', 'eduardo', 'marco', 'marcos', 'julio',
        'cesar', 'oscar', 'raul', 'victor', 'hector', 'arturo', 'sergio', 'diego', 'pablo',
        'andres', 'gabriel', 'rafael', 'martin', 'christian', 'cristian', 'alexander', 'jhon',
        'john', 'kevin', 'bryan', 'edwin', 'elmer', 'wilmer', 'wilder', 'percy', 'walter',
        'hugo', 'felix', 'gustavo', 'alberto', 'mario', 'armando', 'enrique', 'alfredo',
        'jaime', 'ruben', 'ivan', 'omar', 'edgar', 'angel', 'alvaro', 'rocio', 'patricia',
        'claudia', 'silvia', 'milagros', 'jessica', 'yesica', 'veronica', 'monica', 'sandra',
        'paola', 'karina', 'marisol', 'elizabeth', 'liz', 'luz', 'gloria', 'teresa', 'juana',
        'blanca', 'martha', 'marta', 'sara', 'laura', 'elena', 'lucia', 'sofia', 'valeria',
        'camila', 'daniela', 'natalia', 'andrea', 'gabriela', 'alejandra', 'fernanda',
        'carolina', 'diana', 'karen', 'katy', 'fiorella', 'ximena', 'fabiola', 'cecilia',
        'beatriz', 'liliana', 'susana', 'miriam', 'nancy', 'gladys', 'olga', 'sonia',
        'yolanda', 'norma', 'julia', 'bertha', 'ursula',
    ];

    /** @var list<string> */
    private const APELLIDOS_COMUNES = [
        'perez', 'garcia', 'rodriguez', 'martinez', 'lopez', 'gonzalez', 'gonzales',
        'hernandez', 'gomez', 'diaz', 'sanchez', 'ramirez', 'torres', 'flores', 'rivera',
        'morales', 'ortiz', 'gutierrez', 'chavez', 'ramos', 'vargas', 'castillo', 'romero',
        'herrera', 'medina', 'aguilar', 'castro', 'vazquez', 'vasquez', 'mendoza', 'salazar',
        'delgado', 'guzman', 'espinoza', 'espinosa', 'quispe', 'mamani', 'huaman', 'huamani',
        'condori', 'apaza', 'choque', 'rojas', 'vega', 'soto', 'contreras', 'silva',
        'fuentes', 'navarro', 'campos', 'miranda', 'reyes', 'cruz', 'paredes', 'villanueva',
        'cordova', 'cordoba', 'cabrera', 'leon', 'marquez', 'pena', 'rios', 'acosta',
        'padilla', 'molina', 'suarez', 'caceres', 'aguirre', 'salas', 'cardenas', 'velasquez',
        'farfan', 'seminario', 'becerra', 'bazan', 'guevara', 'zambrano', 'carrasco',
        'aliaga', 'valdivia', 'zavaleta', 'mantilla', 'portal', 'julca', 'terrones',
        'gallardo', 'cotrina', 'cieza', 'bustamante', 'alvarado', 'nunez', 'nanez', 'aguero',
        'mejia', 'palomino', 'tapia', 'carranza', 'lozano', 'benites', 'benitez', 'ponce',
        'ibanez', 'valdez', 'arenas', 'luna', 'cornejo', 'araujo', 'davila', 'ochoa',
    ];

    public function contieneDatosPersonales(string $mensaje): bool
    {
        return $this->motivoBloqueo($mensaje) !== null;
    }

    public function esSeguro(string $mensaje): bool
    {
        return $this->motivoBloqueo($mensaje) === null;
    }

    /**
     * Devuelve el código de categoría si el mensaje debe bloquearse, o null si es seguro.
     * Nunca incluye contenido del mensaje original.
     */
    public function motivoBloqueo(string $mensaje): ?string
    {
        if (! mb_check_encoding($mensaje, 'UTF-8')) {
            return 'unicode_invalido';
        }
        if (mb_strlen($mensaje) > 2000) {
            return 'vacio_o_excesivo';
        }
        // Conservar todas las letras; NFKC une combinantes y hace visibles formas de ancho completo.
        $mensaje = \Normalizer::normalize($mensaje, \Normalizer::FORM_KC) ?: $mensaje;
        if (preg_match('/\p{Cf}/u', $mensaje)) {
            // Un carácter invisible puede separar dos palabras o partir una misma palabra.
            // Examinar ambas vistas; la recursión termina porque la vista separada ya no contiene Cf.
            $separado = preg_replace('/\p{Cf}/u', ' ', $mensaje) ?? $mensaje;
            $motivo = $this->motivoBloqueo($separado);
            if ($motivo !== null) {
                return $motivo;
            }
        }
        $mensaje = preg_replace('/\p{Cf}/u', '', $mensaje) ?? $mensaje;
        $mensaje = preg_replace('/\p{Z}+/u', ' ', $mensaje) ?? $mensaje;
        $limpio = trim($mensaje);
        if ($limpio === '' || mb_strlen($mensaje) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $mensaje) === 1) {
            return 'vacio_o_excesivo';
        }

        if ($this->detectaCredencial($limpio)) {
            return 'credencial';
        }

        // Quien declara que NO compartirá un dato («sin dar mis datos») no lo está compartiendo.
        // Solo se retira la frase de rechazo; el resto del mensaje se sigue examinando íntegro.
        $limpio = $this->neutralizaRechazoDeDatos($limpio);

        if ($this->detectaCorreo($limpio)) {
            return 'correo';
        }

        if ($this->detectaDatoBancario($limpio)) {
            return 'bancario';
        }

        if ($this->detectaFechaNacimiento($limpio)) {
            return 'fecha_nacimiento';
        }

        if ($this->detectaDireccion($limpio)) {
            return 'direccion';
        }

        if ($this->detectaTelefono($limpio)) {
            return 'telefono';
        }

        if ($this->detectaDocumento($limpio)) {
            return 'documento';
        }

        if ($this->detectaTelefonoGenerico($limpio)) {
            return 'telefono';
        }

        // Las declaraciones personales se evalúan antes de cualquier exención léxica.
        if ($this->detectaDeclaracionPersonal($limpio)) {
            // Conservar categorías anteriores; la decisión positiva ya no puede revocarse.
            return $this->detectaNombrePersonal($limpio, false)
                || $this->detectaNombreContextual($limpio)
                || $this->detectaNombrePorComplementoDeLexico($limpio)
                ? 'nombre_personal' : 'dato_personal_contextual';
        }

        if (preg_match('/(?=[\p{L}\p{M}]*\p{Latin})(?=[\p{L}\p{M}]*[\p{Cyrillic}\p{Greek}])[\p{L}\p{M}]+/u', $limpio)) {
            return 'unicode_ambiguo';
        }

        if ($this->detectaNombrePersonal($limpio, false)
            || $this->detectaNombreContextual($limpio)
            || $this->detectaNombrePorComplementoDeLexico($limpio)) {
            return 'nombre_personal';
        }

        if ($this->detectaCoincidenciaAmbigua($limpio)) {
            return 'ambiguo';
        }

        return null;
    }

    /** Reconocedor contextual local: no infiere que una palabra del diccionario sea una persona segura. */
    private function detectaDeclaracionPersonal(string $mensaje): bool
    {
        $n = $this->normalizarSinTildes($mensaje);
        $persona = '(?:'.implode('|', array_unique(array_merge(self::ANCLAS_PERSONA, [
            'visitante', 'solicitante', 'persona', 'familiar', 'pareja', 'profesor', 'profesora',
            'asesor', 'asesora', 'encargado', 'encargada', 'madre', 'padre', 'abuelo', 'abuela',
        ]))).')';
        $valor = '[\p{L}\p{M}][\p{L}\p{M}\s\x{2019}\x{0027}-]*';
        // Campo de identidad con valor, no una descripción de curso/empresa ni una pregunta de trámite.
        if (preg_match('/\b(?:nombres?|apellidos?|identidad)\b((?:(?!\b(?:curso|empresa|institucion|producto|marca|servicio|programa|software)\b)[^.;!?:=]){0,80})(?:\b(?:es|son)\s+|[:=]\s*)([\p{L}\p{M}]+)/u', $n, $campo)
            && ! in_array($campo[2], ['obligatorio', 'obligatoria', 'necesario', 'necesaria', 'requerido', 'requerida', 'publico', 'publica', 'opcional'], true)) {
            return true;
        }
        if (preg_match_all('/\b(?:soy|soi)\s+([\p{L}\p{M}]+)\s+([\p{L}\p{M}]+)/u', $n, $presentaciones, PREG_SET_ORDER)) {
            $descripcion = array_merge(self::VOCABULARIO_COMUN, self::TERMINOS_INSTITUCIONALES, self::PALABRAS_NO_PERSONALES, self::TITULOS);
            foreach ($presentaciones as $presentacion) {
                // Reutiliza el reconocedor de oficios existente; no exime ningún positivo de otras capas.
                if ($this->tieneMorfologiaDeOficio($presentacion[1]) && ! in_array($presentacion[1], self::NOMBRES_COMUNES, true)) {
                    continue;
                }
                if (! in_array($presentacion[1], $descripcion, true) && ! in_array($presentacion[2], $descripcion, true)
                    && (in_array($this->clasePalabra($presentacion[1]), [self::C_NOMBRE_LISTADO, self::C_DESCONOCIDA], true)
                        || in_array($this->clasePalabra($presentacion[2]), [self::C_NOMBRE_LISTADO, self::C_DESCONOCIDA], true))) {
                    return true;
                }
            }
        }
        if (preg_match('/\b(?:'.$persona.'\b[^.;!?]{0,45}\bse\s+llama|me\s+(?:llaman|dicen|conocen|apodan))\s+([\p{L}\p{M}]+)/u', $n, $denominacion)
            && ! in_array($denominacion[1], ['para', 'por', 'en', 'con', 'que', 'como', 'cuando', 'donde', 'al', 'el', 'la', 'los', 'las', 'un', 'una'], true)) {
            return true;
        }
        // Una referencia posesiva puede nombrar a alguien sin un rol del diccionario.
        // El sustantivo público interrumpe la referencia: «mi empresa se llama …» no declara una persona.
        $entidad = '(?:'.implode('|', array_merge(self::SUSTANTIVOS_ORGANIZACION, ['curso', 'cursos', 'producto', 'servicio', 'programa', 'archivo', 'proyecto', 'equipo'])).')';
        if (preg_match_all('/\b(?:mi|mis|su|sus)\s+([\p{L}\p{M}]+(?:(?!\b(?:mi|mis|su|sus)\b)[^.;!?]){0,65})\bse\s+llama\s+([\p{L}\p{M}]+)/u', $n, $referencias, PREG_SET_ORDER)) {
            foreach ($referencias as $referencia) {
                // Separar clasificación del sujeto y extracción: evita expandir un léxico entero en un cuantificador.
                if (! preg_match('/\b'.$entidad.'\b/u', $referencia[1])
                    && ! in_array($referencia[2], ['como', 'igual', 'asi'], true)) {
                    return true;
                }
            }
        }
        // Un nombre declarado puede ser CJK, una inicial o dos palabras de uso corriente.
        if (preg_match('/\b(?:se identifica como|nombre y apellido(?:s)?(?: del solicitante)?\s*:|nombre(?: de mi '.$persona.'[^.;!?]{0,55})?\s+es)\s*'.$valor.'/u', $n)
            || preg_match('/\b'.$persona.'\b[^.;!?]{0,45}\bllamad[oa]\s+'.$valor.'/u', $n)
            || preg_match('/\b(?:es|son)\s+mi\s+'.$persona.'\b/u', $n)) {
            return true;
        }
        if (preg_match_all('/\b'.$persona.'\b[^.;!?]{0,90}\b(?:es|son|firma(?: electronicamente)? como)\s+('.$valor.')/u', $n, $declaraciones)) {
            foreach ($declaraciones[1] as $declaracion) {
                $primera = preg_split('/\s+/u', $declaracion)[0];
                // «El alumno es principiante» describe un rol, no entrega un nombre.
                if (! in_array($primera, array_merge(self::PALABRAS_NO_PERSONALES, self::TERMINOS_INSTITUCIONALES, self::VOCABULARIO_COMUN, ['especialista', 'responsable']), true)) {
                    return true;
                }
            }
        }
        // La edad es un atributo personal solo cuando se vincula a alguien; no bloquea duración de cursos.
        if (preg_match('/\b(?:mi|su)\s+'.$persona.'\s+(?:tiene|cumple|cumplio|de)\s+(?:actualmente\s+)?\d{1,3}\s+anos\b/u', $n)
            || preg_match('/\b(?:mi|su)\s+edad\s*(?:es|:|=)\s*\d{1,3}\b/u', $n)
            || preg_match('/\b(?:edad de (?:mi|su) '.$persona.')\b[^.;!?]{0,35}\d{1,3}\b/u', $n)) {
            return true;
        }
        // Identificadores contextuales, incluso cortos o alfanuméricos. No confundir con preguntas sin valor.
        if (preg_match('/\b(?:historia clinica|referencia de (?:mi|su|la) transferencia|identificador personal|numero de seguro|ficha personal|registro privado|cuenta particular)\b[^.;!?]{0,55}\b[\p{L}]*\d[\p{L}\d]*\b/u', $n)) {
            return true;
        }
        if (preg_match('/\b(?:mi|su)\s+secreto(?:\s+compartido)?\s*(?:es|:|=)\s*\S+/u', $n)) {
            return true;
        }
        // Teléfonos dictados y repartidos: contar los dígitos solo dentro del contexto de contacto.
        if (preg_match('/\b(?:telefono|celular|movil|linea|mi numero|llamame|llamenme|contactame|contactenme)\b([^.;!?]*)/u', $n, $m)) {
            $digitos = preg_match_all('/\d/u', $m[1]);
            $dictados = preg_match_all('/\b(?:cero|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve)\b/u', $m[1]);
            if ($digitos + $dictados >= 7) {
                return true;
            }
        }
        // Homónimos personales no dejan de ser candidatos por pertenecer al vocabulario común.
        $homonimos = 'alma|abril|amparo|aurora|cielo|consuelo|estrella|esperanza|felicidad|libertad|luz|mar|nieves|oliva|paz|rosa|sol|triunfo|vida|victoria|blanca|clara|dolores|pilar|socorro';
        if (preg_match_all('/\b(?:'.$homonimos.')\s+([\p{L}]{2,})(?:\s+([\p{L}]{2,}))?/u', $n, $pares, PREG_SET_ORDER)) {
            $publicas = array_merge(self::TERMINOS_INSTITUCIONALES, self::PALABRAS_NO_PERSONALES, self::VERBOS_SUJETO, ['solar', 'natural', 'artificial', 'libre', 'de', 'del', 'y', 'en', 'es', 'para', 'con']);
            foreach ($pares as $par) {
                if (! in_array($par[1], $publicas, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Barrera de salida: entidades públicas exactas, no un vocabulario abierto de nombres. */
    public function contieneDatosPrivadosEnRespuesta(string $texto): bool
    {
        $texto = str_ireplace([
            'Soy Horus AI Advisor', 'Wi-Fi', 'Región de Cajamarca',
            'Colegio de Enfermeros de Cajamarca',
            'Colegio de Enfermeros del Perú CR II La Libertad',
            'Colegio de Enfermeros del Perú - CR II La Libertad',
            'Colegio Regional de Licenciados en Administración de Cajamarca',
            'Colegio de Economistas de Cajamarca',
            'Instituto de Educación Superior ISAM',
            'Jr. José Gálvez N.° 322', 'Libro de Reclamaciones', 'Ley N.°',
        ], ' institución ', $texto);

        return $this->detectaNombrePersonal($texto)
            || $this->detectaCredencial($texto)
            || $this->detectaDatoBancario($texto)
            || $this->detectaDireccion($texto);
    }

    private function detectaCorreo(string $mensaje): bool
    {
        if (str_contains($mensaje, '@')) {
            return true;
        }

        if (preg_match('/\b[a-z0-9._%+\-]+\s*(?:\[?\s*arroba\s*\]?|\(\s*arroba\s*\)|\(\s*at\s*\))\s*[a-z0-9.\-]+\s*(?:\[?\s*punto\s*\]?|\.)\s*[a-z]{2,}\b/iu', $mensaje) === 1) {
            return true;
        }

        return preg_match('/\b(mi\s+correo|mi\s+e-?mail|correo\s+personal|correo\s+electr[oó]nico\s+es|escr[ií]beme\s+a|correo\s*[:=])\b/iu', $mensaje) === 1;
    }

    private function detectaCredencial(string $mensaje): bool
    {
        // Clave/contraseña con calificativo y valor («clave wifi: …», «contraseña del correo = …»).
        if (preg_match('/\b(?:clave|contrase[ñn]a|password|pin|token)(?:\s+[\p{L}]+){0,2}\s*[:=]\s*\S+/iu', $mensaje) === 1) {
            return true;
        }

        // Preguntar por una contraseña olvidada, sin posesivo ni valor, no la revela («¿Qué hago si olvidé la contraseña?»).
        $sinValor = preg_match('/[¿?]/u', $mensaje) === 1
            && preg_match('/\b(?:contrase[ñn]a|password)\b(?:\s+[\p{L}]+){0,3}\s*(?:es|son|[:=])\s*\S+/iu', $mensaje) !== 1
            && preg_match('/\d/u', $mensaje) !== 1;
        if ($sinValor) {
            $mensaje = preg_replace('/\b(?:(?:la|una|esa|otra|el|tu|su|mi|mis)\s+)?(?:contrase[ñn]as?|claves?|api[\s_-]?keys?|secret[\s_-]?keys?|access[\s_-]?tokens?|refresh[\s_-]?tokens?)\b/iu', ' ', $mensaje) ?? $mensaje;
        }

        if (preg_match('/\b(contrase[ñn]a|contrasena|password|passwd|pwd|mi\s+clave|clave\s+de\s+acceso|clave\s+secreta|clave\s+personal|clave\s*[:=]|pass\s*[:=]|mi\s+pin|pin\s+de\s+acceso|pin\s*[:=]|token\s*(?:[:=]|es\b)|api[\s_-]?key|secret[\s_-]?key|access[\s_-]?token|refresh[\s_-]?token|bearer|jwt|credencial|credenciales|private[\s_-]?key|ssh[\s_-]?key|session[\s_-]?id)\b/iu', $mensaje) === 1) {
            return true;
        }

        // Códigos o frases de recuperación declarados con su valor («el código de recuperación privado es …»).
        if (preg_match('/\b(?:c[oó]digo|clave|frase|palabra)(?:\s+[\p{L}]+){0,3}\s+(?:secret[oa]|privad[oa]|de\s+(?:recuperaci[oó]n|respaldo|acceso|paso|seguridad))(?:\s+[\p{L}]+){0,4}\s*(?:es|son|[:=])\s*\S+/iu', $mensaje) === 1
            || preg_match('/\b(?:frase\s+semilla|seed\s+phrase|recovery\s+(?:code|phrase|key))\b/iu', $mensaje) === 1) {
            return true;
        }

        // Los atributos de una etiqueta HTML («<img src=x onerror=alert(1)>») no son claves; los patrones genéricos siguientes
        // trabajan sobre el texto sin marcado. Las palabras clave anteriores ya se evaluaron sobre el mensaje completo.
        $mensaje = preg_replace('/<[^>]{0,500}>/u', ' ', $mensaje) ?? $mensaje;

        if (preg_match('/\b(sk-[a-z0-9_\-]{8,}|re_[a-z0-9_\-]{8,}|gh[pousr]_[a-z0-9_]{8,}|eyJ[a-z0-9_\-]{8,}\.[a-z0-9_\-]{8,})\b/iu', $mensaje) === 1) {
            return true;
        }

        if (preg_match('/(?=\S{8,})(?=\S*[A-Za-z])(?=\S*\d)(?=\S*[@#$%^&*!+=_~`])[A-Za-z0-9@#$%^&*!+=_~`.\-]{8,}/u', $mensaje) === 1) {
            return true;
        }

        return preg_match('/\b(?=[A-Za-z0-9_\-]{16,}\b)(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z0-9_\-]{16,}\b/u', $mensaje) === 1;
    }

    private function detectaDatoBancario(string $mensaje): bool
    {
        if (preg_match('/\b(cci|iban|swift|bic|cvv|cvc|clabe|n[uú]mero\s+de\s+cuenta|nro\.?\s+de\s+cuenta|cuenta\s+bancaria|cuenta\s+de\s+ahorros|cuenta\s+corriente|cuenta\s+sueldo|cuenta\s+interbancaria|mi\s+cuenta\s+es|mi\s+tarjeta|tarjeta\s+visa|tarjeta\s+mastercard|c[oó]digo\s+de\s+seguridad)\b/iu', $mensaje) === 1) {
            return true;
        }

        // Tarjeta de 16 dígitos en bloques de 4 o CCI de 20 dígitos.
        return preg_match('/\b\d{4}(?:[\s.\-]+\d{4}){3}\b|\b\d{3}[\s.\-]+\d{3}[\s.\-]+\d{12}[\s.\-]+\d{2}\b/u', $mensaje) === 1;
    }

    private function detectaFechaNacimiento(string $mensaje): bool
    {
        if (preg_match('/\b(fecha\s+de\s+nacimiento|f\.?\s*nac\.?|nac[ií]\s+el|naci[oó]\s+el|nacido\s+el|nacida\s+el|nac[ií]\s+en\s+\d{4}|mi\s+cumplea[ñn]os|cumplea[ñn]os\s+es|tengo\s+\d{1,3}\s+a[ñn]os|mi\s+edad\s+es|\d{1,2}\s+a[ñn]os\s+de\s+edad)\b/iu', $mensaje) === 1) {
            return true;
        }

        // Edad declarada con cifra («mi edad es 16», «mi edad personal simulada es 16 años»).
        if (preg_match('/\bmi\s+edad(?:\s+[\p{L}]+){0,3}\s+(?:es|son)\s+\d/iu', $mensaje) === 1) {
            return true;
        }

        if (preg_match('/\b(?:0?[1-9]|[12]\d|3[01])\s*[\/\-\.]\s*(?:0?[1-9]|1[0-2])\s*[\/\-\.]\s*(?:19\d{2}|20\d{2}|\d{2})\b/u', $mensaje) === 1) {
            return true;
        }

        if (preg_match('/\b(19\d{2}|20\d{2})\s*[\/\-\.]\s*(?:0?[1-9]|1[0-2])\s*[\/\-\.]\s*(?:0?[1-9]|[12]\d|3[01])\b/u', $mensaje) === 1) {
            return true;
        }

        return preg_match('/\b\d{1,2}\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)(?:\s+de|\s+del)?\s+\d{2,4}\b/iu', $mensaje) === 1;
    }

    private function detectaDireccion(string $mensaje): bool
    {
        if (preg_match('/\d/u', $mensaje) === 1 && preg_match('/\b(jr\.?|jir[oó]n|av\.?|avenida|calle|psje\.?|pasaje|pje\.?|alameda|prolongaci[oó]n|prol\.?|malec[oó]n|mz\.?|manzana|lote|lt\.?\s*[a-z0-9]|dpto\.?\s*[a-z0-9]|departamento\s*\d|interior\s*\d|int\.?\s*\d|urbanizaci[oó]n|urb\.?|asentamiento\s+humano|aa\.?\s*hh\.?|condominio|residencial)\b/iu', $mensaje) === 1) {
            return true;
        }

        // Una pregunta sin cifras sobre dónde vive o a qué dirección alude («¿y si vivo en otra ciudad?») no entrega ninguna.
        $preguntaSinCifras = preg_match('/[¿?]/u', $mensaje) === 1 && preg_match('/\d/u', $mensaje) !== 1;
        if (! $preguntaSinCifras && preg_match('/\b(vivo\s+en|mi\s+direcci[oó]n|mi\s+domicilio|domiciliad[oa]\s+en|resido\s+en|mi\s+casa\s+queda|enviar\s+a\s+la\s+direcci[oó]n)\b/iu', $mensaje) === 1) {
            return true;
        }

        return preg_match('/\b(nro\.?|n[uú]m\.?|n\.?\s*[°º]|cuadra|cdra\.?)\s*\d+/iu', $mensaje) === 1;
    }

    private function detectaDocumento(string $mensaje): bool
    {
        // Nombrar un documento («¿necesito DNI?») no es un dato; sí lo es aportarlo:
        // dígitos, posesivo o atribución («mi DNI», «el DNI de mi hijo es», «DNI: …»).
        $documento = '(?:dni|d\.n\.i\.?|ruc|r\.u\.c\.?|pasaporte|carn[eé]t?\s+de\s+extranjer[ií]a|c[eé]dula(?:\s+de\s+identidad)?|documento\s+de\s+identidad|documento\s+nacional|libreta\s+electoral|brevete|licencia\s+de\s+conducir)';
        $conDigitos = preg_match('/\d/u', $mensaje) === 1;
        // Una pregunta sin cifras con «su/sus» se dirige a la empresa («¿Cuál es su RUC?»): es información pública.
        // Con cifras, con posesivo propio o atribuida a una persona, sigue siendo identificación personal.
        $preguntaInstitucional = ! $conDigitos && preg_match('/[¿?]/u', $mensaje) === 1;
        $propio = $preguntaInstitucional ? '(?:mi|mis|nuestro|nuestra)' : '(?:mi|mis|tu|tus|su|sus|nuestro|nuestra)';
        if ($preguntaInstitucional) {
            // «¿Es seguro compartir mi DNI?», «¿necesito dar mi RUC?»: pregunta sobre el trámite, sin valor alguno.
            $mensaje = preg_replace('/\b(?:compartir|dar|darles|enviar|mandar|pasar|escribir|poner|ingresar|adjuntar|entregar|presentar|mostrar|llevar|traer|tener|facilitar|proporcionar|indicar|necesito|necesita|piden|exigen|requieren|solicitan|pedir|usar|usan)\s+(?:(?:mi|mis|el|su|sus)\s+)?'.$documento.'(?![\p{L}])/iu', ' ', $mensaje) ?? $mensaje;
        }
        $atribucion = $preguntaInstitucional
            ? '(?:de\s+(?:mi|mis)\b|del\s+(?:alumno|estudiante|titular|cliente|participante)\b)'
            : '(?:[:=#]|n[°º.]|nro|n[uú]mero|es\b|de\s+(?:mi|mis|tu|su)\b|del\s+(?:alumno|estudiante|titular|cliente|participante)\b)';
        if (preg_match('/(?<![\p{L}.])'.$documento.'(?![\p{L}])/iu', $mensaje) === 1
            && ($conDigitos
                || preg_match('/\b'.$propio.'\s+(?:\p{L}+\s+){0,2}'.$documento.'(?![\p{L}])/iu', $mensaje) === 1
                || preg_match('/(?<![\p{L}.])'.$documento.'\s*'.$atribucion.'/iu', $mensaje) === 1)) {
            return true;
        }
        if (preg_match('/\bc\.?e\.?\s*\d/iu', $mensaje) === 1) {
            return true;
        }

        // RUC peruano (11 dígitos que inician con 10, 15, 17 o 20, con o sin guiones/espacios).
        if (preg_match('/(?<!\d)(10|15|17|20)(?:[\s.\-]*\d){9}(?!\d)/u', $mensaje) === 1) {
            return true;
        }

        // Pasaporte o documento alfanumérico (1 a 3 letras seguidas de 5 a 9 dígitos sin preposiciones comunes).
        if (preg_match('/\b(?!(?:al|el|del|en|con|por|sin|mi|tu|su)\s)[A-Za-z]{1,3}-?\d{5,9}\b/iu', $mensaje) === 1) {
            return true;
        }

        // DNI peruano (8 dígitos exactos con o sin espacios/guiones).
        return preg_match('/(?<![+\d])\d(?:[\s.\-]*\d){7}(?!\d)/u', $mensaje) === 1;
    }

    private function detectaTelefono(string $mensaje): bool
    {
        if (preg_match('/(?:^|[\s(])\+\d{1,3}[\s.\-()]*\d{2,}/u', $mensaje) === 1) {
            return true;
        }

        if (preg_match('/\(0\d{1,2}\)[\s.\-]*\d{2,}[\s.\-]*\d{2,}/u', $mensaje) === 1) {
            return true;
        }

        if (preg_match('/\b9\d{2}[\s.\-]*\d{3}[\s.\-]*\d{3}\b/u', $mensaje) === 1) {
            return true;
        }

        // «mi celular / mi teléfono / mi número» solo es dato cuando se entrega: con valor, con verbo de entrega o como atribución.
        // «La pantalla de mi celular se rompió» habla de un equipo, no entrega el número.
        $mio = '(?:mi|nuestro)\s+(?:tel[eé]fono|celular|cel|m[oó]vil|n[uú]mero|whatsapp)';
        if (preg_match('/\b'.$mio.'\s*(?:es\b|son\b|actual\b|personal\b|[:=]|\d)/iu', $mensaje) === 1
            || preg_match('/\b(?:dejo|dejar|dejarte|paso|pasar|pasarte|doy|dar|darte|env[ií]o|enviar|enviarte|mando|mandar|anoto|anotar|escribo|escribir|comparto|compartir|este\s+es|ese\s+es|aqu[ií]\s+est[aá]|aqu[ií]\s+va|llama\s+a|llamen\s+a|ll[aá]menme\s+a|escr[ií]banme\s+a|contacta\s+a|contacten\s+a)\s+(?:\p{L}+\s+){0,2}'.$mio.'\b/iu', $mensaje) === 1) {
            return true;
        }

        return preg_match('/\b(mi\s+tel[eé]fono\s*[:=]|ll[aá]mame|llamame|comun[ií]cate\s+al|tel[eé]?f?\.?\s*[:=]?\s*\d|cel\.?\s*[:=]?\s*\d|whatsapp\s*[:=]?\s*\+?\d)\b/iu', $mensaje) === 1;
    }

    private function detectaTelefonoGenerico(string $mensaje): bool
    {
        return preg_match('/\d(?:[\s.\-()]*\d){6,}/u', $mensaje) === 1;
    }

    /**
     * @param  bool  $reglaMayusculas  Regla heredada «dos palabras en mayúscula inicial fuera del vocabulario institucional».
     *                                 Se conserva solo en la barrera de SALIDA; en la entrada la sustituye el complemento del léxico,
     *                                 que no confunde productos ni instituciones capitalizados con personas.
     */
    private function detectaNombrePersonal(string $mensaje, bool $reglaMayusculas = true): bool
    {
        // Productos y topónimo verificados; el resto de la frase sigue siendo examinado.
        $mensaje = preg_replace('/\b(?:Power BI|Ofimática Básica|San Marcos|Colegio de Enfermeros(?: de Cajamarca| del Perú)?|Colegio de Economistas de Cajamarca|Instituto ISAM)\b/iu', ' institucional ', $mensaje) ?? $mensaje;
        if (preg_match('/\b(me\s+llamo|mi\s+nombre(?:\s+completo)?\s+es|mi\s+apellido\s+es|mis\s+apellidos\s+son|a\s+nombre\s+de|de\s+parte\s+de|inscrito\s+como|matriculado\s+como|atentamente|atte\.?)\s+[\p{L}]{2,}/iu', $mensaje) === 1) {
            return true;
        }

        if (preg_match('/\b(sr\.?|sra\.?|srta\.?|don|do[ñn]a)\s+[\p{L}]{2,}/iu', $mensaje) === 1) {
            return true;
        }

        // Búsqueda insensible a mayúsculas y tildes sobre nombres y apellidos frecuentes.
        $normalizado = $this->normalizarSinTildes($mensaje);
        $tokens = preg_split('/[^a-z0-9]+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $conectores = ['de', 'del', 'la', 'las', 'los'];
        $preposicionesPersonales = ['soy', 'para', 'de', 'con', 'cliente', 'alumno', 'alumna', 'estudiante', 'hijo', 'hija', 'esposo', 'esposa', 'hermano', 'hermana'];

        $totalTokens = count($tokens);
        for ($i = 0; $i < $totalTokens; $i++) {
            $actual = $tokens[$i];
            $esNombreOApellido = in_array($actual, self::NOMBRES_COMUNES, true) || in_array($actual, self::APELLIDOS_COMUNES, true);
            if (! $esNombreOApellido) {
                continue;
            }

            if ($i > 0 && in_array($tokens[$i - 1], $preposicionesPersonales, true)) {
                return true;
            }

            $siguienteIdx = $i + 1;
            while ($siguienteIdx < $totalTokens && in_array($tokens[$siguienteIdx], $conectores, true)) {
                $siguienteIdx++;
            }

            if ($siguienteIdx < $totalTokens) {
                $siguiente = $tokens[$siguienteIdx];
                if (in_array($siguiente, self::NOMBRES_COMUNES, true) || in_array($siguiente, self::APELLIDOS_COMUNES, true)) {
                    return true;
                }
            }
        }

        // Dos o más palabras con mayúscula inicial consecutivas (con soporte de tildes, eñe, diéresis y guiones).
        if ($reglaMayusculas && preg_match_all('/\b[A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]{1,25}(?:(?:\s+(?:de|del|la|las|los|y|san|santa))?[\s\-]+[A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]{1,25})+\b/u', $mensaje, $coincidencias) > 0) {
            foreach ($coincidencias[0] as $frase) {
                $palabras = preg_split('/[\s\-]+/u', $this->normalizarSinTildes($frase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($palabras as $palabra) {
                    // Mismo criterio que la entrada: «Aprende Excel» o «Microsoft Office» son vocabulario público;
                    // un nombre listado o una palabra que el léxico no explica («Quispilloclla») sigue contando.
                    if (! in_array($palabra, $conectores, true) && ! in_array($palabra, self::TERMINOS_INSTITUCIONALES, true)
                        && ! in_array($palabra, self::PALABRAS_NO_PERSONALES, true)
                        && in_array($this->clasePalabra($palabra), [self::C_NOMBRE_LISTADO, self::C_DESCONOCIDA], true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Nombres completos sin mayúsculas, reconocidos por su contexto y no por su grafía:
     * presentación («soy …»), matrícula o inscripción de otra persona, relación
     * («mi hijo …»), atención solicitada para alguien («soporte para …») o sujeto al
     * inicio seguido de un verbo («… necesita soporte»). Exige dos o más palabras
     * seguidas fuera del vocabulario común e institucional; una sola palabra no basta.
     * Solo se aplica a la entrada del visitante, no a la barrera de salida.
     */
    private function detectaNombreContextual(string $mensaje): bool
    {
        $tokens = $this->tokensNormalizados($mensaje);
        $total = count($tokens);
        $inicio = 0;
        while ($inicio < $total && in_array($tokens[$inicio][0], ['hola', 'ola', 'buenas', 'buenos', 'buen', 'dia', 'dias', 'tardes', 'noches', 'saludos', self::LIMITE], true)) {
            $inicio++;
        }
        $verbo = $inicio + $this->longitudNombre($tokens, $inicio);
        if ($verbo - $inicio >= 2 && $verbo < $total && in_array($tokens[$verbo][0], self::VERBOS_SUJETO, true)) {
            return true;
        }

        for ($i = 0; $i < $total; $i++) {
            $actual = $tokens[$i][0];
            $siguiente = $tokens[$i + 1][0] ?? '';
            $apodo = in_array($actual, self::ANCLAS_APODO, true) && ($tokens[$i - 1][0] ?? '') === 'me';
            $ancla = $apodo
                || in_array($actual, self::ANCLAS_PRESENTACION, true)
                || preg_match('/^[sz]o+(?:y+|i+)$/', $actual) === 1
                || in_array($actual, self::ANCLAS_PERSONA, true)
                || preg_match('/^(?:matric|inscri|incri|inscrb|registr|anot)/', $actual) === 1
                || (in_array($actual, self::ANCLAS_ATENCION, true) && in_array($siguiente, ['para', 'a', 'de'], true)
                    && ($siguiente !== 'de' || $actual === 'nombre'));
            if (! $ancla) {
                continue;
            }
            // Salta relleno, tratamientos, títulos y oficios («soy el ingeniero …», «soy mecánico, …»); no salta un nombre.
            $j = $i + 1;
            $saltos = 0;
            while ($j < $total && $saltos < 5 && $this->esPrefijoDeNombre($tokens[$j][0])) {
                $j++;
                $saltos++;
            }
            if ($this->longitudNombre($tokens, $j) >= ($apodo ? 1 : 2)) {
                return true;
            }
        }

        return false;
    }

    private function esPrefijoDeNombre(string $palabra): bool
    {
        return $palabra === self::LIMITE
            || in_array($palabra, self::RELLENO, true)
            || in_array($palabra, self::ANCLAS_PERSONA, true)
            || in_array($palabra, self::TITULOS, true)
            || ($this->esCandidatoBase($palabra) && $this->tieneMorfologiaDeOficio($palabra))
            || $this->clasePalabra($palabra) === self::C_CONOCIDA;
    }

    /** @param list<array{0: string, 1: bool}> $tokens */
    private function longitudNombre(array $tokens, int $desde): int
    {
        $n = 0;
        while (isset($tokens[$desde + $n]) && $n < 6 && $this->puedeSerNombre($tokens[$desde + $n][0], $n === 0, $tokens[$desde + $n][1])) {
            $n++;
        }

        return $n;
    }

    /**
     * La primera palabra de un nombre es un nombre de pila: se descartan las que tienen forma de oficio,
     * participio o sustantivo abstracto. Las siguientes son apellidos: se admiten -ado, -ida, -ista o -ando
     * (Hurtado, Almeida, Bautista, Orlando) y solo se descartan adjetivos claros (-ico, -ivo, -dor) y verbos en -ó.
     */
    private function puedeSerNombre(string $palabra, bool $primera, bool $verboPasado): bool
    {
        if (in_array($palabra, self::NOMBRES_COMUNES, true) || in_array($palabra, self::APELLIDOS_COMUNES, true)
            || in_array($palabra, self::NOMBRES_MORFOLOGIA_AMBIGUA, true)) {
            return true;
        }
        if (! $this->esCandidatoBase($palabra) || $verboPasado
            || preg_match('/(?:mente|cion|ciones|sion|siones|dad|dades|iendo)$/', $palabra) === 1) {
            return false;
        }
        if ($primera) {
            // Un nombre de pila no es vocabulario común («chef», «policía», «fotógrafo»); un apellido sí puede serlo («Mercado»).
            return ! $this->tieneMorfologiaDeOficio($palabra) && ! LexicoPublico::contiene($palabra);
        }

        // Un adjetivo o sustantivo derivado del vocabulario común («contable», «mensual», «seguridad») no es un apellido.
        if (preg_match('/(?:able|ible|al|ar|ivo|iva|ico|ica|ismo|cion|sion|dad|miento)s?$/', $palabra) === 1 && LexicoPublico::contiene($palabra)) {
            return false;
        }

        return mb_strlen($palabra) < 6 || preg_match('/(?:icos?|icas?|ivos?|ivas?|dor|dora|dores|doras)$/', $palabra) !== 1;
    }

    private function esCandidatoBase(string $palabra): bool
    {
        return mb_strlen($palabra) >= 2
            && preg_match('/^\p{L}+$/u', $palabra) === 1
            && ! in_array($palabra, self::VOCABULARIO_COMUN, true)
            && ! in_array($palabra, self::TERMINOS_INSTITUCIONALES, true)
            && ! in_array($palabra, self::PALABRAS_NO_PERSONALES, true)
            && ! in_array($palabra, self::ANCLAS_PRESENTACION, true)
            && ! in_array($palabra, self::ANCLAS_PERSONA, true)
            && ! in_array($palabra, self::ANCLAS_ATENCION, true)
            && ! in_array($palabra, self::TITULOS, true)
            && ! in_array($palabra, self::VERBOS_SUJETO, true)
            && preg_match('/^(?:matric|inscri|registr)/', $palabra) !== 1;
    }

    /** Terminaciones de oficio, adjetivo o participio («mecánico», «asesor», «comerciante», «egresado»). */
    private function tieneMorfologiaDeOficio(string $palabra): bool
    {
        return ! in_array($palabra, self::NOMBRES_MORFOLOGIA_AMBIGUA, true)
            && preg_match('/(?:eros?|eras?|istas?|icos?|icas?|logos?|logas?|or|ora|ores|oras|antes?|entes?|arios?|arias?|ados?|adas?|idos?|idas?)$/', $palabra) === 1;
    }

    /**
     * Minúsculas sin tildes ni marcas combinantes (NFC o NFD), espacios Unicode como separadores y la
     * puntuación como límite (LIMITE), para que «soy zurdo, ¿importa?» no forme una secuencia.
     * El segundo valor indica si la palabra original terminaba en «ó» (forma verbal: «aprendió»).
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function tokensNormalizados(string $texto): array
    {
        $texto = class_exists(\Normalizer::class) ? (\Normalizer::normalize($texto, \Normalizer::FORM_KD) ?: $texto) : $texto;
        $partes = preg_split('/([^\p{L}\p{Mn}]+)/u', mb_strtolower($texto, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $tokens = [];
        foreach ($partes as $parte) {
            if (preg_match('/^[\p{L}\p{Mn}]/u', $parte) !== 1) {
                if (preg_match('/[,.;:!?¡¿()\[\]"“”«»…—–\/|]/u', $parte) === 1) {
                    $tokens[] = [self::LIMITE, false];
                }

                continue;
            }
            $verboPasado = preg_match('/o\x{0301}$/u', $parte) === 1;
            $limpia = $this->normalizarSinTildes(preg_replace('/\p{Mn}+/u', '', $parte) ?? $parte);
            if ($limpia !== '') {
                $tokens[] = [$limpia, $verboPasado];
            }
        }

        return $tokens;
    }

    /** Clases de palabra para el complemento del léxico. */
    private const C_NEUTRA = 0;

    private const C_CONOCIDA = 1;

    private const C_NOMBRE_LISTADO = 2;

    private const C_DESCONOCIDA = 3;

    private const C_CONECTOR = 4;

    /** Partículas que unen partes de un nombre compuesto («María de los Ángeles», «Al-Hakim», «van Dijk»). */
    private const CONECTORES_DE_NOMBRE = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'san', 'santa', 'da', 'di', 'do', 'dos', 'das', 'van', 'von', 'der', 'den', 'bin', 'ben', 'al', 'el', 'le', 'mc', 'mac'];

    /**
     * Sustantivos que encabezan nombres de entidad, lugar o producto («Colegio Santa Teresita», «Río Chonta», «software Corel Draw»).
     * La secuencia capitalizada que los sigue es el nombre de la entidad y no se juzga como persona; todo lo que quede FUERA de esa
     * secuencia se sigue examinando. Limitación: una entidad con nombre de persona («empresa Juan Pérez») queda exenta.
     */
    private const SUSTANTIVOS_ORGANIZACION = [
        'empresa', 'compania', 'cooperativa', 'asociacion', 'federacion', 'fundacion', 'corporacion', 'sociedad', 'comunidad', 'consorcio',
        'grupo', 'club', 'ong', 'sindicato', 'gremio', 'colegio', 'instituto', 'institucion', 'universidad', 'escuela', 'academia', 'centro',
        'facultad', 'hospital', 'clinica', 'posta', 'farmacia', 'laboratorio', 'taller', 'hotel', 'hostal', 'restaurante', 'tienda', 'bazar',
        'bodega', 'panaderia', 'ferreteria', 'libreria', 'biblioteca', 'imprenta', 'editorial', 'constructora', 'distribuidora',
        'inmobiliaria', 'minera', 'agencia', 'banco', 'caja', 'municipalidad', 'junta', 'comite', 'iglesia', 'parroquia', 'museo', 'teatro',
        'cine', 'parque', 'hacienda', 'fundo', 'granja', 'servicios', 'soluciones', 'inversiones', 'consultores', 'representaciones',
        'rio', 'lago', 'laguna', 'cerro', 'valle', 'distrito', 'provincia', 'region', 'caserio', 'anexo', 'barrio', 'urbanizacion',
        'sector', 'pueblo', 'ciudad', 'avenida', 'jiron', 'calle', 'puente', 'terminal', 'aeropuerto',
        'software', 'aplicacion', 'plataforma', 'sistema', 'herramienta', 'marca', 'modelo', 'app',
    ];

    /** Verbos que no pueden ser «enlace» entre un sustantivo de entidad y su nombre. */
    private const VERBOS_DE_ENLACE = ['es', 'son', 'soy', 'somos', 'fue', 'era', 'eran', 'se', 'esta', 'estan', 'llama', 'llaman', 'dice', 'dicen', 'ofrece', 'ofrecen', 'cuenta', 'tienen', 'trabaja', 'trabajo', 'estudio', 'vivo', 'pertenezco', 'represento', 'dirijo', 'atiende', 'atienden', 'responde', 'contesta'];

    /** Saludos y cortesías que no cuentan como contenido al juzgar mensajes muy cortos. */
    private const CORTESIA = ['hola', 'ola', 'buenas', 'buenos', 'buen', 'dia', 'dias', 'tarde', 'tardes', 'noche', 'noches', 'gracias', 'saludos', 'ok', 'okay', 'por', 'favor', 'porfa', 'disculpa', 'disculpe', 'perdon', 'estimados', 'estimado', 'estimada', 'hey'];

    /** Tras estas palabras un nombre propio es casi seguro, aunque aparezca solo («se llama …», «identificado como …»). */
    private const ANCLAS_NOMBRE_FUERTE = ['llama', 'llamado', 'llamada', 'llamados', 'llamadas', 'identifico', 'identificado', 'identificada', 'representacion', 'atentamente', 'atte', 'att'];

    /** @var array<string, array<string, true>> */
    private static array $conjuntos = [];

    /**
     * @param  list<string>  $lista
     * @return array<string, true>
     */
    private static function conjunto(string $clave, array $lista): array
    {
        return self::$conjuntos[$clave] ??= array_fill_keys($lista, true);
    }

    /** Minúsculas sin tildes ni marcas combinantes (NFC o NFD); ñ→n. Funciona también sin la extensión intl. */
    private function aNormal(string $palabra): string
    {
        $minuscula = mb_strtolower($palabra, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $minuscula = \Normalizer::normalize($minuscula, \Normalizer::FORM_KD) ?: $minuscula;
        }

        return $this->normalizarSinTildes(preg_replace('/\p{M}+/u', '', $minuscula) ?? $minuscula);
    }

    private function clasePalabra(string $palabra): int
    {
        $nombres = self::conjunto('nombres', array_merge(self::NOMBRES_COMUNES, self::APELLIDOS_COMUNES, self::NOMBRES_MORFOLOGIA_AMBIGUA));
        if (isset($nombres[$palabra])) {
            return self::C_NOMBRE_LISTADO;
        }
        if (isset(self::conjunto('conectores', self::CONECTORES_DE_NOMBRE)[$palabra])) {
            return self::C_CONECTOR;
        }
        // Siglas y etiquetas de una o dos letras («CC», «5G», «UPS») no se tratan como parte de un nombre.
        // (Solo escritura latina: un nombre CJK suele tener dos caracteres por palabra.)
        if (mb_strlen($palabra) <= 2 && preg_match('/^[a-z0-9]+$/', $palabra) === 1) {
            return self::C_NEUTRA;
        }
        $vocabulario = self::conjunto('vocabulario', array_merge(
            self::VOCABULARIO_COMUN, self::TERMINOS_INSTITUCIONALES, self::PALABRAS_NO_PERSONALES, self::TITULOS,
            self::ANCLAS_PRESENTACION, self::ANCLAS_PERSONA, self::ANCLAS_ATENCION, self::VERBOS_SUJETO, self::RELLENO, self::ANCLAS_APODO,
        ));
        if (isset($vocabulario[$palabra]) || LexicoPublico::contiene($palabra)) {
            return self::C_CONOCIDA;
        }

        return self::C_DESCONOCIDA;
    }

    /**
     * Palabras del mensaje con su forma original (¿mayúscula inicial?) y la puntuación como límite.
     * Normaliza compatibilidad Unicode (NFKC), une «D'Angelo» y separa «huatay-cieza», «soy.carlos»,
     * conserva cualquier escritura (CJK, cirílica, árabe) y descompone tildes/diacríticos.
     *
     * 'c' = palabra con mayúscula interna («MateBook», «PowerEdge»); 'a' = sigla en mayúsculas de 3 a 5 letras; 'o' = texto original.
     *
     * @return list<array{n: string, m: bool, l: bool, c: bool, a: bool, o: string}>
     */
    private function palabrasConForma(string $texto): array
    {
        if (class_exists(\Normalizer::class)) {
            $texto = \Normalizer::normalize($texto, \Normalizer::FORM_KC) ?: $texto;
        }
        // Abreviaturas con punto («Hno.», «Ing.», «Av.») no cierran la frase.
        $texto = preg_replace('/\b(Hnos?|Hnas?|Sra?|Srta|Dra?|Ing|Lic|Prof|Mgs?|Mag|Arq|Abog|Jr|Av|Psje|Gral|Cap|Cnel|Mons|Tte|Ud|Uds)\.(?=\s)/u', '$1', $texto) ?? $texto;
        $hayMinuscula = preg_match('/\p{Ll}/u', $texto) === 1;
        $texto = preg_replace(['/(?<=\p{L})[\'’`´](?=\p{L})/u', '/(?<=\p{L})[._\-\/\\\\](?=\p{L})/u'], ['', ' '], $texto) ?? $texto;
        preg_match_all('/[\p{L}\p{M}]+|[^\p{L}\p{M}\s]|\R/u', $texto, $coincidencias);
        $salida = [];
        foreach ($coincidencias[0] as $pieza) {
            if (preg_match('/^[\p{L}\p{M}]/u', $pieza) !== 1) {
                $salida[] = ['n' => self::LIMITE, 'm' => false, 'l' => true, 'c' => false, 'a' => false, 'o' => ''];

                continue;
            }
            if (preg_match('/^[IVXLCDM]{2,}$/', $pieza) === 1) {
                // Numeral romano en mayúsculas («XXIII», «IV»): no es un nombre.
                $salida[] = ['n' => '1', 'm' => false, 'l' => false, 'c' => false, 'a' => false, 'o' => ''];

                continue;
            }
            $normal = $this->aNormal($pieza);
            if ($normal !== '') {
                $salida[] = [
                    'n' => $normal,
                    'm' => preg_match('/^\p{Lu}/u', $pieza) === 1,
                    'l' => false,
                    // «MateBook», «PowerEdge»: mayúscula interna tras minúscula. Se excluyen prefijos de apellido (McDonald, DiCaprio, DeLeón).
                    'c' => preg_match('/^\p{Lu}\p{Ll}+\p{Lu}/u', $pieza) === 1 && preg_match('/^(?:Mc|Mac|Di|De|Da|La|Le|Van|Von|Del|Los)\p{Lu}/u', $pieza) !== 1,
                    // «APEX», «NAS», «SIGA»: sigla de 3 a 5 letras dentro de un mensaje con minúsculas.
                    'a' => $hayMinuscula && preg_match('/^\p{Lu}{3,5}$/u', $pieza) === 1,
                    'o' => $pieza,
                ];
            }
        }

        return $salida;
    }

    /**
     * Nombre de persona detectado por exclusión: dos o más palabras seguidas que el vocabulario público no
     * explica (o nombres frecuentes) forman una secuencia con forma de nombre. No exige ancla ni mayúsculas.
     *
     * Reglas, de mayor a menor evidencia:
     *  a) secuencia de ≥2 palabras «no explicadas» (se admiten partículas de/del/la/los/y entre ellas);
     *  b) un apellido en mayúscula inicial junto a un nombre listado («Ximena Plaza») aunque «plaza» sea vocabulario;
     *  c) ancla fuerte («se llama», «identificado como», «soy» + mayúscula) seguida de una palabra no explicada;
     *  d) mensaje de 1–3 palabras de contenido, todas no explicadas (nombre aislado);
     *  e) texto con letras separadas (≥5 letras sueltas seguidas), técnica de ofuscación.
     */
    private function detectaNombrePorComplementoDeLexico(string $mensaje, bool $nombreAislado = true): bool
    {
        // Un fragmento técnico sin espacios («${jndi:ldap://…}», «{{…}}», un URI) no es un nombre aislado: sus partes
        // son esquema, host o sintaxis. Las reglas (a)–(c) y (e) siguen examinando el mensaje completo, y las reglas de
        // nombre aislado se aplican a lo que queda FUERA de esos fragmentos, de modo que anteponer «${x}» no exime nada.
        if ($nombreAislado && preg_match('~\S*(?:://|\$\{|#\{|\{\{)\S*~u', $mensaje) === 1) {
            $resto = preg_replace('~\S*(?:://|\$\{|#\{|\{\{)\S*~u', ' . ', $mensaje) ?? '';
            // Dentro del fragmento, «.», «/» y «:» separan componentes (host, ruta, esquema); el guion sigue uniendo
            // palabras, así que «…/in/carlos-mendoza-ruiz» conserva la secuencia y se bloquea.
            $separado = preg_replace_callback('~\S*(?:://|\$\{|#\{|\{\{)\S*~u',
                static fn (array $m): string => preg_replace('~[./:]~u', ' , ', $m[0]) ?? $m[0], $mensaje) ?? $mensaje;

            return $this->detectaNombrePorComplementoDeLexico($separado, false)
                || (trim($resto, " .\t\n\r") !== '' && $this->detectaNombrePorComplementoDeLexico($resto));
        }

        $e = $this->palabrasConForma($mensaje);
        $total = count($e);
        if ($total === 0) {
            return false;
        }

        // (e) letras sueltas «Y A N E T H».
        $sueltas = 0;
        foreach ($e as $x) {
            $sueltas = ! $x['l'] && mb_strlen($x['n']) === 1 ? $sueltas + 1 : 0;
            if ($sueltas >= 5) {
                return true;
            }
        }

        $clase = [];
        foreach ($e as $i => $x) {
            $clase[$i] = $x['l'] ? self::C_NEUTRA : $this->clasePalabra($x['n']);
        }

        // Producto con mayúscula interna («MateBook») y sigla aislada («Oracle APEX»): no son parte de un nombre de persona.
        // Una sigla pegada a otra palabra capitalizada no explicada («ELIO SAMPÉN») sí puede serlo y se conserva.
        for ($i = 0; $i < $total; $i++) {
            if ($clase[$i] !== self::C_DESCONOCIDA) {
                continue;
            }
            if ($e[$i]['c']) {
                // «MateBook» = mate + book (vocabulario) → producto. «JuanPerez» o «TamaraQuilcate» se escriben igual, pero sus partes
                // son nombres: se reparte la palabra y solo se exime si alguna parte es vocabulario y ninguna es un nombre listado.
                $partes = preg_split('/(?=\p{Lu})/u', $e[$i]['o'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $hayVocabulario = false;
                $hayNombre = false;
                foreach ($partes as $parte) {
                    $normal = $this->aNormal($parte);
                    $claseParte = $this->clasePalabra($normal);
                    $hayNombre = $hayNombre || $claseParte === self::C_NOMBRE_LISTADO;
                    $hayVocabulario = $hayVocabulario || ($claseParte === self::C_CONOCIDA && mb_strlen($normal) >= 3);
                }
                if ($hayVocabulario && ! $hayNombre) {
                    $clase[$i] = self::C_NEUTRA;
                }
            } elseif ($e[$i]['a']) {
                $vecinoNombre = static fn (int $j): bool => isset($clase[$j]) && $e[$j]['m'] && ! $e[$j]['l']
                    && ($clase[$j] === self::C_DESCONOCIDA || $clase[$j] === self::C_NOMBRE_LISTADO);
                if (! $vecinoNombre($i - 1) && ! $vecinoNombre($i + 1)) {
                    $clase[$i] = self::C_NEUTRA;
                }
            }
        }

        // Nombres de entidad, lugar o producto introducidos por su sustantivo («Colegio Pedro Ruiz Gallo», «Río Chonta»).
        foreach ($this->tramosDeEntidad($e, $clase) as $i) {
            $clase[$i] = self::C_NEUTRA;
        }

        // (b) un nombre listado y un término nominal adyacente; la minúscula no elimina la ambigüedad.
        $institucional = self::conjunto('institucional', array_merge(self::TERMINOS_INSTITUCIONALES, self::PALABRAS_NO_PERSONALES));
        $funcionales = self::conjunto('contexto_no_nominal', array_merge(self::VOCABULARIO_COMUN, self::TITULOS));
        $secuenciaNominal = preg_match('/\A\s*[¿¡]?\p{L}+(?:\s+\p{L}+){1,3}\s*[.!?]?\s*\z/u', $mensaje) === 1;
        foreach ($e as $indice => $palabra) {
            if (! $palabra['l'] && $clase[$indice] === self::C_NEUTRA) {
                $secuenciaNominal = false;
                break;
            }
        }
        for ($i = 0; $i < $total; $i++) {
            if ($clase[$i] !== self::C_NOMBRE_LISTADO) {
                continue;
            }
            $sig = $i + 1;
            if ($sig < $total && $clase[$sig] === self::C_CONOCIDA && $e[$sig]['m']
                && ! isset($institucional[$e[$sig]['n']])) {
                $clase[$sig] = self::C_NOMBRE_LISTADO;
            }
            $ant = $i - 1;
            if ($ant >= 0 && $clase[$ant] === self::C_CONOCIDA
                && (($secuenciaNominal && ! isset($funcionales[$e[$ant]['n']])) || ($ant > 0 && $e[$ant]['m'] && ! $e[$ant - 1]['l']))
                && ! isset($institucional[$e[$ant]['n']])) {
                $clase[$ant] = self::C_NOMBRE_LISTADO;
            }
        }

        // (b2) palabra de vocabulario común en mayúscula, a mitad de frase y pegada a una palabra no explicada: es un apellido
        // corriente («Bruno Alcalde Pinedo»). No aplica a nombres de organización («Hotel Casa Andina», «Taller Nébula»).
        $organizaciones = self::conjunto('organizaciones', self::SUSTANTIVOS_ORGANIZACION);
        $marcadas = [];
        for ($k = 1; $k < $total; $k++) {
            if ($clase[$k] !== self::C_CONOCIDA || ! $e[$k]['m'] || $e[$k - 1]['l'] || isset($institucional[$e[$k]['n']]) || isset($organizaciones[$e[$k]['n']])) {
                continue;
            }
            // La vecina no explicada también va en mayúscula: una minúscula («pierde», «necesita») es un verbo o palabra rara, no un nombre.
            $junto = ($clase[$k - 1] === self::C_DESCONOCIDA && $e[$k - 1]['m'])
                || (isset($clase[$k + 1]) && $clase[$k + 1] === self::C_DESCONOCIDA && $e[$k + 1]['m']);
            if ($junto && ! $this->enNombreDeOrganizacion($e, $clase, $k, $organizaciones)) {
                $marcadas[] = $k;
            }
        }
        foreach ($marcadas as $k) {
            $clase[$k] = self::C_NOMBRE_LISTADO;
        }

        // (a) secuencia de palabras con forma de nombre.
        $racha = 0;
        $conectores = 0;
        for ($i = 0; $i < $total; $i++) {
            $c = $clase[$i];
            if ($c === self::C_NOMBRE_LISTADO || $c === self::C_DESCONOCIDA) {
                $racha++;
                $conectores = 0;
                if ($racha >= 2) {
                    return true;
                }
            } elseif ($c === self::C_CONECTOR && $racha > 0 && $conectores < 2) {
                $conectores++;
            } else {
                $racha = 0;
                $conectores = 0;
            }
        }

        // (c) ancla fuerte + palabra con forma de nombre.
        $rellenos = self::conjunto('rellenos_ancla', array_merge(self::RELLENO, self::TITULOS, self::ANCLAS_PERSONA, ['como', 'personal', 'particular', 'persona', 'senor', 'senora']));
        for ($i = 0; $i < $total; $i++) {
            $n = $e[$i]['n'];
            $esSoy = preg_match('/^[sz]o+(?:y+|i+)$/', $n) === 1;
            if (! $esSoy && ! in_array($n, self::ANCLAS_NOMBRE_FUERTE, true)) {
                continue;
            }
            $j = $i + 1;
            $saltos = 0;
            while ($j < $total && $saltos < 5 && ($e[$j]['l'] && in_array($n, ['atentamente', 'atte', 'att'], true) ? true : isset($rellenos[$e[$j]['n']]))) {
                $j++;
                $saltos++;
            }
            if ($j < $total && ! $e[$j]['l'] && ($clase[$j] === self::C_NOMBRE_LISTADO || $clase[$j] === self::C_DESCONOCIDA)
                && (! $esSoy || $e[$j]['m'])) {
                return true;
            }
        }

        // (d) nombre aislado: 1–3 palabras de contenido, todas con forma de nombre.
        $cortesia = self::conjunto('cortesia', self::CORTESIA);
        $contenido = 0;
        $todasNombre = true;
        foreach ($e as $i => $x) {
            if ($x['l'] || $clase[$i] === self::C_NEUTRA || $clase[$i] === self::C_CONECTOR || isset($cortesia[$x['n']])) {
                continue;
            }
            $contenido++;
            if ($clase[$i] !== self::C_NOMBRE_LISTADO && $clase[$i] !== self::C_DESCONOCIDA) {
                $todasNombre = false;
            }
        }

        if ($nombreAislado && $contenido >= 1 && $contenido <= 3 && $todasNombre) {
            return true;
        }

        // (d') secuencia corta nominal: el diccionario no descarta un nombre con apellido común.
        // Excluir vocabulario funcional/público, en lugar de exigir mayúsculas al visitante.
        if ($nombreAislado && $contenido >= 2 && $contenido <= 3) {
            $hayNombre = false;
            $restoCapitalizado = true;
            foreach ($e as $i => $x) {
                if ($x['l'] || $clase[$i] === self::C_NEUTRA || $clase[$i] === self::C_CONECTOR || isset($cortesia[$x['n']])) {
                    continue;
                }
                if ($clase[$i] === self::C_NOMBRE_LISTADO || $clase[$i] === self::C_DESCONOCIDA) {
                    $hayNombre = true;
                } elseif (isset($institucional[$x['n']])
                    || ((! $x['m'] || $i === 0 || $e[$i - 1]['l']) && (! $secuenciaNominal || isset($funcionales[$x['n']])))) {
                    $restoCapitalizado = false;
                }
            }
            if ($hayNombre && $restoCapitalizado) {
                return true;
            }
        }

        return false;
    }

    /**
     * Índices de las palabras que componen el NOMBRE de una entidad, lugar o producto: la secuencia capitalizada (con partículas
     * de/del/la…) que sigue a un sustantivo de entidad, admitiendo hasta tres palabras de enlace en minúscula («Institución
     * Educativa Pedro Ruiz Gallo», «Junta de Regantes del Río Chonta»). Solo cuenta si hay al menos una palabra en mayúscula
     * inicial; un nombre en minúsculas tras «empresa» NO se exime.
     *
     * @param  list<array{n: string, m: bool, l: bool, c: bool, a: bool, o: string}>  $e
     * @param  array<int, int>  $clase
     * @return list<int>
     */
    private function tramosDeEntidad(array $e, array $clase): array
    {
        $entidades = self::conjunto('organizaciones', self::SUSTANTIVOS_ORGANIZACION);
        $total = count($e);
        $indices = [];
        for ($o = 0; $o < $total; $o++) {
            if ($e[$o]['l'] || ! isset($entidades[$e[$o]['n']])) {
                continue;
            }
            // Palabras de enlace en minúscula (educativa, de madres, ficticia…) hasta la primera mayúscula.
            $k = $o + 1;
            $enlaces = [];
            // Un verbo o cópula («es», «se llama», «necesita») rompe el enlace: «mi contacto en la empresa ES Juan Pérez» no es una entidad.
            while ($k < $total && ! $e[$k]['l'] && ! $e[$k]['m'] && count($enlaces) < 3
                && ($clase[$k] === self::C_CONOCIDA || $clase[$k] === self::C_CONECTOR || $clase[$k] === self::C_NEUTRA)
                && ! in_array($e[$k]['n'], self::VERBOS_DE_ENLACE, true) && ! in_array($e[$k]['n'], self::VERBOS_SUJETO, true)) {
                $enlaces[] = $k;
                $k++;
            }
            if ($k >= $total || $e[$k]['l'] || ! $e[$k]['m']) {
                continue;
            }
            $tramo = [];
            $ultimaMayuscula = $k;
            for ($t = $k; $t < $total && ! $e[$t]['l'] && count($tramo) < 7; $t++) {
                // «Empresa Nube y Persona Apellido»: la coordinación inicia otra entidad,
                // nunca amplía la exención de la primera a una persona adicional.
                if ($e[$t]['n'] === 'y') {
                    break;
                }
                if ($e[$t]['m']) {
                    $ultimaMayuscula = $t;
                } elseif ($clase[$t] !== self::C_CONECTOR) {
                    break;
                }
                $tramo[] = $t;
            }
            $indices[] = $o;
            foreach ($enlaces as $x) {
                $indices[] = $x;
            }
            foreach ($tramo as $t) {
                if ($t <= $ultimaMayuscula) {
                    $indices[] = $t;
                }
            }
        }

        return $indices;
    }

    /**
     * ¿La palabra $k forma parte de un nombre de organización? Sí si la secuencia capitalizada que la contiene empieza por un
     * sustantivo de organización («Taller Nébula») o va precedida por uno («la cooperativa ficticia Horizonte …»).
     *
     * @param  list<array{n: string, m: bool, l: bool, c: bool, a: bool, o: string}>  $e
     * @param  array<int, int>  $clase
     * @param  array<string, true>  $organizaciones
     */
    private function enNombreDeOrganizacion(array $e, array $clase, int $k, array $organizaciones): bool
    {
        $inicio = $k;
        while ($inicio > 0 && ! $e[$inicio - 1]['l'] && ($e[$inicio - 1]['m'] || $clase[$inicio - 1] === self::C_CONECTOR)) {
            $inicio--;
        }
        // El artículo inicial («La ONG …», «el Hotel …») no forma parte del nombre.
        while ($inicio < $k && $clase[$inicio] === self::C_CONECTOR) {
            $inicio++;
        }
        if (isset($organizaciones[$e[$inicio]['n']])) {
            return true;
        }
        $p = $inicio - 1;
        while ($p >= 0 && in_array($e[$p]['n'], ['ficticio', 'ficticia', 'llamado', 'llamada', 'denominado', 'denominada', 'titulado', 'titulada', 'nombre', 'de', 'del', 'la', 'el', 'los', 'las'], true)) {
            $p--;
        }

        return $p >= 0 && ! $e[$p]['l'] && isset($organizaciones[$e[$p]['n']]);
    }

    /**
     * Retira las frases con que el visitante rechaza compartir un dato («sin dar mis datos», «prefiero no escribir
     * mi dirección aquí»). Quien dice que no lo comparte no lo comparte; sin esto, «mis datos» o «mi dirección»
     * bloquearían una consulta que justamente protege la privacidad.
     */
    private function neutralizaRechazoDeDatos(string $mensaje): string
    {
        $dato = '(?:datos(?:\s+personales)?|direcci[oó]n|domicilio|tel[eé]fono|dni|ruc|correo|informaci[oó]n(?:\s+personal)?|nombre|n[uú]mero)';
        $verbo = '(?:dar|compartir|escribir|enviar|mandar|poner|decir|indicar|colocar|revelar|adjuntar|brindar|ingresar|dejar|publicar)';
        $ubicacion = '(?:\s+(?:aqu[ií]|ac[aá]|por\s+(?:este|el)\s+chat|por\s+aqu[ií]|en\s+(?:este\s+)?chat))?';

        return preg_replace(
            '/\b(?:sin|no)\s+(?:(?:quiero|deseo|puedo|debo|voy\s+a|necesito|tengo\s+que|tener\s+que)\s+)?'.$verbo.'(?:me|te|lo|los)?\s+(?:(?:mis|mi|sus|tus)\s+)?'.$dato.$ubicacion.'/iu',
            ' ',
            $mensaje,
        ) ?? $mensaje;
    }

    private function detectaCoincidenciaAmbigua(string $mensaje): bool
    {
        // Iniciales personales (p. ej. "M. P.", "J. Pérez", "María P.").
        if (preg_match('/\b[A-ZÁÉÍÓÚÜÑ]\.\s*[A-ZÁÉÍÓÚÜÑ]\.?/u', $mensaje) === 1
            || preg_match('/\b[A-ZÁÉÍÓÚÜÑ][a-záéíóúüñ]{1,20}\s+[A-ZÁÉÍÓÚÜÑ]\.(?:\s|$)/u', $mensaje) === 1) {
            return true;
        }

        // Preguntar cómo se tratan los datos («¿Usan mis datos para publicidad?») no entrega ninguno.
        if (preg_match('/[¿?]/u', $mensaje) === 1) {
            $mensaje = preg_replace('/\b(?:usan|usar|utilizan|guardan|almacenan|protegen|comparten|venden|tratan|manejan|cuidan|publican|recopilan|piden|necesitan|requieren|ven)\s+(?:mis|mi|los|sus|tus)?\s*(?:datos(?:\s+personales)?|informaci[oó]n(?:\s+personal)?)/iu', ' ', $mensaje) ?? $mensaje;
        }

        // Referencias a un trámite propio dentro de una pregunta sin cifras («¿Dónde veo mi certificado?», «¿puedo pedir la devolución de mi
        // pago?») no entregan ningún dato; en afirmación («mi comprobante ya está enviado») siguen siendo ambiguas.
        if (preg_match('/[¿?]/u', $mensaje) === 1 && preg_match('/\d/u', $mensaje) !== 1) {
            $mensaje = preg_replace('/\bmi\s+(?:pago|certificado|cotizaci[oó]n|boleta|factura|recibo|comprobante|voucher|matr[ií]cula|inscripci[oó]n|cuenta|usuario|perfil|c[oó]digo)\b/iu', ' ', $mensaje) ?? $mensaje;
        }

        // Referencias ambiguas a expedientes, trámites o datos propios del visitante.
        if (preg_match('/\b(mis\s+datos|datos\s+personales|mi\s+informaci[oó]n|mi\s+expediente|mi\s+reclamo|mi\s+queja|mi\s+cotizaci[oó]n|mi\s+certificado|mi\s+boleta|mi\s+factura|mi\s+recibo|mi\s+voucher|mi\s+comprobante|mi\s+pago|mi\s+deuda|mi\s+nota|mis\s+notas|mi\s+promedio|(?<!registrar\s)(?<!registro\s)(?<!marcar\s)(?<!marco\s)mi\s+asistencia|mi\s+c[oó]digo|mi\s+usuario|mi\s+perfil|mi\s+cuenta|te\s+dejo\s+mis|adjunto\s+mi|env[ií]o\s+mis)\b/iu', $mensaje) === 1) {
            return true;
        }

        // «mi matrícula 2026», «mi matrícula es …»: número o valor de un expediente propio.
        if (preg_match('/\bmi\s+matr[ií]cula\s*(?:[:=#]|es\b|n[°º.]|\d)/iu', $mensaje) === 1) {
            return true;
        }

        // Identificadores con guion bajo, enlaces o pares clave-valor sospechosos.
        if (preg_match('/https?:\/\/|www\.|\b[a-z0-9]+_[a-z0-9_]+\b/iu', $mensaje) === 1) {
            return true;
        }

        if (preg_match('/\b(nombre|apellido|telefono|celular|direccion|domicilio|usuario|contacto|id|codigo|expediente)\s*[:=]\s*\S+/iu', $mensaje) === 1) {
            return true;
        }

        // Secuencias en MAYÚSCULAS de dos o más palabras fuera del vocabulario institucional.
        if (preg_match_all('/\b[A-ZÁÉÍÓÚÜÑ]{3,}(?:[\s\-]+[A-ZÁÉÍÓÚÜÑ]{3,})+\b/u', $mensaje, $mayusculas) > 0) {
            foreach ($mayusculas[0] as $bloque) {
                $palabras = preg_split('/[\s\-]+/u', $this->normalizarSinTildes($bloque), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($palabras as $palabra) {
                    // Vocabulario conocido en mayúsculas («DROP TABLE», «ASESORÍA INTEGRAL») no es un nombre gritado.
                    $clase = $this->clasePalabra($palabra);
                    if ($clase === self::C_NOMBRE_LISTADO || $clase === self::C_DESCONOCIDA) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function normalizarSinTildes(string $texto): string
    {
        return strtr(mb_strtolower($texto, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ]);
    }
}
