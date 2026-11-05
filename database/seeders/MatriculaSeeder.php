<?php

namespace Database\Seeders;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Database\Seeder;

class MatriculaSeeder extends Seeder
{
    public function run(): void
    {
        $matriculas = [
            ['70000001', 'CUR-001', 0, 'pagada'],
            ['70000002', 'CUR-001', 1, 'pagada'],
            ['70000003', 'CUR-002', 1, 'pendiente'],
            ['70000004', 'CUR-003', 2, 'pagada'],
            ['70000005', 'CUR-004', 2, 'pagada'],
            ['70000006', 'CUR-005', 3, 'pendiente'],
            ['70000007', 'CUR-006', 3, 'pagada'],
            ['70000008', 'CUR-007', 4, 'pagada'],
            ['70000009', 'CUR-008', 4, 'pendiente'],
            ['70000010', 'CUR-001', 5, 'pagada'],
            ['70000001', 'CUR-003', 5, 'pagada'],
            ['70000002', 'CUR-005', 5, 'pendiente'],
            ['70000003', 'CUR-007', 4, 'pagada'],
            ['70000004', 'CUR-002', 3, 'pagada'],
            ['70000005', 'CUR-006', 2, 'pagada'],
        ];

        foreach ($matriculas as [$dni, $codigoCurso, $mesesAtras, $estado]) {
            $estudiante = Estudiante::where('dni', $dni)->firstOrFail();
            $curso = Curso::where('codigo', $codigoCurso)->firstOrFail();

            Matricula::updateOrCreate(
                [
                    'estudiante_id' => $estudiante->id,
                    'curso_id' => $curso->id,
                ],
                [
                    'fecha_matricula' => now()->subMonths($mesesAtras),
                    'estado' => $estado,
                    'monto_pagado' => $estado === 'pagada' ? $curso->precio : null,
                    'observaciones' => null,
                ],
            );
        }

        Curso::query()
            ->withCount(['matriculas as matriculas_activas_count' => fn ($query) => $query->where('estado', '!=', 'anulada')])
            ->each(function (Curso $curso): void {
                $curso->cupos_disponibles = max(0, $curso->cupos_maximos - $curso->getAttribute('matriculas_activas_count'));
                $curso->save();
            });
    }
}
