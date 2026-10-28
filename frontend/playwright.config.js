import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  workers: 1,
  timeout: 30000,
  expect: { timeout: 15000 },
  use: {
    baseURL: 'http://127.0.0.1:5173',
    channel: 'msedge',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop-edge', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'mobile-edge', use: { ...devices['Pixel 5'], channel: 'msedge' } },
  ],
  webServer: {
    command: 'npm.cmd run dev -- --host 127.0.0.1',
    url: 'http://127.0.0.1:5173',
    reuseExistingServer: true,
    timeout: 120000,
  },
})
