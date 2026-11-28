import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { abrir } from './ayudas';

/**
 * Accesibilidad automática con axe (WCAG 2.0/2.1 A y AA). Axe detecta una parte de los problemas (contraste, nombres
 * accesibles, roles ARIA, etiquetas); no sustituye a una revisión manual con lector de pantalla.
 * Se exigen cero violaciones graves (`serious` o `critical`).
 */
async function violacionesGraves(page: Page) {
  const resultado = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  return resultado.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id} (${v.impact}): ${v.help} → ${v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')} ⟶ ${n.html.replace(/\s+/g, ' ').slice(0, 220)}`).join(' | ')}`);
}

test.describe('Páginas del panel', () => {
  const rutas: [string, string][] = [
    ['Tablero', '/admin/academico'],
    ['Estudiantes', '/admin/academico/estudiantes'],
    ['Cursos', '/admin/academico/cursos'],
    ['Notificaciones', '/admin/academico/notificaciones'],
    ['Reportes (matrículas)', '/admin/academico/reportes'],
    ['Reportes (cobranza, con gráfico)', '/admin/academico/reportes?tipo=cobranza'],
    ['Asistente virtual', '/admin/academico/asistente'],
  ];
  for (const [nombre, ruta] of rutas) {
    test(`${nombre}: sin violaciones graves`, async ({ page }) => {
      await abrir(page, ruta);
      expect(await violacionesGraves(page)).toEqual([]);
    });
  }

  test('Estudiantes con el menú ⋯ abierto: sin violaciones graves', async ({ page }) => {
    await abrir(page, '/admin/academico/estudiantes');
    await page.getByRole('button', { name: 'Acciones para Luis Torres Díaz' }).click();
    await expect(page.getByRole('menu')).toBeVisible();
    expect(await violacionesGraves(page)).toEqual([]);
  });

  test('Panel «Registrar estudiante» abierto: sin violaciones graves', async ({ page }) => {
    await abrir(page, '/admin/academico/estudiantes');
    await page.getByRole('button', { name: 'Nuevo estudiante' }).click();
    await expect(page.getByRole('dialog', { name: 'Registrar estudiante' })).toBeVisible();
    expect(await violacionesGraves(page)).toEqual([]);
  });
});

test.describe('Páginas públicas del panel', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  const rutasAcceso: [string, string, string][] = [
    ['Inicio de sesión', '/login', 'Inicia sesión'],
    ['Registro', '/registro', 'Crea tu cuenta'],
    ['Recuperar contraseña', '/recuperar-contrasena', 'Restablece tu contraseña'],
    ['Restablecer contraseña', '/restablecer-contrasena', 'Crea tu contraseña nueva'],
  ];

  for (const [nombre, ruta, titulo] of rutasAcceso) {
    test(`${nombre}: sin violaciones graves`, async ({ page }) => {
      await page.goto(ruta);
      await expect(page.getByRole('heading', { name: titulo })).toBeVisible();
      expect(await violacionesGraves(page)).toEqual([]);
    });
  }
});
