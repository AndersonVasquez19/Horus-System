import { expect, test, type Page } from '@playwright/test';

/** Chat público: lo que el visitante encuentra al pulsar WhatsApp. La respuesta del asistente se simula en el navegador. */
const PASOS = [
  'Para matricularte sigue estos pasos:',
  '1. Elige el curso vigente de tu interés en nuestra web.',
  '2. Escríbenos por WhatsApp al +51 972 021 374.',
].join('\n');
const CURSOS = 'Cursos y capacitaciones vigentes: Matemática. Escríbenos al +51 972 021 374.';

async function preguntar(page: Page, texto: string) {
  await page.getByLabel('Escribe tu consulta').fill(texto);
  await page.getByRole('button', { name: 'Enviar consulta' }).click();
}

const mensajeDe = async (enlace: ReturnType<Page['getByRole']>) =>
  new URL((await enlace.getAttribute('href')) ?? '').searchParams.get('text');

test.beforeEach(async ({ page }) => {
  await page.route('**/api/chat', async (ruta) => {
    const { mensaje } = ruta.request().postDataJSON() as { mensaje: string };
    await ruta.fulfill({ json: { respuesta: mensaje.includes('matricul') ? PASOS : CURSOS } });
  });
  await page.goto('/');
  const abrirChat = page.getByRole('button', { name: 'Abrir chat con el asistente Horus' });
  // El botón responde cuando React ya hidrató la página: se reintenta hasta que el panel abra.
  await expect(async () => {
    await abrirChat.click({ timeout: 2000 });
    await expect(page.getByLabel('Escribe tu consulta')).toBeVisible({ timeout: 2000 });
  }).toPass();
});

test('cada botón de WhatsApp lleva un mensaje escrito según la respuesta, también al recargar', async ({ page }) => {
  const botones = page.getByRole('link', { name: 'Contactar por WhatsApp' });

  await preguntar(page, '¿Qué cursos tienen?');
  await expect(botones).toHaveCount(1);
  expect(await mensajeDe(botones.last())).toBe('Hola Horus Group, consulté el asistente web y deseo más información.');

  await page.waitForTimeout(500); // el chat ignora dos envíos casi simultáneos
  await preguntar(page, '¿Cómo puedo matricularme?');
  await expect(botones).toHaveCount(2);
  const matricula = 'Hola Horus Group, consulté el asistente web y deseo matricularme. ¿Me confirman vacante, tarifa e inicio?';
  expect(await mensajeDe(botones.last())).toBe(matricula);

  await page.reload();
  await expect(async () => {
    await page.getByRole('button', { name: 'Abrir chat con el asistente Horus' }).click({ timeout: 2000 });
    await expect(botones).toHaveCount(2, { timeout: 2000 });
  }).toPass();
  expect(await mensajeDe(botones.last())).toBe(matricula);
});

test('los pasos de matrícula se muestran numerados', async ({ page }, info) => {
  await preguntar(page, '¿Cómo puedo matricularme?');
  const pasos = page.locator('.hc-rich-item');
  await expect(pasos).toHaveCount(2);
  await expect(pasos.nth(0).locator('.hc-rich-number')).toHaveText('1.');
  await expect(pasos.nth(1).locator('.hc-rich-number')).toHaveText('2.');
  await expect(page.locator('.hc-rich-bullet')).toHaveCount(0);
  // El número y su punto van en una sola línea.
  for (const numero of await page.locator('.hc-rich-number').all()) {
    expect((await numero.boundingBox())?.height ?? 0).toBeLessThan(30);
  }
  await page.screenshot({ path: `e2e/capturas/chat-pasos-${info.project.name}.png` });
});
