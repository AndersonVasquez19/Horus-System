<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\ServicioPublicacion;
use App\Models\ServicioPublicacionElemento;
use App\Models\ServicioTecnologico;
use App\Support\PaginasServicios;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa, como BORRADOR, el contenido vigente de las tres páginas públicas de Servicios Tecnológicos.
 *
 * - Idempotente: si el servicio ya tiene ficha editorial no se toca (nunca pisa ediciones de Gerencia).
 * - No publica nada: el servicio se crea inactivo y la ficha en borrador; Gerencia revisa, activa y autoriza.
 * - No sube ni copia archivos: reutiliza las rutas originales de public/assets/images (no se borran jamás).
 * - No forma parte de DatabaseSeeder: se ejecuta de forma explícita y solo con autorización.
 *
 * Uso (cuando se autorice): php artisan db:seed --class=ServiciosTecnologicosContenidoSeeder
 */
class ServiciosTecnologicosContenidoSeeder extends Seeder
{
    public function run(): void
    {
        $hoy = now('America/Lima');
        foreach (self::definiciones() as $codigo => $definicion) {
            DB::transaction(function () use ($codigo, $definicion, $hoy): void {
                $servicio = ServicioTecnologico::query()->where('codigo', $codigo)->lockForUpdate()->first();
                if ($servicio === null) {
                    $servicio = ServicioTecnologico::forceCreate([
                        'codigo' => $codigo, 'identificador_publico' => (string) Str::uuid(), 'nombre' => $definicion['nombre'],
                        'categoria' => $codigo, 'estado' => 'inactivo', 'revision' => 1,
                    ]);
                }
                if (ServicioPublicacion::query()->where('servicio_tecnologico_id', $servicio->id)->exists()) {
                    return;
                }
                $ficha = ServicioPublicacion::forceCreate([
                    'servicio_tecnologico_id' => $servicio->id, 'titulo' => $definicion['titulo'],
                    'resumen' => explode("\n\n", $definicion['descripcion'])[0], 'descripcion' => $definicion['descripcion'],
                    'categoria' => $codigo, 'estado' => 'borrador',
                    'vigente_desde' => $hoy->toDateString(), 'vigente_hasta' => $hoy->copy()->addYears(5)->toDateString(),
                    'titulo_tarjetas' => $definicion['titulo_tarjetas'], 'imagen_principal_ruta' => $definicion['imagen'],
                    'imagen_principal_alt' => $definicion['imagen_alt'],
                ]);
                foreach ($definicion['tarjetas'] as $orden => $tarjeta) {
                    $elemento = new ServicioPublicacionElemento([
                        'titulo' => $tarjeta['titulo'], 'descripcion' => $tarjeta['descripcion'], 'caracteristicas' => $tarjeta['caracteristicas'] ?? [],
                        'icono' => $tarjeta['icono'] ?? null, 'variante_imagen' => $tarjeta['variante'] ?? null,
                        'imagen_alt' => $tarjeta['alt'] ?? null, 'orden' => $orden + 1, 'activo' => true,
                    ]);
                    $elemento->servicio_publicacion_id = $ficha->id;
                    $elemento->imagen_ruta = $tarjeta['imagen'] ?? null;
                    $elemento->save();
                }
                $servicio->revision++;
                $servicio->save();
                AuditLog::record('servicio_contenido_inicial_importado', ServicioTecnologico::class, $servicio->id,
                    ['revision' => $servicio->revision, 'tarjetas' => count($definicion['tarjetas'])]);
            });
        }
    }

