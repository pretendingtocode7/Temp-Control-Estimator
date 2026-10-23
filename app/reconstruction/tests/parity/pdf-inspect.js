import { inflateSync } from 'node:zlib';

// Test-only inspection of the direct-length streams produced by jsPDF here.
// This is deliberately not a parser for arbitrary or untrusted PDF documents.
export function inspectFixturePdf(pdf) {
  const text = pdf.toString('latin1');
  const images = [], placements = [];
  for (const match of text.matchAll(/\d+\s+0\s+obj\b/g)) {
    const start = match.index + match[0].length;
    const end = text.indexOf('endobj', start);
    const stream = text.indexOf('stream\n', start);
    if (stream < 0 || stream > end) continue;
    const header = text.slice(start, stream);
    const length = header.match(/\/Length\s+(\d+)\b/);
    if (!length) continue;
    const dataStart = stream + 'stream\n'.length;
    const bytes = pdf.subarray(dataStart, dataStart + Number(length[1]));
    if (/\/Subtype\s*\/Image\b/.test(header) && /\/DCTDecode\b/.test(header)) {
      images.push({ width: Number(header.match(/\/Width\s+(\d+)/)?.[1]), height: Number(header.match(/\/Height\s+(\d+)/)?.[1]), bytes });
    } else if (/\/FlateDecode\b/.test(header)) {
      const content = inflateSync(bytes).toString('latin1');
      for (const transform of content.matchAll(/([-\d.]+)\s+0\s+0\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+cm\s*\/(I\d+)\s+Do/g)) {
        placements.push({ width: Number(transform[1]), height: Number(transform[2]), x: Number(transform[3]), y: Number(transform[4]), image: transform[5] });
      }
    }
  }
  return { images, placements };
}
