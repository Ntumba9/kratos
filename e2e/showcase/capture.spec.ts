import { expect, test, type Page } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { loginToConsole, newAccount, register, verify } from '../tests/support'
import { sendSigned } from '../tests/signing'

const shots = '../docs/screenshots'
const snap = async (page: Page, name: string) => {
  await page.mouse.move(1430, 890) // no hover highlight in the capture
  await page.screenshot({ path: `${shots}/${name}.png` })
}

/**
 * Real flow on the local stack: sign up and verify (Mailpit), issue an
 * ingestion key, send signed synthetic events, let KRATOS MIND score them,
 * then capture the console. Documentation IP ranges (RFC 5737) only.
 */
test('capture the KRATOS walkthrough', async ({ page, browser, request }) => {
  const admin = newAccount('showcase')
  const setup = await browser.newContext()
  const setupPage = await setup.newPage()
  await register(setupPage, admin)
  await verify(setupPage, admin)
  await setup.close()
  await loginToConsole(page, admin)

  await page.getByRole('link', { name: 'Ingestion Keys' }).click()
  await page.getByLabel('Name', { exact: true }).fill('showcase sensor')
  await page.getByRole('button', { name: 'Issue key' }).click()
  await expect(page.getByText('it is shown only once')).toBeVisible()
  const keyId = (await page.locator('code').nth(0).textContent())!.trim()
  const secret = (await page.locator('code').nth(1).textContent())!.trim()

  const run = randomUUID().slice(0, 8)
  const ago = (s: number) => new Date(Date.now() - s * 1000).toISOString()
  const ip = (n: number) => `198.51.100.${(n % 200) + 10}`
  const events: Record<string, unknown>[] = []
  for (let i = 0; i < 30; i++) {
    events.push(i % 3 === 0
      ? { event_type: 'authentication_failure', source: 'identity_provider', severity: 'low', timestamp: ago(1800 - i * 50), metadata: { source_ip: ip(i), username: `user${i % 9}`, failed_attempts: 1 } }
      : { event_type: 'authentication_success', source: 'identity_provider', severity: 'info', timestamp: ago(1800 - i * 50), metadata: { source_ip: ip(i), username: `user${i % 9}` } })
  }
  events.push({ event_type: 'firewall_block', source: 'edge_firewall', severity: 'low', timestamp: ago(600), metadata: { source_ip: '192.0.2.44', destination_port: 3389, protocol: 'tcp' } })
  events.push({ event_type: 'permission_denied', source: 'billing_service', severity: 'medium', timestamp: ago(500), metadata: { source_ip: '192.0.2.45', username: 'user3', resource: '/admin/invoices', action: 'export' } })
  for (let i = 1; i <= 14; i++) {
    events.push({ event_type: 'authentication_failure', source: 'identity_provider', severity: 'medium', timestamp: ago(140 - i * 10), metadata: { source_ip: '203.0.113.77', username: 'finance.lead', failed_attempts: i } })
  }
  for (const [i, event] of events.entries()) {
    const response = await sendSigned(request, keyId, secret, { ...event, idempotency_key: `sc-${run}-${i}`, metadata: { ...(event.metadata as object), synthetic: true } })
    expect(response.status(), await response.text()).toBe(202)
  }

  await page.getByRole('link', { name: 'Security Events' }).click()
  await page.getByLabel('Sort', { exact: true }).selectOption('-risk_score')
  await expect(page.locator('tbody tr').first()).toContainText('malicious', { timeout: 180_000 })
  await snap(page, 'events-by-risk')

  await page.locator('tbody tr').first().click()
  const drawer = page.getByRole('dialog', { name: 'Event detail' })
  await expect(drawer.getByText('brute_force_source', { exact: true })).toBeVisible()
  await snap(page, 'event-explanation')
  await drawer.getByRole('button', { name: 'Close' }).click()

  await page.getByRole('link', { name: 'Threat Intelligence' }).click()
  await expect(page.getByText('Synthetic evaluation.')).toBeVisible()
  await snap(page, 'threat-intelligence')
  await page.getByText('Synthetic evaluation.').scrollIntoViewIfNeeded()
  await snap(page, 'model-card')

  await page.getByRole('link', { name: 'Overview' }).click()
  await page.reload() // drop the empty stats cached at login; start at the top
  await expect(page.getByRole('heading', { name: 'Security overview' })).toBeVisible()
  await expect(page.getByText('No events in the last 24 hours')).toHaveCount(0)
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(1500) // chart transitions
  await snap(page, 'overview')
})
