#!/usr/bin/env node
// Gateway de Horus System: levanta Laravel (API), Next.js (interfaz) y Horus AI (chatbot en Python) con un solo comando y
// los publica detrás de UN solo origen. Uso:  node ops/gateway/gateway.mjs [opciones]   (o `npm run gateway` desde la raíz)

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { crearGateway } from './proxy.mjs';
import {
  RAIZ,
  RUNTIME,
  arrancar,
  colaDeLog,
  definirServicios,
  destinoBaseDeDatos,
  entornoConDefaultsApi,
  matarArbol,
  puertoLibre,
  resolverConfig,
  sondear,
  verificarRequisitos,
} from './servicios.mjs';

const AYUDA = `Gateway de Horus System

  node ops/gateway/gateway.mjs [opciones]

  --modo=dev|prod     dev (por defecto): next dev. prod: next start (compila antes si falta el build).
  --build             fuerza \`next build\` antes de arrancar (solo con --modo=prod).
  --puerto=N          puerto del gateway (por defecto 8080, o GATEWAY_PORT).
  --sin-chatbot       no levanta Horus AI; Laravel responde con la contingencia institucional.
  --semantico         activa el modo híbrido (embeddings) del chatbot; necesita ~3 GB libres.

Variables opcionales: HORUS_LARAVEL_PORT (8000), HORUS_NEXT_PORT (3000), HORUS_AI_PORT (8001), HORUS_WEB_SERVICE_TOKEN y
HORUS_AI_SERVICE_TOKEN (32 caracteres o más; si faltan se genera uno aleatorio por ejecución).`;

const log = (texto) => console.log(texto);
const fallar = (texto) => {
  console.error(`\n✖ ${texto}`);
  process.exit(1);
};

if (process.argv.includes('--ayuda') || process.argv.includes('-h')) {
  log(AYUDA);
  process.exit(0);
}

let cfg;
try {
  cfg = resolverConfig(process.argv.slice(2), entornoConDefaultsApi(process.env));
} catch (error) {
  fallar(`${error.message}\n\n${AYUDA}`);
}

const faltas = verificarRequisitos(cfg);
if (faltas.length > 0) fallar(`No se puede arrancar:\n  - ${faltas.join('\n  - ')}`);

const servicios = definirServicios(cfg);
for (const [nombre, numero] of Object.entries(cfg.puertos)) {
  if (nombre === 'ai' && !cfg.chatbot) continue;
  if (!(await puertoLibre(numero))) fallar(`El puerto ${numero} (${nombre}) ya está en uso. Cierra lo que lo ocupa o cambia el puerto.`);
}

const procesos = [];
let servidor = null;
let cerrando = false;

function cerrar(codigo) {
  if (cerrando) return;
  cerrando = true;
  log('\nApagando servicios…');
  servidor?.close();
  for (const p of procesos) matarArbol(p.hijo);
  setTimeout(() => process.exit(codigo), 300);
}
process.on('SIGINT', () => cerrar(0));
process.on('SIGTERM', () => cerrar(0));
process.on('exit', () => procesos.forEach((p) => matarArbol(p.hijo)));

// `--modo=prod`: Next necesita su compilación.
if (cfg.modo === 'prod' && (cfg.build || !fs.existsSync(path.join(servicios[1].cwd, '.next', 'BUILD_ID')))) {
  log('Compilando apps/web (next build)…');
  const build = spawnSync(process.execPath, [servicios[1].args[0], 'build'], { cwd: servicios[1].cwd, stdio: 'inherit', env: { ...process.env, ...servicios[1].env } });
  if (build.status !== 0) fallar('next build falló.');
}

log(`Horus System — gateway en ${cfg.origen}  (modo ${cfg.modo})`);
log(`  Base de datos de Laravel: ${destinoBaseDeDatos()}  (del entorno o de apps/api/.env; el gateway no la cambia)`);
log(`  Chatbot: ${cfg.chatbot ? `Horus AI en 127.0.0.1:${cfg.puertos.ai} (${cfg.semantico ? 'híbrido' : 'modo según apps/ai/.env'}; catálogo según .env)` : 'desactivado (--sin-chatbot)'}`);
log('Iniciando servicios…');

