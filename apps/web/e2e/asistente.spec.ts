import { expect, test } from '@playwright/test';
import { abrir, capturar, limpiarPeticiones } from './ayudas';

/** Probador del asistente virtual y su historial. El servidor falso responde «Respuesta de prueba a: <mensaje>». */
test.describe('Probador del asistente', () => {
  test.beforeEach(async ({ page }) => {
    await limpiarPeticiones(page);
    await abrir(page, '/admin/academico/asistente?vista=probar');
  });

  test('una pregunta de ejemplo se envía con un clic', async ({ page }) => {
    await page.getByRole('button', { name: '¿Qué cursos tienen?' }).click();
    const registro = page.getByRole('log', { name: 'Conversación de prueba' });
    await expect(registro.getByText('Respuesta de prueba a: ¿Qué cursos tienen?')).toBeVisible();
  });

  test('Enter envía, Shift+Enter salta de línea y el campo se vacía', async ({ page }) => {
    const campo = page.getByLabel('Mensaje de prueba');
    await campo.fill('primera línea');
    await campo.press('Shift+Enter');
    await campo.pressSequentially('segunda línea');
    await expect(campo).toHaveValue('primera línea\nsegunda línea');

    await campo.press('Enter');
    const registro = page.getByRole('log', { name: 'Conversación de prueba' });
    await expect(registro.getByText('Respuesta de prueba a: primera línea\nsegunda línea')).toBeVisible();
    await expect(campo).toHaveValue('');
  });

  test('si el envío falla, el mensaje no se pierde y se puede reintentar', async ({ page }) => {
    const campo = page.getByLabel('Mensaje de prueba');
    await campo.fill('falla una vez');
    await campo.press('Enter');

    const registro = page.getByRole('log', { name: 'Conversación de prueba' });
    await expect(registro.getByText('falla una vez')).toBeVisible();
    await expect(registro.getByText('No se pudo enviar este mensaje.')).toBeVisible();

    await registro.getByRole('button', { name: 'Reintentar' }).click();
    await expect(registro.getByText('Respuesta de prueba a: falla una vez')).toBeVisible();
    await expect(registro.getByText('No se pudo enviar este mensaje.')).toBeHidden();
    // El mensaje original sigue en la conversación, una sola vez. El «Tú: » es texto solo para lectores de pantalla.
    await expect(registro.getByText(/^Tú: falla una vez$/)).toHaveCount(1);
  });

  test('muestra «escribiendo» mientras espera la respuesta', async ({ page }) => {
    const campo = page.getByLabel('Mensaje de prueba');
    await campo.fill('lento');
    await campo.press('Enter');

    const registro = page.getByRole('log', { name: 'Conversación de prueba' });
    await expect(registro.getByText('El asistente está escribiendo…')).toBeVisible();
    await expect(registro.getByText('Respuesta de prueba a: lento')).toBeVisible();
    await expect(registro.getByText('El asistente está escribiendo…')).toBeHidden();
  });

  test('el registro se desplaza solo hasta el último mensaje', async ({ page }, info) => {
    const campo = page.getByLabel('Mensaje de prueba');
    const registro = page.getByRole('log', { name: 'Conversación de prueba' });
    for (let n = 1; n <= 8; n += 1) {
      await campo.fill(`mensaje ${n}`);
      await campo.press('Enter');
      await expect(registro.getByText(`Respuesta de prueba a: mensaje ${n}`)).toBeVisible();
    }

    // Con 8 intercambios el registro desborda su altura máxima: el último mensaje debe estar a la vista.
    const medidas = await registro.evaluate((el) => ({ desborda: el.scrollHeight > el.clientHeight, alFinal: el.scrollTop + el.clientHeight >= el.scrollHeight - 2 }));
    expect(medidas.desborda).toBe(true);
    expect(medidas.alFinal).toBe(true);
    await capturar(page, info, 'asistente-probador');
  });

  test('el contador de caracteres solo aparece cerca del límite', async ({ page }) => {
    const campo = page.getByLabel('Mensaje de prueba');
    await campo.fill('corto');
    await expect(page.getByText(/\d+\/2000/)).toHaveCount(0);

    await campo.fill('a'.repeat(1850));
    await expect(page.getByText('1850/2000')).toBeVisible();
  });

  test('«Nueva conversación» vacía el registro', async ({ page }) => {
    const campo = page.getByLabel('Mensaje de prueba');
    await campo.fill('hola');
    await campo.press('Enter');
    const registro = page.getByRole('log', { name: 'Conversación de prueba' });
    await expect(registro.getByText('Respuesta de prueba a: hola')).toBeVisible();

    await page.getByRole('button', { name: 'Nueva conversación' }).click();
    await expect(registro.getByText('Escribe un mensaje para ver cómo responde el asistente.')).toBeVisible();
  });
});

