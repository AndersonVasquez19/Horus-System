import { readdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';

const testRoots = ['tests/frontend', 'tests/browser'];
const javascriptExtensions = new Set(['.js', '.cjs', '.mjs', '.ts', '.cts', '.mts', '.tsx', '.jsx']);
const testNamePattern = /(?:^|[.-])(?:test|spec)\.(?:mjs)$/i;
const invalidFiles = [];
const testFiles = [];

async function visit(directory) {
  let entries;
  try {
    entries = await readdir(directory, { withFileTypes: true });
  } catch (error) {
    if (error.code === 'ENOENT') return;
    throw error;
  }

  for (const entry of entries) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      await visit(path);
      continue;
    }

    if (!entry.isFile() || !javascriptExtensions.has(path.slice(path.lastIndexOf('.')))) continue;
    if (!path.endsWith('.mjs')) invalidFiles.push(path);
    if (testNamePattern.test(entry.name)) testFiles.push(path);
  }
}

for (const root of testRoots) await visit(root);

if (invalidFiles.length) {
  console.error('Las pruebas y smokes JavaScript del frontend deben usar la extensión .mjs:');
  for (const path of invalidFiles) console.error(`- ${path}`);
  process.exitCode = 1;
} else if (testFiles.length === 0) {
  console.log('Convención verificada: los archivos JavaScript de tests/frontend y tests/browser usan .mjs. No hay pruebas unitarias *.test.mjs o *.spec.mjs para ejecutar.');
} else {
  const result = spawnSync(process.execPath, ['--test', ...testFiles], { stdio: 'inherit' });
  process.exitCode = result.status ?? 1;
}
