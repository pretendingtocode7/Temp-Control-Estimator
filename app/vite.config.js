import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { viteSingleFile } from 'vite-plugin-singlefile';
import { resolve } from 'node:path';

/**
 * Vite config — produces two files:
 *   dist/estimate-builder.js   (JS bundle, React + app code)
 *   dist/estimate-builder.css  (CSS bundle)
 *
 * Why two files and not one HTML blob:
 *   The WordPress enqueue layer (public/class-enqueue.php) loads these
 *   as a standard script+style pair. Inlining into HTML would bypass
 *   WP's script-loader pipeline, break dependency resolution for other
 *   plugins, and break the Content-Security-Policy headers that WP
 *   Engine's security ruleset applies.
 *
 * The service worker is built separately below so it has a stable
 * top-level URL (service workers cannot be served inside a JS bundle).
 */
export default defineConfig(({ mode }) => ({
  plugins: [react()],

  // Serve from plugin-relative path during dev so absolute asset
  // references resolve correctly when dev-injected into WP.
  base: './',

  define: {
    __APP_VERSION__: JSON.stringify(process.env.npm_package_version || '0.2.0'),
    __BUILD_TIME__: JSON.stringify(new Date().toISOString()),
  },

  build: {
    outDir: 'dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: mode === 'development',
    minify: mode === 'production' ? 'esbuild' : false,

    rollupOptions: {
      input: {
        'estimate-builder': resolve(__dirname, 'src/main.jsx'),
      },
      output: {
        // Fixed filenames so the WP enqueuer doesn't need a manifest lookup.
        entryFileNames: '[name].js',
        chunkFileNames: '[name].js',
        assetFileNames: (assetInfo) => {
          // Single CSS output: estimate-builder.css
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'estimate-builder.css';
          }
          return '[name][extname]';
        },
        // One chunk — smaller than a split bundle for a ~50KB app
        // and avoids cross-origin fetch edge cases inside shortcodes.
        manualChunks: undefined,
        inlineDynamicImports: true,
      },
    },
  },

  // Service worker build runs as a separate invocation (see scripts).
}));