test.describe('Resumen y conversaciones del asistente', () => {
  test('el resumen abre por omisión y las pestañas llevan a cada sección', async ({ page }, info) => {
    await abrir(page, '/admin/academico/asistente');
    const pestanas = page.getByRole('navigation', { name: 'Secciones del asistente' });
    await expect(pestanas.getByRole('link', { name: 'Resumen' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { name: '¿Sobre qué preguntan?' })).toBeVisible();
    await expect(page.getByText('3 de 41 consultas con tema')).toBeVisible();
    await expect(page.getByRole('log')).toHaveCount(0);
    await capturar(page, info, 'asistente-resumen');

    await pestanas.getByRole('link', { name: 'Conversaciones' }).click();
    await expect(page.getByRole('heading', { name: 'Visitante 7F3A' })).toBeVisible();
  });

  test('el resumen muestra hasta dónde llegan y lleva a las conversaciones de esa etapa', async ({ page }) => {
    await abrir(page, '/admin/academico/asistente');
    const fila = page.getByRole('link', { name: /Quieren matricularse/ });
    await expect(fila).toContainText('19%');
    await fila.click();
    await expect(page).toHaveURL(/vista=conversaciones&etapa=matricula/);
    await expect(page.locator('select[name="etapa"]')).toHaveValue('matricula');
  });

  test('cada tarjeta dice su etapa y de qué trató sin abrirla', async ({ page }) => {
    await abrir(page, '/admin/academico/asistente?vista=conversaciones');
    const tarjeta = page.locator('details').filter({ has: page.getByRole('heading', { name: 'Visitante 7F3A' }) });
    const cabecera = tarjeta.locator('summary');
    await expect(cabecera.getByText('Quiere matricularse')).toBeVisible();
    await expect(cabecera.getByText('Abrió WhatsApp')).toBeVisible();
    await expect(cabecera.getByText(/Para matricularte sigue estos pasos/)).toBeVisible();
    await expect(page.locator('summary').filter({ hasText: 'Visitante B21C' }).getByText('Contingencia', { exact: true })).toBeVisible();
  });

  test('cada conversación se abre como un chat, sin repetir el texto omitido', async ({ page }, info) => {
    await abrir(page, '/admin/academico/asistente?vista=conversaciones');
    await expect(page.getByText('contenido omitido')).toHaveCount(0);
    const tarjeta = page.locator('details').filter({ has: page.getByRole('heading', { name: 'Visitante 7F3A' }) });
    await expect(tarjeta.getByText('Visitante preguntó sobre: Cursos y capacitaciones')).toBeHidden();

    await tarjeta.getByRole('heading', { name: 'Visitante 7F3A' }).click();
    await expect(tarjeta.getByText('Visitante preguntó sobre: Cursos y capacitaciones')).toBeVisible();
    await expect(tarjeta.getByText('Ver completo')).toBeVisible();

    const bloqueada = page.locator('details').filter({ has: page.getByRole('heading', { name: 'Visitante B21C' }) });
    await bloqueada.getByRole('heading', { name: 'Visitante B21C' }).click();
    await expect(bloqueada.getByText('Bloqueada por privacidad')).toBeVisible();
    await expect(bloqueada.locator('ol').getByText('Contingencia', { exact: true })).toBeVisible();
    await capturar(page, info, 'asistente-conversaciones');
  });

  test('la página no se desplaza en horizontal', async ({ page }) => {
    for (const vista of ['', '?vista=conversaciones', '?vista=probar']) {
      await abrir(page, `/admin/academico/asistente${vista}`);
      const sobra = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(sobra, vista).toBeLessThanOrEqual(0);
    }
  });
});
