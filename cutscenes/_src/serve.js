const http = require('http'), fs = require('fs'), path = require('path');
const PUB = path.resolve(__dirname, '../../public'), SCR = __dirname;
const T = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.json': 'application/json', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
http.createServer((q, r) => {
  let u = decodeURIComponent(q.url.split('?')[0]);
  let f = u.startsWith('/scratch/') ? path.join(SCR, u.slice(9)) : path.join(PUB, u);
  if (u.endsWith('/')) f = path.join(f, 'index.html');
  fs.readFile(f, (e, d) => { if (e) { r.writeHead(404); r.end(); return; } r.writeHead(200, { 'Content-Type': T[path.extname(f)] || 'application/octet-stream', 'Cache-Control': 'no-store' }); r.end(d); });
}).listen(+process.argv[2], () => console.log('up', process.argv[2]));
