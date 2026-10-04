import { defineConfig, devices } from '@playwright/test'

// End-to-end against a real `sugarcrush serve` on the offline EchoProvider
// (e2e/fixtures/server.ts starts it). Serial on purpose: the suite shares
// one server per worker and its turns run in forked PHP children.
export default defineConfig({
  testDir: 'e2e',
  timeout: 120_000,
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
