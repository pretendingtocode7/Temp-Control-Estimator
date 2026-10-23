import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/parity',
  timeout: 90_000,
  expect: { timeout: 12_000 },
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    actionTimeout: 15_000,
    baseURL: 'http://127.0.0.1:4178',
    browserName: 'chromium',
    launchOptions: {
      executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop', use: { viewport: { width: 1280, height: 900 } } },
    { name: 'mobile', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
  webServer: {
    command: 'node tests/parity/server.mjs',
    url: 'http://127.0.0.1:4178/health',
    reuseExistingServer: false,
    timeout: 15_000,
  },
});
