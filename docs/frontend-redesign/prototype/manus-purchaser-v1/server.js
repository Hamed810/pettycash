const http = require('http');
const fs = require('fs');
const path = require('path');

const root = __dirname;
const port = Number(process.env.PORT || 3000);
const mime = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.md': 'text/markdown; charset=utf-8'
};

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  let requested = decodeURIComponent(url.pathname);
  if (requested === '/') requested = '/index.html';
  if (requested === '/manus-routes.json') requested = '/public/manus-routes.json';
  const safePath = path.normalize(requested).replace(/^([.][.][/\\])+/, '');
  let filePath = path.join(root, safePath);
  if (!filePath.startsWith(root)) { res.writeHead(403); res.end('Forbidden'); return; }
  if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) filePath = path.join(root, 'index.html');
  const ext = path.extname(filePath).toLowerCase();
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('Content-Type', mime[ext] || 'application/octet-stream');
  fs.createReadStream(filePath).on('error', () => { res.writeHead(500); res.end('Server error'); }).pipe(res);
});

server.listen(port, '0.0.0.0', () => console.log(`Pettycash prototype listening on 0.0.0.0:${port}`));
