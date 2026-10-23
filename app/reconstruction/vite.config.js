import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { copyFileSync } from "node:fs";

export default defineConfig(({ command }) => ({
  // WordPress loads a standalone classic script, so there is no Node process global.
  define: command === "build" ? { "process.env.NODE_ENV": '"production"' } : {},
  plugins: [
    react(),
    {
      name: "copy-classic-service-worker",
      closeBundle() {
        copyFileSync("src/service-worker.js", "dist/service-worker.js");
      },
    },
  ],
  build: {
    outDir: "dist",
    emptyOutDir: true,
    sourcemap: true,
    target: "es2020",
    cssCodeSplit: false,
    lib: {
      entry: "src/entry.jsx",
      name: "TempControlEstimateReconstruction",
      formats: ["iife"],
      fileName: () => "estimate-builder.js",
      cssFileName: "estimate-builder",
    },
  },
  test: {
    environment: "jsdom",
    include: ["tests/**/*.test.{js,jsx}"],
    restoreMocks: true,
    clearMocks: true,
  },
}));
