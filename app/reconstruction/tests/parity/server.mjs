// Local fixture server. Only static reference/rebuilt assets are served; all APIs
// are fulfilled by the browser test. No proxy to WordPress or Zoho exists.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const mime = { '.js': 'application/javascript', '.css': 'text/css', '.map': 'application/json' };
const server = http.createServer((request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1:4178');
  if (url.pathname === '/health') return response.end('fixture-only');
  const variant = url.pathname.match(/^\/(reference|rebuilt)\/?$/)?.[1];
  if (variant) {
    const boot = JSON.stringify({
      restUrl: 'http://127.0.0.1:4178/wp-json/tc-estimate/v1',
      ajaxUrl: 'http://127.0.0.1:4178/wp-admin/admin-ajax.php',
      nonce: 'fixture-nonce',
      brand: { name: 'Temp Control Fixture', primary: '#214c7a' },
    }).replaceAll('&', '&amp;').replaceAll('"', '&quot;');
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    return response.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/${variant}/estimate-builder.css"><style>body{margin:0;background:#fff}</style></head><body><div id="tc-estimate-builder-root" data-boot="${boot}"></div><script data-tc-estimate-bundle src="/assets/${variant}/estimate-builder.js"></script></body></html>`);
  }
  const asset = url.pathname.match(/^\/assets\/(reference|rebuilt)\/(estimate-builder\.(?:js|css|js\.map)|service-worker\.js)$/);
  if (asset) {
    const file = path.resolve(root, asset[1] === 'reference' ? '../dist' : 'dist', asset[2]);
    if (fs.existsSync(file)) {
      response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
      return fs.createReadStream(file).pipe(response);
    }
  }
  response.writeHead(404, { 'Content-Type': 'text/plain' });
  response.end('No fixture exists for this path. External services are unavailable.');
});
server.listen(4178, '127.0.0.1');
for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => server.close(() => process.exit(0)));
