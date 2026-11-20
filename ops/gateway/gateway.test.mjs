// Pruebas del gateway: reparto de rutas, proxy con servidores ficticios y configuración de servicios. `node --test ops/gateway`.
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import { crearGateway } from './proxy.mjs';
import { DESTINO, destinoDe } from './rutas.mjs';
import { definirServicios, destinoBaseDeDatos, resolverConfig, sondear } from './servicios.mjs';

describe('reparto de rutas', () => {
  test('Laravel recibe /up, /api/auth, /storage y las imágenes públicas', () => {
    for (const ruta of ['/up', '/api/auth/login', '/api/auth', '/storage/servicios/a.png', '/assets/images/x/y.jpg?v=1']) {
      assert.equal(destinoDe(ruta), DESTINO.LARAVEL, ruta);
    }
  });

  test('Next recibe la interfaz, el panel y su BFF', () => {
    for (const ruta of ['/', '/login', '/admin/academico/matriculas', '/api/bff/auth/login', '/_next/static/chunk.js', '/tecnologias/cableado-estructurado', '/upload']) {
      assert.equal(destinoDe(ruta), DESTINO.NEXT, ruta);
    }
  });

  test('la API del panel y la pública nunca salen al navegador', () => {
    for (const ruta of ['/api/panel/v1/auth/login', '/api/panel', '/api/public/v1/chat', '/API/../api/panel/v1/x', '/api/%70anel/v1/x', '//api/panel/v1/x', '/api/public%2Fv1/chat']) {
      assert.equal(destinoDe(ruta), DESTINO.BLOQUEADO, ruta);
    }
  });

  test('rutas con trucos de normalización se bloquean o caen en Next, nunca en Laravel por accidente', () => {
    assert.equal(destinoDe('/storage/../api/panel/v1/x'), DESTINO.BLOQUEADO);
    assert.equal(destinoDe('/%2e%2e/api/panel'), DESTINO.BLOQUEADO);
    assert.equal(destinoDe('/up%00'), DESTINO.BLOQUEADO);
    assert.equal(destinoDe('/%E0%A4%A'), DESTINO.BLOQUEADO);
    assert.equal(destinoDe('/uploads'), DESTINO.NEXT, '/uploads no es /up');
    assert.equal(destinoDe('/api/authx'), DESTINO.NEXT, '/api/authx no es /api/auth');
  });
});

