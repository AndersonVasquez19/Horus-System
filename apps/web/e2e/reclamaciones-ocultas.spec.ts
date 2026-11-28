import { expect, test } from '@playwright/test';
import { API, abrir, type Peticion } from './ayudas';

/**
 * La sección de reclamaciones está oculta en el panel (`RECLAMACIONES_EN_PANEL`). Los mensajes de «Contáctanos», que
 * compartían la sección, siguen accesibles en la misma dirección.
 */
const UUID = '00000000-0000-4000-8000-000000000001';

test('el menú ofrece los mensajes de contacto y no las reclamaciones', async ({ page }, info) => {
  test.skip(info.project.name === 'movil', 'En móvil el menú va en un cajón; sus entradas son las mismas.');
  await abrir(page, '/admin/academico');
  await expect(page.getByRole('link', { name: 'Mensajes de contacto' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Reclamaciones/ })).toHaveCount(0);
});

test('el inicio no muestra los plazos de reclamaciones', async ({ page }) => {
  await abrir(page, '/admin/academico');
  await expect(page.getByText('Pagos por confirmar').first()).toBeVisible();
  await expect(page.getByText('Plazos de reclamaciones')).toHaveCount(0);
});

for (const ruta of ['/admin/academico/reclamaciones', '/admin/academico/reclamaciones?vista=mensajes', '/admin/academico/reclamaciones?estado=abierto']) {
  test(`${ruta} muestra solo los mensajes de contacto`, async ({ page, request }) => {
    await request.delete(`${API}/__peticiones`);
    await abrir(page, ruta);
    await expect(page.getByRole('heading', { level: 1, name: 'Mensajes de contacto' })).toBeVisible();
    await expect(page.getByText('Consulta sintética sobre un curso.')).toBeVisible();
    await expect(page.locator('#contenido-panel')).not.toContainText(/reclamaci/i);
    const peticiones: Peticion[] = await (await request.get(`${API}/__peticiones`)).json();
    expect(peticiones.filter((p) => p.ruta === 'compartido/reclamaciones')).toEqual([]);
  });
}

for (const ruta of [`/admin/academico/reclamaciones/${UUID}`, `/admin/academico/reclamaciones/${UUID}/hoja`]) {
  test(`${ruta} lleva a los mensajes de contacto`, async ({ page }) => {
    await page.goto(ruta);
    await expect(page).toHaveURL(/\/admin\/academico\/reclamaciones$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Mensajes de contacto' })).toBeVisible();
  });
}

test('«Exportar datos» no ofrece las reclamaciones ni las incluye en la descarga', async ({ page, request }) => {
  await abrir(page, '/admin/academico/respaldo');
  await expect(page.getByText('Mensajes de contacto', { exact: true }).last()).toBeVisible();
  await expect(page.locator('#contenido-panel')).not.toContainText(/reclamaci/i);
  await request.delete(`${API}/__peticiones`);
  await page.getByRole('button', { name: /Descargar/ }).first().click();
  await expect
    .poll(async () => ((await (await request.get(`${API}/__peticiones`)).json()) as Peticion[]).find((p) => p.ruta === 'compartido/respaldo/exportar')?.consulta.tablas)
    .toBe('estudiantes,mensajes_contacto');
});