    /**
     * Contenido tomado de las vistas Blade estáticas (textos, orden, íconos e imágenes originales).
     *
     * @return array<string, array{nombre: string, titulo: string, descripcion: string, imagen: string, imagen_alt: string, titulo_tarjetas: string|null, tarjetas: list<array<string, mixed>>}>
     */
    public static function definiciones(): array
    {
        $categorias = [
            ['Categoría 1', 'categoria1.jpg', 'Diseñada para comunicaciones telefónicas analógicas. No es apta para transmisión de datos modernos debido a sus limitadas capacidades de frecuencia.', 'Velocidad máx: 512 kbit/s'],
            ['Categoría 2', 'categoria2.webp', 'Utilizada en redes Token Ring de primera generación. Soporta transmisión de voz y datos básicos en entornos de baja demanda.', 'Velocidad máx: 4 Mbit/s'],
            ['Categoría 3', 'categoria3.webp', 'Estándar para redes Ethernet 10BaseT. Ampliamente utilizada en instalaciones de voz y datos en edificios de oficinas durante los años 90.', 'Velocidad máx: 10 Mbit/s'],
            ['Categoría 4', 'categoria4.jpg', 'Optimizada para redes Token Ring de alta velocidad. Ofrece mayor ancho de banda que su predecesora, con mejor rendimiento en entornos corporativos.', 'Velocidad máx: 16 Mbit/s'],
            ['Categoría 5', 'categoria5.jpeg', 'Estándar para redes Fast Ethernet y Gigabit. Ideal para infraestructuras de red modernas que requieren alta velocidad y confiabilidad en la transmisión.', 'Velocidad máx: 100 Mbit/s'],
            ['Categoría 6', 'categoria6.jpg', 'Solución de alto rendimiento para centros de datos y redes empresariales. Soporta aplicaciones de video en alta definición y comunicaciones críticas.', 'Velocidad máx: 10 Gbit/s'],
        ];
        $camaras = [
            ['CÁMARAS IP', 'camara-ip.jpg', 'producto', 'fas fa-video', 'Cámara IP giratoria con conexión Wi-Fi', 'Garantizamos la seguridad de sus espacios con tecnología de última generación.'],
            ['CÁMARAS ANALÓGICAS', 'camara-analogica.jpg', 'producto', 'fas fa-video', 'Cámara analógica HD tipo bala', 'Es la solución ideal para quienes buscan un sistema de vigilancia estable, económico y de larga duración.'],
            ['CÁMARAS FULL HD', 'camara-full-hd.jpg', 'producto', 'fas fa-video', 'Cámara Full HD con visión infrarroja', 'Si lo que buscas es que la imagen no se "pixele" al hacer zoom, la tecnología Full HD es la opción indispensable.'],
            ['CÁMARAS DOMO', 'camara-domo.jpg', 'producto', 'fas fa-video', 'Cámara domo de vigilancia para interiores', 'Si necesitas vigilar interiores (oficinas, tiendas o casas) de forma elegante y resistente, la Cámara Domo es la elección profesional.'],
            ['CÁMARAS DE 360°', 'camara-360.jpg', 'escena', 'fas fa-video', 'Cámara de seguridad de 360 grados montada en el techo', 'Si quieres ver todo el lugar al mismo tiempo con un diseño discreto en el techo, la Cámara de 360° es la opción más eficiente.'],
            ['CÁMARAS FIJAS (TUBO O BALA)', 'camara-tubo-bala.jpg', 'producto', 'fas fa-video', 'Cámara fija tipo bala para exteriores', 'Si necesitas que la gente sepa que la estás vigilando y quieres proteger exteriores o pasillos largos, la Cámara de Bala es la mejor herramienta.'],
            ['Sensores de Movimiento', 'sensor-movimiento.jpg', 'escena', 'fas fa-wifi', 'Sensor de movimiento instalado en la pared', 'Añaden una capa de inteligencia a su sistema de seguridad, permitiendo que la tecnología actúe por usted.'],
            ['Alarma vía Email', 'alarma-email.jpg', 'escena', 'fas fa-envelope', 'Ilustración de una alarma con aviso remoto', 'Transforme su sistema de vigilancia en un centro de respuesta rápida.'],
            ['Visión Nocturna', 'vision-nocturna.jpg', 'producto', 'fas fa-moon', 'Cámara tipo bala con visión nocturna', 'Garantiza que la oscuridad no sea un obstáculo para la seguridad de sus espacios.'],
            ['Visualización desde tu Computadora o Celular', 'visualizacion-dispositivos.jpg', 'escena', 'fas fa-mobile-alt', 'Tableta mostrando varias cámaras de vigilancia en vivo', 'Manténgase conectado con lo que más le importa a través de sus dispositivos personales.'],
        ];

        return [
            PaginasServicios::CABLEADO => [
                'nombre' => 'Cableado estructurado',
                'titulo' => 'CABLEADO ESTRUCTURADO',
                'descripcion' => 'Diseñamos e implementamos sistemas de cableado estructurado (UTP/STP) y redes LAN bajo estrictas normas técnicas para garantizar una interconectividad eficiente y de alta velocidad entre todas las áreas de su empresa.',
                'imagen' => 'assets/images/tecnologias/cableado.jpg',
                'imagen_alt' => 'Instalacion de cableado ',
                'titulo_tarjetas' => 'CATEGORÍAS DE CABLEADO',
                'tarjetas' => array_map(fn (array $c): array => [
                    'titulo' => $c[0], 'descripcion' => $c[2], 'caracteristicas' => [$c[3]], 'icono' => 'fas fa-bolt',
                    'imagen' => 'assets/images/tecnologias/'.$c[1], 'alt' => $c[0],
                ], $categorias),
            ],
            PaginasServicios::VIDEOVIGILANCIA => [
                'nombre' => 'Sistema de videovigilancia',
                'titulo' => 'SISTEMA DE VIDEOVIGILANCIA',
                'descripcion' => 'Desarrollamos proyectos de seguridad integral diseñados para proteger su hogar, empresa o institución. Nuestras soluciones avanzadas le permiten mantener el control absoluto de sus espacios, sin importar dónde se encuentre.',
                'imagen' => 'assets/images/videovigilancia/camera.webp',
                'imagen_alt' => 'Sistema de videovigilancia',
                'titulo_tarjetas' => null,
                'tarjetas' => array_map(fn (array $c): array => [
                    'titulo' => $c[0], 'descripcion' => $c[5], 'caracteristicas' => [], 'icono' => $c[3], 'variante' => $c[2],
                    'imagen' => 'assets/images/videovigilancia/'.$c[1], 'alt' => $c[4],
                ], $camaras),
            ],
            PaginasServicios::SOPORTE => [
                'nombre' => 'Soporte y mantenimiento',
                'titulo' => 'SOPORTE Y MANTENIMIENTO',
                'descripcion' => "Contamos con un gran equipo de trabajo en TI donde te ofrecemos soluciones físicas (hardware) o lógicas (software) de PC, sistemas y accesorios.\n\nTe ofrecemos mantenimiento preventivo y correctivo en tus equipos informáticos donde te ayuden a seguir con la productividad de estos.",
                'imagen' => 'assets/images/soporte-mantenimiento/img-intro.png',
                'imagen_alt' => '',
                'titulo_tarjetas' => 'Nuestros Servicios',
                'tarjetas' => [
                    [
                        'titulo' => 'CONFIGURACIÓN E INSTALACIÓN', 'icono' => 'fas fa-cogs',
                        'descripcion' => 'Nos encargamos de dejar todos sus equipos y sistemas correctamente configurados y listos para su uso inmediato, garantizando un funcionamiento óptimo desde el primer día.',
                        'caracteristicas' => [
                            'Configuración y sincronización de correos electrónicos corporativos.',
                            'Instalación y configuración de impresoras y optimización de la red Wi-Fi en oficinas.',
                            'Preparación e instalación de equipos para nuevos colaboradores.',
                        ],
                    ],
                    [
                        'titulo' => 'MANTENIMIENTO PREVENTIVO', 'icono' => 'fas fa-tools',
                        'descripcion' => 'Implementamos acciones preventivas para evitar fallas técnicas y prolongar la vida útil de sus equipos informáticos.',
                        'caracteristicas' => [
                            'Limpieza interna y externa de equipos para prevenir daños por polvo y sobrecalentamiento.',
                            'Actualización de sistema operativo y software antivirus.',
                            'Verificación y supervisión de copias de seguridad (backups).',
                        ],
                    ],
                    [
                        'titulo' => 'ASESORÍA Y CAPACITACIÓN', 'icono' => 'fas fa-graduation-cap',
                        'descripcion' => 'Brindamos orientación especializada para que su equipo aproveche al máximo las herramientas tecnológicas disponibles.',
                        'caracteristicas' => [
                            'Capacitación en el uso de programas y sistemas informáticos.',
                            'Asesoramiento en la compra de equipos según necesidades y presupuesto.',
                            'Formación personalizada para optimizar procesos tecnológicos.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
