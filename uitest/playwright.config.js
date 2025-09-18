const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const videoMode = process.env.UI_VIDEO_MODE || 'retain-on-failure';

/**
 * Playwright project configuration.
 * Set UI_BASE_URL to point at the UI under test.
 */
module.exports = defineConfig({
  testDir: './tests',
  timeout: 45_000,
  expect: {
    timeout: 10_000,
  },
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.UI_BASE_URL || 'http://localhost:3000',
    trace: 'on-first-retry',
    video: videoMode,
    screenshot: 'only-on-failure',
  },
  webServer: process.env.UI_SKIP_WEB_SERVER
    ? undefined
    : {
        command: 'php -S 127.0.0.1:8000 -t examples',
        cwd: repoRoot,
        port: 8000,
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
      },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
