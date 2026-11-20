import { expect, test } from '@playwright/test';
import { abrir, capturar, limpiarPeticiones } from './ayudas';

/** Probador del asistente virtual y su historial. El servidor falso responde «Respuesta de prueba a: <mensaje>». */
test.describe('Probador del asistente', () => {
  test.beforeEach(async ({ page }) => {
    await limpiarPeticiones(page);
    await abrir(page, '/admin/academico/asistente');
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

test.describe('Historial del asistente', () => {
  test.beforeEach(async ({ page }) => {
    await abrir(page, '/admin/academico/asistente');
  });

  test('un mensaje largo se recorta y se despliega con «Ver completo»', async ({ page }) => {
    const celda = page.getByRole('cell').filter({ hasText: 'Mensaje muy largo del historial' });
    const detalle = celda.locator('details');

    // Cerrado: el texto se recorta a tres líneas y el control invita a ver el resto.
    await expect(detalle).not.toHaveAttribute('open', '');
    await expect(celda.locator('.line-clamp-3')).toBeVisible();
    await expect(celda.getByText('Ver completo')).toBeVisible();
    const alturaCerrado = (await detalle.boundingBox())!.height;

    // Abierto: sin recorte, más alto, y el control pasa a «Ver menos».
    await celda.getByText('Ver completo').click();
    await expect(detalle).toHaveAttribute('open', '');
    await expect(celda.locator('.line-clamp-3')).toBeHidden();
    await expect(celda.getByText('Ver menos')).toBeVisible();
    expect((await detalle.boundingBox())!.height).toBeGreaterThan(alturaCerrado);
  });

  test('un mensaje corto se muestra entero, sin control', async ({ page }) => {
    const celda = page.getByRole('cell', { name: '¿Cuánto cuesta el curso?' });
    await expect(celda).toBeVisible();
    await expect(celda.getByText('Ver completo')).toHaveCount(0);
  });

  test('las intenciones configuradas están plegadas y se pueden abrir', async ({ page }) => {
    const bloque = page.locator('details').filter({ hasText: 'Intenciones configuradas' });
    await expect(bloque).not.toHaveAttribute('open', '');
    await expect(page.getByRole('table', { name: /^Intenciones/ })).toBeHidden();

    await bloque.getByText('Intenciones configuradas (2)').click();
    await expect(page.getByRole('table', { name: /^Intenciones/ })).toBeVisible();
    await expect(page.getByText('Inactiva')).toBeVisible();
  });
});
