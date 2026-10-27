import { cp, mkdir, readdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const source = join(root, 'legacy-html-node', 'assets');
const destination = join(root, 'public', 'assets');

for (const directory of ['css', 'images', 'js']) {
  const from = join(source, directory);
  const files = await readdir(from);
  if (files.length === 0) {
    throw new Error(`No hay recursos en ${from}`);
  }
  await mkdir(join(destination, directory), { recursive: true });
  await cp(from, join(destination, directory), { recursive: true, force: true });
}

console.log('Recursos públicos de Laravel sincronizados en public/assets.');
