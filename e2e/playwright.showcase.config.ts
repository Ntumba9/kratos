import { defineConfig, devices } from '@playwright/test'

/**
 * Captures portfolio screenshots and a walkthrough video from the local
 * Docker Compose stack, using synthetic events only. Not part of the test
 * suite: `npx playwright test -c playwright.showcase.config.ts`.
 */
export default defineConfig({
  testDir: './showcase',
  outputDir: './showcase-results',
  timeout: 600_000,
  expect: { timeout: Number(process.env.KRATOS_E2E_EXPECT_TIMEOUT ?? 60_000) },
  workers: 1,
  reporter: 'list',
  use: {
    ...devices['Desktop Chrome'],
    baseURL: process.env.KRATOS_BASE_URL ?? 'http://localhost:8080',
    viewport: { width: 1440, height: 900 },
    video: { mode: 'on', size: { width: 1440, height: 900 } },
  },
})