describe('proxy', () => {
  const recibido = { laravel: [], next: [] };
  let laravel;
  let next;
  let gateway;
  let puertoGateway;

  const escuchar = (servidor) => new Promise((r) => servidor.listen(0, '127.0.0.1', () => r(servidor.address().port)));
  const pedir = (opciones, cuerpo) =>
    new Promise((resolver, rechazar) => {
      const req = http.request({ host: '127.0.0.1', port: puertoGateway, ...opciones }, (res) => {
        const trozos = [];
        res.on('data', (t) => trozos.push(t));
        res.on('end', () => resolver({ estado: res.statusCode, cabeceras: res.headers, cuerpo: Buffer.concat(trozos).toString() }));
      });
      req.on('error', rechazar);
      req.end(cuerpo);
    });
  const falso = (nombre) =>
    http.createServer((req, res) => {
      const trozos = [];
      req.on('data', (t) => trozos.push(t));
      req.on('end', () => {
        recibido[nombre].push({ metodo: req.method, url: req.url, cabeceras: req.headers, cuerpo: Buffer.concat(trozos).toString() });
        if (req.url === '/redirigir') {
          res.writeHead(302, { location: '/destino', 'set-cookie': ['a=1; HttpOnly', 'b=2'] });
          return res.end();
        }
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end(`${nombre}:${req.method}:${req.url}`);
      });
    });

  before(async () => {
    laravel = falso('laravel');
    next = falso('next');
    next.on('upgrade', (req, socket) => {
      socket.write('HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\neco');
      socket.end();
    });
    const pl = await escuchar(laravel);
    const pn = await escuchar(next);
    gateway = crearGateway({ laravel: { host: '127.0.0.1', port: pl }, next: { host: '127.0.0.1', port: pn } });
    puertoGateway = await escuchar(gateway);
  });
  after(() => {
    for (const s of [gateway, laravel, next]) s.close();
    gateway.closeAllConnections?.();
  });

  test('reparte cada ruta a su servicio', async () => {
    assert.equal((await pedir({ path: '/up' })).cuerpo, 'laravel:GET:/up');
    assert.equal((await pedir({ path: '/admin/x?y=1' })).cuerpo, 'next:GET:/admin/x?y=1');
  });

  test('bloquea la API interna con 404 y no llega a ningún servicio', async () => {
    const antes = recibido.laravel.length + recibido.next.length;
    const r = await pedir({ path: '/api/panel/v1/auth/login', method: 'POST' }, '{}');
    assert.equal(r.estado, 404);
    assert.equal(JSON.parse(r.cuerpo).error.code, 'no_expuesta');
    assert.equal(recibido.laravel.length + recibido.next.length, antes);
  });

  test('conserva Host, método, cuerpo y cookies; no confía en X-Forwarded-For entrante', async () => {
    const r = await pedir({ path: '/api/auth/login', method: 'POST', headers: { host: 'horus.test', 'x-forwarded-for': '6.6.6.6', cookie: 's=1', 'content-type': 'application/json' } }, '{"a":1}');
    const llegada = recibido.laravel.at(-1);
    assert.equal(r.estado, 200);
    assert.equal(llegada.cabeceras.host, 'horus.test', 'el BFF deriva su origen del Host original');
    assert.equal(llegada.cuerpo, '{"a":1}');
    assert.equal(llegada.cabeceras.cookie, 's=1');
    assert.notEqual(llegada.cabeceras['x-forwarded-for'], '6.6.6.6');
    assert.equal(llegada.cabeceras['x-forwarded-host'], 'horus.test');
  });

  test('devuelve redirecciones y cookies tal cual, sin seguirlas', async () => {
    const r = await pedir({ path: '/redirigir' });
    assert.equal(r.estado, 302);
    assert.equal(r.cabeceras.location, '/destino');
    assert.deepEqual(r.cabeceras['set-cookie'], ['a=1; HttpOnly', 'b=2']);
  });

  test('un servicio caído responde 502 sin colgar el gateway', async () => {
    const muerto = await new Promise((r) => {
      const s = net.createServer().listen(0, '127.0.0.1', () => {
        const puerto = s.address().port;
        s.close(() => r(puerto));
      });
    });
    const g = crearGateway({ laravel: { host: '127.0.0.1', port: muerto }, next: { host: '127.0.0.1', port: muerto } });
    const p = await escuchar(g);
    const r = await new Promise((resolver) => http.get({ host: '127.0.0.1', port: p, path: '/up' }, (res) => {
      let t = '';
      res.on('data', (c) => (t += c));
      res.on('end', () => resolver({ estado: res.statusCode, cuerpo: t }));
    }));
    g.close();
    assert.equal(r.estado, 502);
    assert.equal(JSON.parse(r.cuerpo).error.code, 'servicio_no_disponible');
  });

  test('encadena el WebSocket hacia Next y rechaza el de otras rutas', async () => {
    const abrir = (ruta) =>
      new Promise((resolver) => {
        const socket = net.connect(puertoGateway, '127.0.0.1', () => socket.write(`GET ${ruta} HTTP/1.1\r\nHost: x\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n\r\n`));
        let datos = '';
        socket.on('data', (d) => (datos += d));
        socket.on('close', () => resolver(datos));
        socket.on('error', () => resolver(datos));
      });
    assert.match(await abrir('/_next/webpack-hmr'), /101 Switching Protocols/);
    assert.match(await abrir('/api/panel/v1/ws'), /404/);
  });
});

