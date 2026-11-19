// Proxy inverso del gateway (solo módulos de Node, sin dependencias). Conserva el encabezado Host original: el BFF de Next
// deriva de él el origen propio para su comprobación CSRF, y Laravel genera enlaces a partir de HORUS_WEB_URL.

import http from 'node:http';
import net from 'node:net';
import { DESTINO, destinoDe } from './rutas.mjs';

// Encabezados que describen un salto, no el mensaje: no se reenvían.
const SALTO_A_SALTO = new Set(['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade']);

function encabezadosHaciaAtras(original, req) {
  const salida = {};
  for (const [nombre, valor] of Object.entries(original)) {
    if (!SALTO_A_SALTO.has(nombre.toLowerCase())) salida[nombre] = valor;
  }
  const ipCliente = req.socket.remoteAddress ?? '';
  // El gateway es el único que decide quién es el cliente: no se confía en un X-Forwarded-For entrante.
  salida['x-forwarded-for'] = ipCliente;
  salida['x-forwarded-host'] = req.headers.host ?? '';
  salida['x-forwarded-proto'] = 'http';
  return salida;
}

function responderJson(res, estado, cuerpo) {
  const texto = JSON.stringify(cuerpo);
  res.writeHead(estado, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store', 'content-length': Buffer.byteLength(texto) });
  res.end(texto);
}

/**
 * @param {{ laravel: {host: string, port: number}, next: {host: string, port: number} }} destinos
 * @param {{ registrar?: (linea: string) => void }} [opciones]
 */
export function crearGateway(destinos, opciones = {}) {
  const registrar = opciones.registrar ?? (() => {});

  const servidor = http.createServer((req, res) => {
    const destino = destinoDe(req.url ?? '/');
    if (destino === DESTINO.BLOQUEADO) {
      registrar(`${req.method} ${req.url} -> bloqueado`);
      return responderJson(res, 404, { error: { code: 'no_expuesta', message: 'Esta ruta no se expone fuera del servidor.' } });
    }
    const objetivo = destinos[destino];
    const salida = http.request(
      { host: objetivo.host, port: objetivo.port, method: req.method, path: req.url, headers: encabezadosHaciaAtras(req.headers, req) },
      (respuesta) => {
        registrar(`${req.method} ${req.url} -> ${destino} ${respuesta.statusCode}`);
        const cabeceras = {};
        for (const [nombre, valor] of Object.entries(respuesta.headers)) {
          if (!SALTO_A_SALTO.has(nombre.toLowerCase())) cabeceras[nombre] = valor;
        }
        res.writeHead(respuesta.statusCode ?? 502, respuesta.statusMessage, cabeceras);
        respuesta.pipe(res);
      },
    );
    salida.on('error', (error) => {
      registrar(`${req.method} ${req.url} -> ${destino} ERROR ${error.code ?? error.message}`);
      if (!res.headersSent) responderJson(res, 502, { error: { code: 'servicio_no_disponible', message: `El servicio ${destino} no responde.` } });
      else res.destroy();
    });
    req.on('aborted', () => salida.destroy());
    req.pipe(salida);
  });

  // WebSocket (recarga en caliente de `next dev`): se envía a Next y se encadenan los sockets.
  servidor.on('upgrade', (req, socket, head) => {
    if (destinoDe(req.url ?? '/') !== DESTINO.NEXT) {
      socket.end('HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n');
      return;
    }
    const conexion = net.connect(destinos.next.port, destinos.next.host, () => {
      const lineas = [`${req.method} ${req.url} HTTP/1.1`];
      for (let i = 0; i < req.rawHeaders.length; i += 2) lineas.push(`${req.rawHeaders[i]}: ${req.rawHeaders[i + 1]}`);
      conexion.write(`${lineas.join('\r\n')}\r\n\r\n`);
      if (head.length > 0) conexion.write(head);
      socket.pipe(conexion).pipe(socket);
    });
    conexion.on('error', () => socket.destroy());
    socket.on('error', () => conexion.destroy());
  });

  return servidor;
}
