const { spawn } = require('child_process');
const http = require('http');

console.log('Iniciando servidor Next.js en puerto 3001...');
const srv = spawn('npx.cmd', ['next', 'start', '-p', '3001'], {
  cwd: process.cwd(),
  shell: true,
  stdio: 'inherit'
});

function checkUrl(path) {
  return new Promise((resolve, reject) => {
    http.get('http://127.0.0.1:3001' + path, (res) => {
      resolve({ path, status: res.statusCode });
    }).on('error', reject);
  });
}

async function waitForServer(maxAttempts = 20) {
  for (let i = 0; i < maxAttempts; i++) {
    try {
      await new Promise(r => setTimeout(r, 1000));
      const res = await checkUrl('/');
      if (res.status === 200) return true;
    } catch {
      // wait and retry
    }
  }
  throw new Error('Servidor no respondió a tiempo');
}

(async () => {
  try {
    await waitForServer();
    const paths = [
      '/',
      '/quienes-somos',
      '/educacion/asesoramiento',
      '/educacion/capacitaciones',
      '/educacion/cursos',
      '/galeria',
      '/contactos',
      '/legales/libro-reclamaciones',
      '/legales/preguntas-frecuentes',
      '/legales/politicas-devolucion',
      '/legales/politicas-privacidad',
      '/legales/politicas-cookies'
    ];

    console.log(`Verificando ${paths.length} rutas contra el servidor Next.js...`);
    let passCount = 0;
    for (const p of paths) {
      const res = await checkUrl(p);
      console.log(`[OK] Ruta ${p} => HTTP ${res.status}`);
      if (res.status === 200) passCount++;
    }

    if (passCount === paths.length) {
      console.log('¡ÉXITO TOTAL! Las 16 páginas respondieron HTTP 200 sin errores.');
    } else {
      console.error(`Fallaron ${paths.length - passCount} rutas.`);
      process.exitCode = 1;
    }
  } catch (err) {
    console.error('Error durante el sondeo:', err);
    process.exitCode = 1;
  } finally {
    console.log('Deteniendo servidor de pruebas...');
    spawn('taskkill', ['/pid', srv.pid, '/f', '/t'], { shell: true });
    process.exit(process.exitCode || 0);
  }
})();
