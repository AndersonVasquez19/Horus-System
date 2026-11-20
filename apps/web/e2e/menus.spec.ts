import { expect, test } from '@playwright/test';
import { abrir, capturar } from './ayudas';

test.describe('Menú ⋯ de una fila', () => {
  test.beforeEach(async ({ page }) => {
    await abrir(page, '/admin/academico/estudiantes');
  });

  test('agrupa en bloques con encabezados y se recorre con el teclado', async ({ page }, info) => {
    // Luis Torres Díaz tiene correo y teléfono: 7 ítems, suficientes para que aparezcan los encabezados.
    const boton = page.getByRole('button', { name: 'Acciones para Luis Torres Díaz' });
    await boton.click();

    const menu = page.getByRole('menu', { name: 'Acciones para Luis Torres Díaz' });
    await expect(menu).toBeVisible();
    await expect(menu.getByText('Contactar')).toBeVisible();
    await expect(menu.getByText('Copiar', { exact: true })).toBeVisible();
    // Los encabezados no heredan la alineación a la derecha de la celda de la tabla donde vive el menú.
    await expect(menu.getByText('Contactar')).toHaveCSS('text-align', 'left');
    await expect(menu.getByText('Copiar', { exact: true })).toHaveCSS('text-align', 'left');
    await capturar(page, info, 'menu-fila');

    // El foco empieza en el primer ítem; las flechas lo recorren.
    await expect(menu.getByRole('menuitem', { name: 'Ver ficha' })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('menuitem', { name: 'Matricular' })).toBeFocused();

    // Búsqueda por letra: «c» salta al primer ítem que empieza por c.
    await page.keyboard.press('c');
    await expect(menu.getByRole('menuitem', { name: 'Copiar teléfono' })).toBeFocused();

    // Escape cierra y devuelve el foco al botón.
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(boton).toBeFocused();
  });

  test('ningún ítem queda tapado por las celdas fijas de la tabla', async ({ page }) => {
    await page.getByRole('button', { name: 'Acciones para Luis Torres Díaz' }).click();
    const menu = page.getByRole('menu', { name: 'Acciones para Luis Torres Díaz' });
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
    const boton = page.getByRole('button', { name: 'Acciones para Luis Torres Díaz' });
    await boton.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menu', { name: 'Acciones para Luis Torres Díaz' })).toBeVisible();
  });

  test('un menú corto no lleva encabezados', async ({ page }) => {
    // Ana Quispe Rojas no tiene correo ni teléfono: solo 3 ítems.
    await page.getByRole('button', { name: 'Acciones para Ana Quispe Rojas' }).click();
    const menu = page.getByRole('menu', { name: 'Acciones para Ana Quispe Rojas' });
    await expect(menu.getByRole('menuitem')).toHaveCount(3);
    await expect(menu.getByText('Contactar')).toBeHidden();
  });

  test('tiene un objetivo táctil de al menos 40 px en móvil', async ({ page }, info) => {
    test.skip(info.project.name !== 'movil', 'Comprobación propia de la vista móvil.');
    const caja = await page.getByRole('button', { name: 'Acciones para Luis Torres Díaz' }).boundingBox();
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
