import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
  testDir: './tests/Browser', workers: 1, fullyParallel: false,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: { baseURL: 'http://127.0.0.1:8091', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'node tests/Browser/server.mjs', url: 'http://127.0.0.1:8091/login',
    reuseExistingServer: false, timeout: 120000,
  },
});
