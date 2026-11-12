<?php

namespace Database\Seeders;

use App\Models\Curso;
use Illuminate\Database\Seeder;

class CursoSeeder extends Seeder
{
    public function run(): void
    {
        $cursos = [
            [
                'codigo' => 'CUR-001',
                'nombre' => 'Ofimática y herramientas digitales',
                'descripcion' => 'Capacitación práctica en herramientas de oficina, documentos, hojas de cálculo y presentaciones.',
                'categoria' => 'tecnologia',
                'duracion_horas' => 40,
                'precio' => 350,
                'modalidad' => 'presencial',
                'horario' => 'Lun-Mié, 18:00-20:00',
                'cupos_maximos' => 30,
            ],
            [
                'codigo' => 'CUR-002',
                'nombre' => 'Diseño gráfico digital',
                'descripcion' => 'Fundamentos de diseño, composición y producción de piezas gráficas para medios digitales.',
                'categoria' => 'tecnologia',
                'duracion_horas' => 48,
                'precio' => 480,
                'modalidad' => 'hibrido',
                'horario' => 'Mar-Jue, 18:00-20:00',
                'cupos_maximos' => 25,
            ],
            [
                'codigo' => 'CUR-003',
                'nombre' => 'Asistente de educación inicial',
                'descripcion' => 'Estrategias de acompañamiento, desarrollo infantil y apoyo en el aula de educación inicial.',
                'categoria' => 'educacion',
                'duracion_horas' => 60,
                'precio' => 520,
                'modalidad' => 'presencial',
                'horario' => 'Sábados, 09:00-13:00',
                'cupos_maximos' => 30,
            ],
            [
                'codigo' => 'CUR-004',
                'nombre' => 'Primeros auxilios básicos',
                'descripcion' => 'Principios de prevención y respuesta inicial ante emergencias y accidentes comunes.',
                'categoria' => 'salud',
                'duracion_horas' => 24,
                'precio' => 280,
                'modalidad' => 'presencial',
                'horario' => 'Sábados, 14:00-18:00',
                'cupos_maximos' => 20,
            ],
            [
                'codigo' => 'CUR-005',
                'nombre' => 'Gestión de pequeños negocios',
                'descripcion' => 'Herramientas de planificación, organización y control para emprendimientos y pequeños negocios.',
                'categoria' => 'gestion',
                'duracion_horas' => 36,
                'precio' => 390,
                'modalidad' => 'virtual',
                'horario' => 'Lun-Mié, 19:00-21:00',
                'cupos_maximos' => 25,
            ],
            [
                'codigo' => 'CUR-006',
                'nombre' => 'Marketing digital para emprendedores',
                'descripcion' => 'Introducción a estrategias de contenido, presencia digital y promoción de productos y servicios.',
                'categoria' => 'gestion',
                'duracion_horas' => 32,
                'precio' => 420,
                'modalidad' => 'hibrido',
                'horario' => 'Mar-Jue, 19:00-21:00',
                'cupos_maximos' => 25,
            ],
            [
                'codigo' => 'CUR-007',
                'nombre' => 'Instalaciones eléctricas domiciliarias',
                'descripcion' => 'Conceptos de seguridad y fundamentos para instalaciones eléctricas de uso residencial.',
                'categoria' => 'tecnologia',
                'duracion_horas' => 50,
                'precio' => 560,
                'modalidad' => 'presencial',
                'horario' => 'Sábados, 08:00-13:00',
                'cupos_maximos' => 20,
            ],
            [
                'codigo' => 'CUR-008',
                'nombre' => 'Atención al cliente y comunicación efectiva',
                'descripcion' => 'Técnicas de comunicación, escucha activa y atención de calidad al usuario.',
                'categoria' => 'otros',
                'duracion_horas' => 20,
                'precio' => 240,
                'modalidad' => 'virtual',
                'horario' => 'Vie, 18:00-21:00',
                'cupos_maximos' => 30,
            ],
        ];

        foreach ($cursos as $curso) {
            Curso::updateOrCreate(
                ['codigo' => $curso['codigo']],
                [...$curso, 'cupos_disponibles' => $curso['cupos_maximos'], 'estado' => 'activo'],
            );
        }
    }
}
