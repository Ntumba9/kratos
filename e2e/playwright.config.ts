import { defineConfig, devices } from '@playwright/test'

/**
 * Runs against the full Docker Compose stack (Nginx on :8080, Mailpit on
 * :8025). Start it first with `docker compose up -d`.
 */
export default defineConfig({
  testDir: './tests',
  // Generous: multi-user flows include email delivery via the queue worker.
  timeout: 240_000,
  // Override on slow hosts (e.g. Docker Desktop bind mounts from a synced
  // Windows folder): KRATOS_E2E_EXPECT_TIMEOUT=60000 npx playwright test
  expect: { timeout: Number(process.env.KRATOS_E2E_EXPECT_TIMEOUT ?? 20_000) },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.KRATOS_BASE_URL ?? 'http://localhost:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
