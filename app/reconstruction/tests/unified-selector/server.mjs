// Loopback static fixture only. Business API calls are mocked in Playwright.
import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const app = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const dist = process.env.UNIFIED_SELECTOR_DIST || path.join(app, "dist");
const origin = "http://127.0.0.1:4190";
const server = http.createServer((request, response) => {
  const url = new URL(request.url, origin);
  if (url.pathname === "/health")
    return response.end("unified-selector-fixture-only");
  if (url.pathname === "/estimate-builder/") {
    const boot = JSON.stringify({
      restUrl: `${origin}/wp-json/tc-estimate/v1`,
      ajaxUrl: `${origin}/wp-admin/admin-ajax.php`,
      nonce: "unified-fixture-nonce",
      brand: { name: "Unified Selector Fixture", primary: "#214c7a" },
    })
      .replaceAll("&", "&amp;")
      .replaceAll('"', "&quot;");
    response.writeHead(200, {
      "Content-Type": "text/html; charset=utf-8",
      "Cache-Control": "no-store",
    });
    return response.end(
      `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/estimate-builder.css"><style>body{margin:0;background:#fff}</style></head><body><div id="tc-estimate-builder-root" data-boot="${boot}"></div><script data-tc-estimate-bundle src="/assets/estimate-builder.js"></script></body></html>`,
    );
  }
  const asset = url.pathname.match(
    /^\/assets\/(estimate-builder\.(?:js|css|js\.map))$/,
  )?.[1];
  if (asset && fs.existsSync(path.join(dist, asset))) {
    response.writeHead(200, {
      "Content-Type": asset.endsWith(".css")
        ? "text/css"
        : "application/javascript",
      "Cache-Control": "no-store",
    });
    return fs.createReadStream(path.join(dist, asset)).pipe(response);
  }
  if (url.pathname === "/favicon.ico") {
    response.writeHead(204);
    return response.end();
  }
  if (
    ["/estimate-builder/assets/logo.jpg", "/assets/logo.jpg"].includes(
      url.pathname,
    )
  ) {
    const logo = path.resolve(app, "../../assets/logo.jpg");
    response.writeHead(200, {
      "Content-Type": "image/jpeg",
      "Cache-Control": "no-store",
    });
    return fs.createReadStream(logo).pipe(response);
  }
  response.writeHead(404, { "Content-Type": "text/plain" });
  response.end("No local fixture for this path; no external proxy exists.");
});
server.listen(4190, "127.0.0.1");
for (const signal of ["SIGTERM", "SIGINT"])
  process.on(signal, () => server.close(() => process.exit(0)));