describe('configuración de servicios', () => {
  test('valores por defecto y banderas', () => {
    const cfg = resolverConfig([], {});
    assert.deepEqual(cfg.puertos, { gateway: 8080, laravel: 8000, next: 3000, ai: 8001 });
    assert.equal(cfg.modo, 'dev');
    assert.equal(cfg.chatbot, true);
    const sin = resolverConfig(['--sin-chatbot', '--semantico', '--puerto=9090', '--modo=prod'], {});
    assert.equal(sin.chatbot, false);
    assert.equal(sin.semantico, false, 'sin chatbot no hay modo híbrido');
    assert.equal(sin.puertos.gateway, 9090);
    assert.equal(sin.modo, 'prod');
  });

  test('rechaza opciones, modos y puertos inválidos o repetidos', () => {
    assert.throws(() => resolverConfig(['--otra'], {}), /desconocida/);
    assert.throws(() => resolverConfig(['--modo=xx'], {}), /dev o prod/);
    assert.throws(() => resolverConfig(['--puerto=80'], {}), /puerto no válido/);
    assert.throws(() => resolverConfig(['--puerto=8000'], {}), /distintos/);
  });

  test('los secretos: los del entorno si son válidos, aleatorios si no, y nunca en los argumentos', () => {
    const largo = 'x'.repeat(40);
    assert.equal(resolverConfig([], { HORUS_WEB_SERVICE_TOKEN: largo }).tokens.servicioWeb, largo);
    const a = resolverConfig([], { HORUS_WEB_SERVICE_TOKEN: 'corto' });
    const b = resolverConfig([], {});
    assert.ok(a.tokens.servicioWeb.length >= 32 && a.tokens.servicioWeb !== 'corto');
    assert.notEqual(a.tokens.servicioWeb, b.tokens.servicioWeb);
    const servicios = definirServicios(a);
    for (const s of servicios) assert.ok(!s.args.join(' ').includes(a.tokens.servicioWeb), `${s.nombre}: el token no va en la línea de comandos`);
  });

  test('Laravel, Next y Horus AI quedan conectados entre sí con los mismos secretos', () => {
    const cfg = resolverConfig(['--puerto=8123'], {});
    const por = Object.fromEntries(definirServicios(cfg).map((s) => [s.nombre, s]));
    assert.equal(por.laravel.env.HORUS_WEB_URL, 'http://127.0.0.1:8123');
    assert.equal(por.laravel.env.HORUS_WEB_SERVICE_TOKEN, por.next.env.HORUS_WEB_SERVICE_TOKEN);
    assert.equal(por.next.env.HORUS_API_URL, 'http://127.0.0.1:8000');
    assert.match(por.next.env.HORUS_WEB_ORIGIN, /http:\/\/127\.0\.0\.1:8123/);
    assert.equal(por.laravel.env.HORUS_ADVISOR_URL, 'http://127.0.0.1:8001');
    assert.equal(por.laravel.env.HORUS_ADVISOR_TOKEN, por.ai.env.HORUS_AI_SERVICE_TOKEN);
    assert.equal(por.laravel.env.HORUS_ADVISOR_ENABLED, 'true');
    assert.ok(por.ai.args.includes('127.0.0.1'), 'el chatbot solo escucha en loopback');
    assert.equal(por.laravel.env.HORUS_AI_CATALOG_SYNC_ENABLED, undefined, 'la sincronización de catálogo se hereda del .env');
    assert.equal(por.laravel.env.HORUS_OPS_RENEW_CATALOG, undefined);
  });

  test('sin chatbot no hay proceso de Horus AI y Laravel apaga el asesor', () => {
    const servicios = definirServicios(resolverConfig(['--sin-chatbot'], {}));
    assert.deepEqual(servicios.map((s) => s.nombre), ['laravel', 'next']);
    assert.equal(servicios[0].env.HORUS_ADVISOR_ENABLED, 'false');
  });

  test('con sincronización de catálogo activa se levanta el worker compartiendo el token de Horus AI', () => {
    const servicios = definirServicios(resolverConfig([], { HORUS_AI_CATALOG_SYNC_ENABLED: 'true' }));
    const por = Object.fromEntries(servicios.map((s) => [s.nombre, s]));
    assert.deepEqual(servicios.map((s) => s.nombre), ['laravel', 'next', 'ai', 'worker']);
    assert.equal(por.worker.salud, undefined);
    assert.equal(por.worker.env.HORUS_ADVISOR_TOKEN, por.ai.env.HORUS_AI_SERVICE_TOKEN);
    assert.equal(por.worker.env.HORUS_OPS_ENABLED, 'true');
  });
});

describe('arranque de Laravel y comprobaciones de salud', () => {
  test('php -S corre con public/ como directorio actual y el enrutador de Laravel por ruta absoluta', () => {
    const laravel = definirServicios(resolverConfig([], {})).find((s) => s.nombre === 'laravel');
    const plano = (ruta) => ruta.split(path.sep).join('/');
    assert.match(plano(laravel.cwd), /apps\/api\/public$/);
    assert.ok(plano(laravel.args.at(-1)).endsWith('Foundation/resources/server.php'));
    assert.ok(laravel.args.at(-1).includes(':') || laravel.args.at(-1).startsWith('/'), 'ruta absoluta');
  });

  test('un 200 con un error de PHP impreso NO cuenta como servicio sano', async () => {
    const roto = http.createServer((req, res) => res.end('<b>Warning</b>: require_once(...): Failed to open stream'));
    const sano = http.createServer((req, res) => res.end('<html>Application up</html>'));
    const puerto = (s) => new Promise((r) => s.listen(0, '127.0.0.1', () => r(s.address().port)));
    const [pr, ps] = [await puerto(roto), await puerto(sano)];
    assert.equal(await sondear({ puerto: pr, ruta: '/up', contiene: 'Application up' }), false);
    assert.equal(await sondear({ puerto: ps, ruta: '/up', contiene: 'Application up' }), true);
    assert.equal(await sondear({ puerto: pr, ruta: '/' }), true, 'sin texto esperado basta con no ser 5xx');
    roto.close();
    sano.close();
  });

  test('el aviso de base de datos respeta las variables del entorno y no imprime credenciales', () => {
    assert.equal(destinoBaseDeDatos({ DB_CONNECTION: 'pgsql', DB_HOST: '10.0.0.5', DB_PORT: '5432', DB_DATABASE: 'horus', DB_PASSWORD: 'secreto' }), 'pgsql://10.0.0.5:5432/horus');
    assert.equal(destinoBaseDeDatos({ DB_CONNECTION: 'sqlite', DB_DATABASE: 'C:/x/demo.sqlite' }), 'sqlite (demo.sqlite)');
    assert.ok(!destinoBaseDeDatos({ DB_CONNECTION: 'pgsql', DB_PASSWORD: 'secreto' }).includes('secreto'));
  });
});
