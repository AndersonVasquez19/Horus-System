import { expect, test } from '@playwright/test';
import { abrir, limpiarPeticiones, peticiones } from './ayudas';

test.describe('Formulario «Registrar estudiante»', () => {
  test.beforeEach(async ({ page }) => {
    await limpiarPeticiones(page);
    await abrir(page, '/admin/academico/estudiantes');
    await page.getByRole('button', { name: 'Nuevo estudiante' }).click();
    await expect(page.getByRole('dialog', { name: 'Registrar estudiante' })).toBeVisible();
  });

  test('un error de validación local marca el campo y le da el foco, sin llamar al servidor', async ({ page }) => {
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    await panel.getByRole('button', { name: 'Registrar estudiante' }).click();

    await expect(panel.getByText('El DNI debe tener 8 dígitos.')).toBeVisible();
    await expect(panel.getByRole('textbox', { name: 'DNI', exact: true })).toBeFocused();
    await expect(panel.getByRole('textbox', { name: 'DNI', exact: true })).toHaveAttribute('aria-invalid', 'true');
    expect((await peticiones(page)).filter((p) => p.metodo === 'POST')).toHaveLength(0);
  });

  test('un error 422 de Laravel cae en su campo, lo enfoca y se anuncia', async ({ page }) => {
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    await panel.getByRole('textbox', { name: 'DNI', exact: true }).fill('00000000');
    await panel.getByLabel('Nombres').fill('Ana');
    await panel.getByLabel('Apellidos').fill('Quispe');
    await panel.getByRole('button', { name: 'Registrar estudiante' }).click();

    const error = panel.getByText('Este DNI ya está registrado.');
    await expect(error).toBeVisible();
    await expect(error).toHaveAttribute('role', 'alert');
    await expect(panel.getByText('Revisa los campos marcados.')).toBeVisible();
    await expect(panel.getByRole('textbox', { name: 'DNI', exact: true })).toBeFocused();

    const envios = (await peticiones(page)).filter((p) => p.metodo === 'POST' && p.ruta === 'academico/estudiantes');
    expect(envios).toHaveLength(1);
    // Paridad con el cuerpo de siempre: los opcionales vacíos viajan como null.
    expect(envios[0].cuerpo).toMatchObject({ dni: '00000000', nombres: 'Ana', apellidos: 'Quispe', email: null, telefono: null, estado: 'activo' });
  });

  test('un registro correcto cierra el panel y muestra el aviso de éxito', async ({ page }) => {
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    await panel.getByRole('textbox', { name: 'DNI', exact: true }).fill('12345678');
    await panel.getByLabel('Nombres').fill('Rosa');
    await panel.getByLabel('Apellidos').fill('Mendoza');
    await panel.getByRole('button', { name: 'Registrar estudiante' }).click();
    await expect(page.getByText('Estudiante registrado correctamente.')).toBeVisible();
  });

  test('marca con asterisco los campos obligatorios', async ({ page }) => {
    const panel = page.getByRole('dialog', { name: 'Registrar estudiante' });
    // El asterisco es decorativo (aria-hidden); el control expone `required` a los lectores de pantalla.
    await expect(panel.getByRole('textbox', { name: 'DNI', exact: true })).toHaveAttribute('required', '');
    await expect(panel.locator('label', { hasText: 'DNI' }).locator('span[aria-hidden="true"]')).toHaveText('*');
  });
});

test.describe('Contraseñas', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('el botón mostrar/ocultar cambia el tipo del campo y expone su estado', async ({ page }) => {
    await page.goto('/registro');
    const campo = page.locator('input[name="password"]');
    await expect(campo).toHaveAttribute('type', 'password');

    const alternar = page.getByRole('button', { name: 'Mostrar contraseña' }).first();
    await alternar.click();
    await expect(campo).toHaveAttribute('type', 'text');
    await expect(page.getByRole('button', { name: 'Ocultar contraseña' }).first()).toHaveAttribute('aria-pressed', 'true');
  });
});
