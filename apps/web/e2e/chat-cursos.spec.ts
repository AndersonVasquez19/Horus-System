import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/** Chat público: comparación de cursos con sus portadas y curso en foco. El servidor se simula en el navegador. */
const PORTADA = 'https://api.prueba.test/storage/cursos/037d4074-1111-2222-3333-444455556666.jpg';
const CURSOS = ['Comunicación', 'Matemática', 'Ciencias Sociales'];

const ficha = (titulo: string, duracion: string, horario: string, certificacion: string) =>
  `${titulo}: Resumen largo de ${titulo} que no debe repetirse. Modalidad: presencial. Duración: ${duracion}. Inicio: 30 de noviembre de 2026. Horario: ${horario}. Certificación: ${certificacion}.`;
const COMPARACION = [
  ficha('Comunicación', '2 semanas', '2:00 p. m. a 5:00 p. m.', 'Certificado físico'),
  ficha('Matemática', '8 semanas', '2:00 p. m. a 6:00 p. m.', 'Certificado físico'),
  ficha('Ciencias Sociales', '8 semanas', '3:00 p. m. a 5:00 p. m.', 'Certificado físico'),
].join('\n');
const tarjetas = CURSOS.map((titulo, i) => ({
  id: `cat_curso_publicacion_${i + 1}`, tipo: 'curso', titulo, descripcion: `Resumen de ${titulo}.`,
  url: '/educacion/cursos', imagen: i === 2 ? null : PORTADA, accion: 'Ver información',
}));

async function abrirChat(page: Page) {
  const boton = page.getByRole('button', { name: 'Abrir chat con el asistente Horus' });
  // El botón responde cuando React ya hidrató la página: se reintenta hasta que el panel abra.
  await expect(async () => {
    await boton.click({ timeout: 2000 });
    await expect(page.getByLabel('Escribe tu consulta')).toBeVisible({ timeout: 2000 });
  }).toPass();
}

async function preguntar(page: Page, texto: string) {
  await page.waitForTimeout(500); // el chat ignora dos envíos casi simultáneos
  await page.getByLabel('Escribe tu consulta').fill(texto);
  await page.getByRole('button', { name: 'Enviar consulta' }).click();
}

test.beforeEach(async ({ page }) => {
  await page.route(PORTADA, (ruta) => ruta.fulfill({ path: 'public/images/capacitaciones/convenios-institucionales.jpg' }));
  await page.route('**/api/chat/orientador', (ruta) =>
    ruta.fulfill({ json: { cursos: CURSOS.map((titulo) => ({ titulo, modalidad: 'presencial', vigente_hasta: null })) } }),
  );
  await page.route('**/api/chat', async (ruta) => {
    const { mensaje } = ruta.request().postDataJSON() as { mensaje: string };
    if (/comp[aá]rame/i.test(mensaje)) return ruta.fulfill({ json: { respuesta: COMPARACION, tarjetas } });
    if (/inscribirme/i.test(mensaje)) {
      return ruta.fulfill({ json: { respuesta: 'Para consultar la matrícula, continúa por el WhatsApp oficial: +51 972 021 374.' } });
    }
    if (/horario/i.test(mensaje)) {
      return ruta.fulfill({ json: { respuesta: '«Comunicación»: el horario es 2:00 p. m. a 5:00 p. m.. Escríbenos al +51 972 021 374.' } });
    }
    return ruta.fulfill({ json: { respuesta: 'Cursos y capacitaciones vigentes: Comunicación; Matemática; Ciencias Sociales.' } });
  });
  await page.goto('/');
  await abrirChat(page);
});

test('la comparación dice lo común una vez y lo distinto en la tarjeta de cada curso, con su imagen', async ({ page }, info) => {
  await preguntar(page, 'Compárame los cursos disponibles');
  const comun = page.locator('.hc-compare-common');
  await expect(comun).toContainText('Modalidad');
  await expect(comun).toContainText('Certificado físico');
  await expect(comun).not.toContainText('Duración');
  await expect(page.getByText('En qué se diferencian')).toBeVisible();
  await expect(page.getByText('que no debe repetirse')).toHaveCount(0);

  const matematica = page.getByRole('region', { name: 'Matemática', exact: true });
  await expect(matematica).toContainText('8 semanas');
  await expect(matematica).not.toContainText('Certificado físico');
  const portada = matematica.getByRole('img', { name: 'Matemática' });
  await portada.scrollIntoViewIfNeeded();
  await expect.poll(() => portada.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  await expect(page.getByRole('region', { name: 'Ciencias Sociales', exact: true }).getByRole('img')).toHaveCount(0);

  const sobra = await page.locator('.hc-log').evaluate((el) => el.scrollWidth - el.clientWidth);
  expect(sobra).toBeLessThanOrEqual(0);
  await comun.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `e2e/capturas/chat-comparacion-${info.project.name}.png` });
  await matematica.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `e2e/capturas/chat-comparacion-tarjeta-${info.project.name}.png` });

  const graves = (await new AxeBuilder({ page }).include('.hc-dialog').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze())
    .violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  expect(graves.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);

  await page.reload();
  await abrirChat(page);
  await expect(page.locator('.hc-compare-common')).toBeVisible();
  await expect(page.getByRole('region', { name: 'Matemática', exact: true }).getByRole('img', { name: 'Matemática' })).toHaveCount(1);
});

test('el curso en foco sigue a la respuesta, también en WhatsApp', async ({ page }) => {
  await preguntar(page, 'Compárame los cursos disponibles');
  await page.getByRole('region', { name: 'Matemática', exact: true }).getByRole('button', { name: 'Consultar aquí' }).click();
  const barra = page.locator('.hc-focus-bar');
  await expect(barra).toContainText('Consultando: «Matemática»');

  await preguntar(page, '¿Cuál es el horario?');
  await expect(barra).toContainText('Consultando: «Comunicación»');
  const whatsapp = page.getByRole('link', { name: 'Consultar «Comunicación» por WhatsApp' });
  const mensaje = new URL((await whatsapp.getAttribute('href')) ?? '').searchParams.get('text') ?? '';
  expect(mensaje).toContain('«Comunicación»');
  expect(mensaje).not.toContain('Matemática');
  // El dato ya termina en punto: no se ve «p. m..».
  await expect(page.locator('.hc-log')).not.toContainText('p. m..');

  await preguntar(page, '¿Qué cursos tienen?');
  await expect(barra).toHaveCount(0);
});

test('pedir un curso que no está publicado no arrastra el curso anterior', async ({ page }) => {
  await preguntar(page, 'Compárame los cursos disponibles');
  await page.getByRole('region', { name: 'Matemática', exact: true }).getByRole('button', { name: 'Consultar aquí' }).click();
  await expect(page.locator('.hc-focus-bar')).toContainText('Matemática');

  await preguntar(page, 'quiero inscribirme a robótica');
  await expect(page.locator('.hc-focus-bar')).toHaveCount(0);
  const boton = page.getByRole('link', { name: 'Contactar por WhatsApp' }).last();
  const mensaje = new URL((await boton.getAttribute('href')) ?? '').searchParams.get('text');
  expect(mensaje).toBe('Hola Horus Group, consulté el asistente web y deseo matricularme. ¿Me confirman vacante, tarifa e inicio?');
});
