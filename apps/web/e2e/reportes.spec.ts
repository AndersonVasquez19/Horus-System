import { expect, test } from '@playwright/test';
import { abrir, capturar } from './ayudas';

/**
 * Reportes: gráficos calculados con el `resumen` que entrega Laravel, paginación por lotes y botones de exportación.
 * El servidor falso devuelve cifras fijas (ver `reporte()` en `mock-laravel.mjs`).
 */
test.describe('Gráficos por tipo de reporte', () => {
  test('cobranza: avance de cobro con el porcentaje y las cifras como texto', async ({ page }, info) => {
    await abrir(page, '/admin/academico/reportes?tipo=cobranza');
    const grafico = page.getByRole('figure').filter({ hasText: 'Avance de cobro' });

    // 350 cobrados de 500 facturados.
    await expect(grafico.getByText('70 % cobrado')).toBeVisible();
    await expect(grafico.getByText(/Cobrado:/)).toContainText('350');
    await expect(grafico.getByText(/Saldo pendiente:/)).toContainText('150');
    // La barra se describe con texto: no hay información que dependa solo del color.
    await expect(grafico.getByRole('img')).toHaveAttribute('aria-label', /Cobrado 70 %.*Saldo pendiente 30 %/);
    await capturar(page, info, 'reporte-cobranza');
  });

  test('matrículas no tiene gráfico: el backend no entrega datos para uno útil', async ({ page }) => {
    await abrir(page, '/admin/academico/reportes');
    await expect(page.getByRole('figure')).toHaveCount(0);
  });
});

test.describe('Paginación por lotes', () => {
  test('el reporte dice «Lote» y no «Página»', async ({ page }) => {
    await abrir(page, '/admin/academico/reportes?tipo=cobranza');
    const paginacion = page.getByRole('navigation', { name: 'Paginación' });
    await expect(paginacion.getByText('Lote 1 de 2')).toBeVisible();
    await expect(paginacion.getByText(/^Página/)).toHaveCount(0);
  });

  test('los listados normales siguen diciendo «Página»', async ({ page }) => {
    await abrir(page, '/admin/academico/estudiantes');
    await expect(page.getByRole('navigation', { name: 'Paginación' }).getByText('Página 1 de 2')).toBeVisible();
  });
});

test.describe('Botones de exportación', () => {
  test('cada formato tiene su botón, con nombre visible', async ({ page }, info) => {
    await abrir(page, '/admin/academico/reportes');
    for (const nombre of ['Excel', 'PDF completo']) {
      const boton = page.getByRole('button', { name: nombre, exact: true });
      await expect(boton).toBeVisible();
      // Sin `aria-label` propio: el nombre accesible es el texto visible (WCAG 2.5.3).
      await expect(boton).not.toHaveAttribute('aria-label', /.+/);
    }
    // Con un solo lote no se ofrece «PDF lote».
    await expect(page.getByRole('button', { name: 'PDF lote' })).toHaveCount(0);
    await capturar(page, info, 'botones-exportar');
  });

  test('el reporte de pagos ofrece Excel y un único PDF', async ({ page }) => {
    await abrir(page, '/admin/academico/reportes?tipo=cobranza');
    await expect(page.getByRole('button', { name: 'Excel', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'PDF', exact: true })).toBeVisible();
  });

  test('«Vista previa» sigue visible junto a las exportaciones', async ({ page }) => {
    await abrir(page, '/admin/academico/reportes');
    await expect(page.getByRole('button', { name: 'Vista previa' })).toBeVisible();
  });
});
