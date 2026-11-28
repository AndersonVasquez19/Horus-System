import { expect, test } from '@playwright/test';
import { abrir, capturar } from './ayudas';

/**
 * Reportes: gráficos calculados con el `resumen` que entrega Laravel, paginación por lotes y menú «Exportar».
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

test.describe('Menú «Exportar»', () => {
  test('agrupa las exportaciones en un solo botón con nombre visible', async ({ page }, info) => {
    await abrir(page, '/admin/academico/reportes');
    const boton = page.getByRole('button', { name: 'Exportar', exact: true });
    await expect(boton).toBeVisible();
    // Sin `aria-label` propio: el nombre accesible es el texto visible (WCAG 2.5.3).
    await expect(boton).not.toHaveAttribute('aria-label', /.+/);

    // Ya no hay tres botones sueltos de exportación.
    await expect(page.getByRole('button', { name: /^Exportar (Excel|PDF)/ })).toHaveCount(0);

    await boton.click();
    const menu = page.getByRole('menu', { name: 'Exportar reporte' });
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Excel' })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'PDF completo' })).toBeVisible();
    await capturar(page, info, 'menu-exportar');

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(boton).toBeFocused();
  });

  test('«Vista previa» sigue visible junto al menú', async ({ page }) => {
    await abrir(page, '/admin/academico/reportes');
    await expect(page.getByRole('button', { name: 'Vista previa' })).toBeVisible();
  });
});
