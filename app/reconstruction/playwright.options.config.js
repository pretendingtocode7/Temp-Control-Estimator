import { defineConfig } from "@playwright/test";
import path from "node:path";
import { fileURLToPath } from "node:url";
const app = path.dirname(fileURLToPath(import.meta.url));
export default defineConfig({
  testDir: "./tests/proposal-options",
  outputDir: path.resolve(
    app,
    "../../../options-tiers/browser-validation-final-public/test-results",
  ),
  timeout: 120_000,
  expect: { timeout: 12_000 },
  fullyParallel: false,
  workers: 1,
  reporter: [["list"]],
  use: {
    baseURL: "http://127.0.0.1:4191",
    browserName: "chromium",
    headless: true,
    launchOptions: {
      executablePath:
        process.env.CHROME_PATH ||
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    },
    actionTimeout: 15_000,
    deviceScaleFactor: 1,
    reducedMotion: "reduce",
    trace: "retain-on-failure",
  },
  projects: [
    { name: "desktop", use: { viewport: { width: 1280, height: 900 } } },
    {
      name: "mobile",
      use: {
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
      },
    },
  ],
  webServer: {
    command: "node tests/proposal-options/server.mjs",
    url: "http://127.0.0.1:4191/health",
    reuseExistingServer: false,
    timeout: 15_000,
  },
});
