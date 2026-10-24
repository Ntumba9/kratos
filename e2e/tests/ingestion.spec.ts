import { expect, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { sendSigned } from './signing'
import { loginToConsole, newAccount, register, verify } from './support'

test('issue a key, register an asset, ingest signed events, and see them on the dashboard', async ({ page, browser, request }) => {
  const admin = newAccount('eye')
  const context = await browser.newContext()
  const setup = await context.newPage()
  await register(setup, admin)
  await verify(setup, admin)
  await context.close()

  await loginToConsole(page, admin)

  // 1. Issue an ingestion key; the secret is shown once.
  await page.getByRole('link', { name: 'Ingestion Keys' }).click()
  await page.getByLabel('Name', { exact: true }).fill('e2e sender')
  await page.getByRole('button', { name: 'Issue key' }).click()
  await expect(page.getByText('it is shown only once')).toBeVisible()
  const codes = page.locator('code')
  const keyId = (await codes.nth(0).textContent())!.trim()
  const secret = (await codes.nth(1).textContent())!.trim()
  expect(keyId).toMatch(/^krk_/)
  expect(secret).toMatch(/^krs_/)

  // 2. Register an asset.
  await page.getByRole('link', { name: 'Infrastructure' }).click()
  await page.getByRole('button', { name: 'Register asset' }).first().click()
  await page.getByLabel('Name', { exact: true }).fill('e2e-gateway')
  await page.getByLabel('Criticality', { exact: true }).selectOption('critical')
  await page.getByRole('button', { name: 'Register asset' }).last().click()
  await expect(page.getByText('e2e-gateway')).toBeVisible()

  // 3. Send signed events as an external client would.
  const now = new Date().toISOString()
  const run = randomUUID().slice(0, 8)
  for (const [i, severity] of (['critical', 'high', 'low'] as const).entries()) {
    const response = await sendSigned(request, keyId, secret, {
      event_type: 'authentication_failure',
      source: 'e2e_gateway',
      severity,
      timestamp: now,
      asset: 'e2e-gateway',
      idempotency_key: `e2e-${run}-${i}`,
      metadata: { failed_attempts: i + 1, source_ip: '203.0.113.50', synthetic: true },
    })
    expect(response.status(), await response.text()).toBe(202)
  }

  // A replay with the same idempotency key is recognised as a duplicate.
  const duplicate = await sendSigned(request, keyId, secret, {
    event_type: 'authentication_failure', source: 'e2e_gateway', severity: 'critical', timestamp: now,
    asset: 'e2e-gateway', idempotency_key: `e2e-${run}-0`, metadata: { failed_attempts: 1, source_ip: '203.0.113.50', synthetic: true },
  })
  expect(duplicate.status()).toBe(200)

  // A wrong signature is rejected.
  const forged = await sendSigned(request, keyId, 'krs_wrong', { event_type: 'authentication_failure', source: 'x', severity: 'low', timestamp: now })
  expect(forged.status()).toBe(401)

  // 4. Events are visible in the explorer, processed by the worker.
  await page.getByRole('link', { name: 'Security Events' }).click()
  await page.getByRole('button', { name: 'critical' }).click()
  await expect(page.getByRole('cell', { name: 'e2e-gateway' })).toHaveCount(1)
  await expect(page.getByRole('row', { name: /e2e-gateway/ }).getByText('Processed', { exact: true })).toBeVisible({ timeout: 60_000 })

  // 5. The overview counts them (3, not 4: the duplicate was not stored).
  await page.getByRole('link', { name: 'Overview' }).click()
  await expect(page.getByRole('group', { name: 'Events · 24 h' }).getByText('3', { exact: true })).toBeVisible()
  await expect(page.getByRole('group', { name: 'High / critical · 24 h' }).getByText('2', { exact: true })).toBeVisible()
})
