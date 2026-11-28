import { expect, test } from '@playwright/test';
import { abrir, capturar, esMovil, limpiarPeticiones, peticiones } from './ayudas';

test.describe('Filtros del listado de estudiantes', () => {
  test.beforeEach(async ({ page }) => {
    await limpiarPeticiones(page);
    await abrir(page, '/admin/academico/estudiantes');
  });

  test('en móvil están plegados detrás de «Filtros» y siguen funcionando', async ({ page }, info) => {
    test.skip(!esMovil(info), 'El plegado solo existe en pantallas pequeñas.');
    const buscar = page.getByLabel('Buscar', { exact: true });
    await expect(buscar).toBeHidden();

    const alternar = page.getByRole('button', { name: /^Filtros/ });
    await expect(alternar).toHaveAttribute('aria-expanded', 'false');
    await alternar.click();
    await expect(alternar).toHaveAttribute('aria-expanded', 'true');
    await expect(buscar).toBeVisible();
    await capturar(page, info, 'filtros-abiertos');

    await buscar.fill('Torres');
    await page.getByRole('button', { name: 'Aplicar' }).click();
    await expect(page).toHaveURL(/busqueda=Torres/);
    // El valor viajó realmente hasta el servidor.
    await expect.poll(async () => (await peticiones(page)).some((p) => p.consulta.busqueda === 'Torres')).toBe(true);
  });

  test('en escritorio los filtros se ven siempre y no hay botón de plegado', async ({ page }, info) => {
    test.skip(esMovil(info), 'Comprobación propia del escritorio.');
    await expect(page.getByLabel('Buscar', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Filtros/ })).toBeHidden();
  });

  test('el selector «Por página» cambia la cantidad de filas', async ({ page }, info) => {
    if (esMovil(info)) await page.getByRole('button', { name: /^Filtros/ }).click();
    await expect(page.getByText('1–20 de 25')).toBeVisible();

    await page.getByLabel('Por página').selectOption('50');
    await page.getByRole('button', { name: 'Aplicar' }).click();
    await expect(page).toHaveURL(/per_page=50/);
    await expect(page.getByText('1–25 de 25')).toBeVisible();
  });
});

test.describe('Tabla de estudiantes en móvil', () => {
  test('la columna de acciones sigue a la vista sin desplazar la tabla', async ({ page }, info) => {
    test.skip(!esMovil(info), 'Comprobación propia de la vista móvil.');
    await abrir(page, '/admin/academico/estudiantes');
    const primeraFila = page.getByRole('row').nth(1);
    await expect(primeraFila.getByRole('link', { name: 'Ver', exact: true })).toBeInViewport({ ratio: 1 });
    await capturar(page, info, 'tabla-movil');
  });
});

test.describe('Menú lateral en móvil', () => {
  test.beforeEach(async ({ page }, info) => {
    test.skip(!esMovil(info), 'El menú superpuesto solo existe en pantallas pequeñas.');
    await abrir(page, '/admin/academico/estudiantes');
  });

  test('se superpone al contenido en vez de empujarlo, y se cierra con Escape', async ({ page }, info) => {
    const contenido = page.locator('#contenido-panel');
    const antes = await contenido.boundingBox();

    const boton = page.getByRole('button', { name: /^Menú de/ });
    await boton.click();
    await expect(page.getByRole('link', { name: 'Cursos' })).toBeVisible();

    const despues = await contenido.boundingBox();
    expect(Math.round(despues!.y)).toBe(Math.round(antes!.y));
    await capturar(page, info, 'menu-lateral-abierto');

    await page.keyboard.press('Escape');
    await expect(page.getByRole('link', { name: 'Cursos' })).toBeHidden();
    await expect(boton).toBeFocused();
  });

  test('se cierra al pulsar fuera, en el fondo atenuado', async ({ page }) => {
    await page.getByRole('button', { name: /^Menú de/ }).click();
    await expect(page.getByRole('link', { name: 'Cursos' })).toBeVisible();
    await page.mouse.click(180, 725);
    await expect(page.getByRole('link', { name: 'Cursos' })).toBeHidden();
  });

  test('un enlace del menú navega y lo cierra', async ({ page }) => {
    await page.getByRole('button', { name: /^Menú de/ }).click();
    await page.getByRole('link', { name: 'Cursos' }).click();
    await expect(page).toHaveURL(/\/admin\/academico\/cursos/);
    await expect(page.getByRole('link', { name: 'Estudiantes' })).toBeHidden();
  });
});

test.describe('Barra superior en móvil', () => {
  test('el buscador rápido y el menú de usuario son alcanzables y tienen nombre', async ({ page }, info) => {
    test.skip(!esMovil(info), 'Comprobación propia de la vista móvil.');
    await abrir(page, '/admin/academico/estudiantes');
    await expect(page.getByRole('button', { name: /^Buscar…/ })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Administrador de prueba' })).toBeVisible();
  });
});
