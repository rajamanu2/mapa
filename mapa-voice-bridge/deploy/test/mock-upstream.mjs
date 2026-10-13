// Local test fixture, never the native MAPA API. No account or call is created.
import http from 'node:http';
import { createHash } from 'node:crypto';
const server = http.createServer((_req, res) => { res.writeHead(200); res.end('PRIVATE-UPSTREAM-FIXTURE'); });
server.on('upgrade', (req, socket) => {
  const key = req.headers['sec-websocket-key'];
  if (!key) return socket.end('HTTP/1.1 400 Bad Request\r\n\r\n');
  const accept = createHash('sha1').update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64');
  socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`);
  socket.once('data', () => {
    const text = Buffer.from(JSON.stringify({ uri: req.url, host: req.headers.host,
      auth_digest: createHash('sha256').update(req.headers.authorization || '').digest('hex'),
      cookie: req.headers.cookie || null, origin: req.headers.origin || null }));
    const header = Buffer.alloc(4); header[0] = 0x81; header[1] = 126; header.writeUInt16BE(text.length, 2);
    socket.write(Buffer.concat([header, text]));
  });
});
server.listen(8789, '0.0.0.0');