if (cfg.chatbot && cfg.syncCatalogo) {
  // En Windows `taskkill /F` corta PHP sin liberar el lock de 1 h; se limpia si quedó de una ejecución previa.
  spawnSync(
    'php',
    [
      '-r',
      "require 'vendor/autoload.php'; $a = require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); Illuminate\\Support\\Facades\\Cache::lock('horus.ops.worker.lock')->forceRelease();",
    ],
    { cwd: path.join(RAIZ, 'apps', 'api'), stdio: 'ignore' },
  );
}

for (const servicio of servicios) {
  const { hijo, archivoLog } = arrancar(servicio);
  procesos.push({ servicio, hijo, archivoLog });
  hijo.on('exit', (codigo) => {
    if (cerrando) return;
    const cola = colaDeLog(archivoLog);
    if (servicio.nombre === 'worker' && cola.includes('worker_already_running')) {
      log('  ℹ worker ya estaba activo externamente; el gateway continúa');
      return;
    }
    console.error(`\n✖ ${servicio.nombre} se detuvo (código ${codigo}). Últimas líneas de ${archivoLog}:\n${cola}`);
    cerrar(1);
  });
}

// Esperar a que cada servicio responda su comprobación de salud.
await Promise.all(
  procesos.map(async ({ servicio }) => {
    if (!servicio.salud) {
      log(`  ✔ ${servicio.nombre} iniciado`);
      return;
    }
    const inicio = Date.now();
    while (!cerrando && Date.now() - inicio < servicio.salud.maxSegundos * 1000) {
      if (await sondear(servicio.salud)) {
        log(`  ✔ ${servicio.nombre} listo (${((Date.now() - inicio) / 1000).toFixed(1)} s)`);
        return;
      }
      await new Promise((r) => setTimeout(r, 500));
    }
    if (!cerrando) {
      console.error(`\n✖ ${servicio.nombre} no respondió en ${servicio.salud.maxSegundos} s.`);
      cerrar(1);
    }
  }),
);
if (cerrando) await new Promise(() => {});

if (cfg.chatbot && cfg.syncCatalogo) {
  const worker = servicios.find((s) => s.nombre === 'worker');
  const sync = spawnSync('php', ['artisan', 'horus:advisor-operacion', 'sync', '--execute'], {
    cwd: path.join(RAIZ, 'apps', 'api'),
    env: { ...process.env, ...worker.env },
    encoding: 'utf8',
  });
  if (sync.status === 0) log('  ✔ sincronización inicial de catálogo despachada');
  else log(`  ⚠ sincronización inicial de catálogo devolvió código ${sync.status ?? 'desconocido'}`);
}

fs.mkdirSync(path.join(RUNTIME, 'logs'), { recursive: true });
const archivoGateway = path.join(RUNTIME, 'logs', 'gateway.log');
const flujo = fs.createWriteStream(archivoGateway, { flags: 'w' });
servidor = crearGateway(
  { laravel: { host: '127.0.0.1', port: cfg.puertos.laravel }, next: { host: '127.0.0.1', port: cfg.puertos.next } },
  { registrar: (linea) => flujo.write(`${new Date().toISOString()} ${linea}\n`) },
);
servidor.on('error', (error) => fallar(`El gateway no pudo abrir el puerto ${cfg.puertos.gateway}: ${error.message}`));
servidor.listen(cfg.puertos.gateway, '127.0.0.1', () => {
  log(`\nTodo listo → ${cfg.origen}`);
  log('  Interfaz y panel : /  y  /admin');
  log('  API de Laravel   : /api/auth, /up, /storage (el panel y la API pública solo se alcanzan por el BFF de Next)');
  log(`  Registros        : ${path.relative(process.cwd(), path.join(RUNTIME, 'logs')) || RUNTIME}`);
  log('Ctrl+C para apagar todo.');
});
