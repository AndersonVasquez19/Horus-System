import { expect, test } from '@playwright/test';
import { abrir, capturar, pulsarBotonDeMenu } from './ayudas';

test.describe('Menú ⋯ de una fila', () => {
  // Hoy solo el listado de matrículas usa el menú ⋯. Ningún menú real llega a los 6 ítems que activan los encabezados
  // «Contactar» y «Copiar», así que esos encabezados no se comprueban aquí.
  const MENU = 'Más acciones para matrícula #1';

  test.beforeEach(async ({ page }) => {
    await abrir(page, '/admin/academico/matriculas');
  });

  test('agrupa en bloques y se recorre con el teclado', async ({ page }, info) => {
    const boton = page.getByRole('button', { name: MENU });
    await pulsarBotonDeMenu(boton);

    const menu = page.getByRole('menu', { name: MENU });
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem')).toHaveCount(4);
    // Lo destructivo va al final, separado del resto.
    await expect(menu.getByRole('separator')).toHaveCount(1);
    // El panel no hereda la alineación a la derecha de la celda de la tabla.
    await expect(menu.getByRole('menuitem').first()).toHaveCSS('text-align', 'left');
    await capturar(page, info, 'menu-fila');

    // El foco empieza en el primer ítem; las flechas lo recorren.
    await expect(menu.getByRole('menuitem', { name: 'Editar matrícula' })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('menuitem', { name: 'Descargar constancia PDF' })).toBeFocused();

    // Búsqueda por letra: «a» salta al ítem que empieza por a.
    await page.keyboard.press('a');
    await expect(menu.getByRole('menuitem', { name: 'Anular matrícula' })).toBeFocused();

    // Escape cierra y devuelve el foco al botón.
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(boton).toBeFocused();
  });

  test('ningún ítem queda tapado por las celdas fijas de la tabla', async ({ page }) => {
    await pulsarBotonDeMenu(page.getByRole('button', { name: MENU }));
    const menu = page.getByRole('menu', { name: MENU });
    await expect(menu).toBeVisible();

    // El panel va en un portal fuera de la tabla: la última columna es `sticky` y cada celda crea su propio contexto de
    // apilamiento, así que un menú que viviera dentro de una celda quedaría tapado por las celdas siguientes.
    expect(await menu.evaluate((el) => el.parentElement === document.body)).toBe(true);
    const tapados = await menu.getByRole('menuitem').evaluateAll((items) =>
      items
        .filter((item) => {
          const caja = item.getBoundingClientRect();
          const encima = document.elementFromPoint(caja.right - 6, caja.top + caja.height / 2);
          return encima === null || encima.closest('[role="menu"]') === null;
        })
        .map((item) => item.textContent),
    );
    expect(tapados).toEqual([]);
  });

  test('se abre con la flecha abajo desde el botón', async ({ page }) => {
    const boton = page.getByRole('button', { name: MENU });
    await boton.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menu', { name: MENU })).toBeVisible();
  });

  test('las acciones dependen de la fila y un menú corto no lleva encabezados', async ({ page }) => {
    // Una matrícula ya anulada no ofrece «Anular».
    await pulsarBotonDeMenu(page.getByRole('button', { name: 'Más acciones para matrícula #2' }));
    const menu = page.getByRole('menu', { name: 'Más acciones para matrícula #2' });
    await expect(menu.getByRole('menuitem')).toHaveCount(3);
    await expect(menu.getByRole('menuitem', { name: 'Anular matrícula' })).toHaveCount(0);
    await expect(menu.getByText('Contactar')).toHaveCount(0);
  });

  test('tiene un objetivo táctil de al menos 40 px en móvil', async ({ page }, info) => {
    test.skip(info.project.name !== 'movil', 'Comprobación propia de la vista móvil.');
    const caja = await page.getByRole('button', { name: MENU }).boundingBox();
    expect(caja!.width).toBeGreaterThanOrEqual(40);
    expect(caja!.height).toBeGreaterThanOrEqual(40);
  });
});

test.describe('Menú de usuario', () => {
  test('se abre con la flecha abajo, enfoca el primer ítem y Escape devuelve el foco', async ({ page }) => {
    await abrir(page, '/admin/academico/estudiantes');
    const boton = page.getByRole('button', { name: 'Administrador de prueba' });
    await boton.focus();
    await page.keyboard.press('ArrowDown');

    const menu = page.getByRole('menu', { name: 'Menú de cuenta' });
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Mi perfil' })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('menuitem', { name: 'Notificaciones' })).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(boton).toBeFocused();
  });
});
