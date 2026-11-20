<?php

declare(strict_types=1);

use App\Support\Entrada;

it('convierte a entero igual que el cast de PHP para escalares y null', function (): void {
    expect(Entrada::entero(7))->toBe(7)
        ->and(Entrada::entero('42'))->toBe(42)
        ->and(Entrada::entero('12abc'))->toBe(12)
        ->and(Entrada::entero(3.9))->toBe(3)
        ->and(Entrada::entero(true))->toBe(1)
        ->and(Entrada::entero(null))->toBe(0);
});

it('usa el valor por defecto con arreglos y objetos en lugar de un resultado engañoso', function (): void {
    expect(Entrada::entero([1, 2]))->toBe(0)
        ->and(Entrada::entero([1], 5))->toBe(5)
        ->and(Entrada::texto(['a']))->toBe('')
        ->and(Entrada::texto(new stdClass, 'x'))->toBe('x')
        ->and(Entrada::decimal([1], 2.5))->toBe(2.5)
        ->and(Entrada::booleano([1]))->toBeFalse();
});

it('convierte a texto, decimal y booleano', function (): void {
    $objeto = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'hola';
        }
    };

    expect(Entrada::texto('a'))->toBe('a')
        ->and(Entrada::texto(12))->toBe('12')
        ->and(Entrada::texto(null))->toBe('')
        ->and(Entrada::texto($objeto))->toBe('hola')
        ->and(Entrada::decimal('1.5'))->toBe(1.5)
        ->and(Entrada::decimal(2))->toBe(2.0)
        ->and(Entrada::booleano('1'))->toBeTrue()
        ->and(Entrada::booleano(0))->toBeFalse()
        ->and(Entrada::lista([1]))->toBe([1])
        ->and(Entrada::lista('x'))->toBe([]);
});

it('deja pasar celdas escalares y normaliza el resto', function (): void {
    expect(Entrada::celda('a'))->toBe('a')
        ->and(Entrada::celda(3))->toBe(3)
        ->and(Entrada::celda(1.5))->toBe(1.5)
        ->and(Entrada::celda(null))->toBeNull()
        ->and(Entrada::celda(true))->toBe(1)
        ->and(Entrada::celda(false))->toBe(0)
        ->and(Entrada::celda(['x']))->toBe('');
});

it('convierte filas y objetos en mapas con claves de texto', function (): void {
    $objeto = new stdClass;
    $objeto->a = 1;

    expect(Entrada::mapa(['x' => 1, 2 => 'b']))->toBe(['x' => 1, '2' => 'b'])
        ->and(Entrada::mapa($objeto))->toBe(['a' => 1])
        ->and(Entrada::mapa('texto'))->toBe([])
        ->and(Entrada::mapa(null))->toBe([]);
});
