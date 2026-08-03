import { defineConfig } from 'vite';
import { resolve } from 'node:path';

/**
 * Service worker build. Separate from the main bundle because:
 *   1. Browsers require service workers to have a stable, top-level URL.
 *   2. SW code runs in a different global scope (self, not window).
 *
 * Output: dist/service-worker.js
 *
 * Run via: `npm run build:sw` (chained after the main build in `npm run build`).
 */
export default defineConfig({
  define: {
    __APP_VERSION__: JSON.stringify(process.env.npm_package_version || '0.2.38'),
  },
  build: {
    outDir: 'dist',
    emptyOutDir: false, // do NOT wipe the main bundle
    sourcemap: false,
    minify: 'esbuild',
    rollupOptions: {
      input: resolve(__dirname, 'src/sw/service-worker.js'),
      output: {
        entryFileNames: 'service-worker.js',
        format: 'iife',
      },
    },
  },
});
