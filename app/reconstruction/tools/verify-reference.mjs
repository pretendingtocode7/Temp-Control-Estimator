import fs from "node:fs";
import path from "node:path";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
const repository = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../../..",
);
const manifest = JSON.parse(
  fs.readFileSync(
    path.join(repository, "docs/rebuild/reference-manifest.json"),
  ),
);
for (const expected of [manifest.archive, ...manifest.files]) {
  const bytes = fs.readFileSync(path.join(repository, expected.path));
  const actual = createHash("sha256").update(bytes).digest("hex");
  if (bytes.length !== expected.size_bytes || actual !== expected.sha256) {
    throw new Error(`Saved v0.4.8 reference changed: ${expected.path}`);
  }
}
console.log(
  `Verified exact saved installer and all ${manifest.files.length} runtime files; candidate output remains separate.`,
);
