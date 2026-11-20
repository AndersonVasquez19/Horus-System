import { expect, test } from '@playwright/test';
import { abrir, capturar, esMovil } from './ayudas';

/**
 * Diálogos y paneles laterales (`Dialogo`): foco inicial, cierre, aviso de cambios sin guardar, pantalla completa en
 * móvil, pie de acciones fijo y avisos por encima. Se prueban con el panel de «Registrar estudiante».
 */
test.describe('Panel lateral «Registrar estudiante»', () => {
  test.beforeEach(async ({ page }) => {
    await abrir(page, '/admin/academico/estudiantes');
    await page.getByRole('button', { name: 'Nuevo estudiante' }).click();
    await expect(page.getByRole('dialog', { name: 'Registrar estudiante' })).toBeVisible();
  });

  test('el foco empieza en el primer campo, no en el botón de cerrar', async ({ page }) => {
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    await expect(panel.getByRole('textbox', { name: 'DNI', exact: true })).toBeFocused();
  });

  test('Escape sin cambios cierra el panel', async ({ page }) => {
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Registrar estudiante' })).toBeHidden();
  });

  test('con cambios, Escape pide confirmar y conserva lo escrito si se sigue editando', async ({ page }) => {
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    await panel.getByRole('textbox', { name: 'DNI', exact: true }).fill('123');

    await page.keyboard.press('Escape');
    await expect(panel.getByText('Tienes cambios sin guardar')).toBeVisible();
    await expect(panel).toBeVisible();

    await panel.getByRole('button', { name: 'Seguir editando' }).click();
    await expect(panel.getByText('Tienes cambios sin guardar')).toBeHidden();
    await expect(panel.getByRole('textbox', { name: 'DNI', exact: true })).toHaveValue('123');

    await page.keyboard.press('Escape');
    await panel.getByRole('button', { name: 'Descartar' }).click();
    await expect(panel).toBeHidden();
  });

  test('un clic en el fondo cierra el panel limpio (solo escritorio)', async ({ page }, info) => {
    test.skip(esMovil(info), 'En móvil el panel ocupa toda la pantalla: no hay fondo visible.');
    await page.mouse.click(40, 400);
    await expect(page.getByRole('dialog', { name: 'Registrar estudiante' })).toBeHidden();
  });

  test('un clic en el fondo con cambios pide confirmar en lugar de perder lo escrito (solo escritorio)', async ({ page }, info) => {
    test.skip(esMovil(info), 'En móvil el panel ocupa toda la pantalla: no hay fondo visible.');
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    await panel.getByLabel('Nombres').fill('Ana');
    await page.mouse.click(40, 400);
    await expect(panel.getByText('Tienes cambios sin guardar')).toBeVisible();
  });

  test('en móvil el panel ocupa toda la pantalla', async ({ page }, info) => {
    test.skip(!esMovil(info), 'Comprobación propia de la vista móvil.');
    const caja = await page.getByRole('dialog', { name: 'Registrar estudiante' }).boundingBox();
    const vista = page.viewportSize();
    expect(caja).not.toBeNull();
    expect(vista).not.toBeNull();
    expect(Math.round(caja!.width)).toBe(vista!.width);
    expect(Math.round(caja!.height)).toBe(vista!.height);
    await capturar(page, info, 'panel-lateral');
  });

  test('el botón de guardar queda visible sin desplazarse en un formulario largo (móvil)', async ({ page }, info) => {
    test.skip(!esMovil(info), 'El formulario solo es largo en la vista móvil (una columna).');
    const guardar = page.getByRole('dialog', { name: 'Registrar estudiante' }).getByRole('button', { name: 'Registrar estudiante' });
    await expect(guardar).toBeInViewport();

    // La barra fija llega hasta el borde inferior: no deja una franja por la que se vea pasar el contenido.
    const pie = await page.getByRole('dialog', { name: 'Registrar estudiante' }).locator('.pie-acciones').boundingBox();
    const vista = page.viewportSize();
    expect(pie).not.toBeNull();
    expect(Math.abs(pie!.y + pie!.height - vista!.height)).toBeLessThanOrEqual(1);
  });

  test('un aviso aparece por encima del panel abierto', async ({ page }, info) => {
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    await panel.getByRole('textbox', { name: 'DNI', exact: true }).fill('70000001');
    await panel.getByRole('button', { name: 'Consultar DNI' }).click();

    const aviso = page.getByText('Datos del DNI cargados correctamente.');
    await expect(aviso).toBeVisible();
    await capturar(page, info, 'aviso-sobre-panel');

    // El aviso se pinta en un `popover` abierto, que vive en la capa superior igual que el diálogo y se reabre con cada
    // aviso para quedar encima. No se puede comprobar con `elementFromPoint`: un diálogo modal vuelve inerte todo lo de
    // fuera y esa API no distingue «tapado» de «inerte». La posición por encima se revisa en la captura guardada.
    const capa = page.locator('[popover]:has([data-sonner-toaster])');
    await expect(capa).toHaveCount(1);
    expect(await capa.evaluate((el) => el.matches(':popover-open'))).toBe(true);

    // El aviso se solapa con el panel (caso que antes quedaba oculto detrás del diálogo).
    const caja = await aviso.boundingBox();
    const cajaPanel = await panel.boundingBox();
    expect(caja).not.toBeNull();
    expect(cajaPanel).not.toBeNull();
    expect(caja!.x + caja!.width).toBeGreaterThan(cajaPanel!.x);
    expect(caja!.y).toBeGreaterThan(cajaPanel!.y);
  });
});
