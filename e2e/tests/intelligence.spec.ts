import { expect, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { loginToConsole, newAccount, register, verify } from './support'
import { sendSigned } from './signing'

/**
 * Full stack including KRATOS MIND: a brute-force burst is ingested, scored
 * by the real service, and explained in the explorer and intelligence view.
 */
test('a brute-force burst is detected, scored and explained', async ({ page, browser, request }) => {
  const admin = newAccount('mind')
  const context = await browser.newContext()
  const setup = await context.newPage()
  await register(setup, admin)
  await verify(setup, admin)
  await context.close()
  await loginToConsole(page, admin)

  await page.getByRole('link', { name: 'Ingestion Keys' }).click()
  await page.getByLabel('Name', { exact: true }).fill('e2e mind')
  await page.getByRole('button', { name: 'Issue key' }).click()
  await expect(page.getByText('it is shown only once')).toBeVisible()
  const keyId = (await page.locator('code').nth(0).textContent())!.trim()
  const secret = (await page.locator('code').nth(1).textContent())!.trim()

  const run = randomUUID().slice(0, 8)
  for (let i = 1; i <= 12; i++) {
    const response = await sendSigned(request, keyId, secret, {
      event_type: 'authentication_failure',
      source: 'identity_provider',
      severity: 'medium',
      timestamp: new Date(Date.now() - (12 - i) * 10_000).toISOString(),
      idempotency_key: `bf-${run}-${i}`,
      metadata: { source_ip: '203.0.113.77', username: 'finance.lead', failed_attempts: i, synthetic: true },
    })
    expect(response.status(), await response.text()).toBe(202)
  }

  // Explorer: the highest-risk event is the end of the burst, flagged malicious.
  await page.getByRole('link', { name: 'Security Events' }).click()
  await page.getByLabel('Sort', { exact: true }).selectOption('-risk_score')
  const topRow = page.locator('tbody tr').first()
  await expect(topRow).toContainText('malicious', { timeout: 120_000 })

  await topRow.click()
  const drawer = page.getByRole('dialog', { name: 'Event detail' })
  await expect(drawer.getByText(/failed logins from 203\.0\.113\.77 in 15 minutes/).first()).toBeVisible()
  await expect(drawer.getByText('brute_force_source', { exact: true })).toBeVisible()
  await drawer.getByRole('button', { name: 'Close' }).click()

  // Intelligence view: rule hits counted, model metrics labelled as synthetic.
  await page.getByRole('link', { name: 'Threat Intelligence' }).click()
  await expect(page.getByText('Synthetic evaluation.')).toBeVisible()
  await expect(page.getByRole('group', { name: 'Malicious · 24 h' }).getByText(/^[1-9]\d*$/)).toBeVisible()
  await expect(page.getByText('brute_force_source').first()).toBeVisible()
})
