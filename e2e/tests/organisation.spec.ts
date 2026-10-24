import { expect, test, type Browser } from '@playwright/test'
import { apiGet, loginToConsole, newAccount, register, verify, type TestAccount } from './support'

async function onboard(browser: Browser, label: string): Promise<TestAccount> {
  const context = await browser.newContext()
  const page = await context.newPage()
  const account = newAccount(label)
  await register(page, account)
  await verify(page, account)
  await context.close()
  return account
}

test.describe('organisation administration', () => {
  test('admin adds an analyst; the analyst gets a restricted console; changes are audited', async ({ browser, page }) => {
    const admin = await onboard(browser, 'admin')
    const analyst = await onboard(browser, 'analyst')

    await loginToConsole(page, admin)
    await page.getByRole('link', { name: 'Members' }).click()
    await page.getByLabel('Email of a registered user').fill(analyst.email)
    await page.getByLabel('Role', { exact: true }).selectOption('analyst')
    await page.getByRole('button', { name: 'Add' }).click()
    await expect(page.getByText('Member added.')).toBeVisible()
    await expect(page.getByText(analyst.email)).toBeVisible()

    await page.getByRole('link', { name: 'Audit Log' }).click()
    await page.getByRole('button', { name: 'membership.' }).click()
    await expect(page.getByText('membership.created').first()).toBeVisible()

    // The analyst sees the admin's organisation with analyst-level navigation.
    const analystContext = await browser.newContext()
    const analystPage = await analystContext.newPage()
    await loginToConsole(analystPage, analyst)
    await analystPage.getByLabel('Organisation').selectOption({ label: admin.organisation })
    await expect(analystPage.getByText('Security Analyst').first()).toBeVisible()
    const nav = analystPage.getByRole('navigation', { name: 'Primary' })
    await expect(nav.getByRole('link', { name: 'Members' })).toBeVisible()
    await expect(nav.getByRole('link', { name: 'Audit Log' })).toHaveCount(0)

    // Server-side enforcement, independent of the UI.
    const me = await (await apiGet(analystPage, '/api/v1/auth/me')).json()
    const adminOrg = me.data.memberships.find((m: { organisation: { name: string } }) => m.organisation.name === admin.organisation)
    const audit = await apiGet(analystPage, `/api/v1/organisations/${adminOrg.organisation.id}/audit-logs`)
    expect(audit.status()).toBe(403)

    await analystContext.close()
  })

  test('a user cannot read another organisation by ID', async ({ browser, page }) => {
    const victim = await onboard(browser, 'victim')
    const attacker = await onboard(browser, 'attacker')

    const victimContext = await browser.newContext()
    const victimPage = await victimContext.newPage()
    await loginToConsole(victimPage, victim)
    const victimMe = await (await apiGet(victimPage, '/api/v1/auth/me')).json()
    const victimOrgId: string = victimMe.data.memberships[0].organisation.id
    await victimContext.close()

    await loginToConsole(page, attacker)

    for (const path of ['', '/members', '/audit-logs']) {
      const response = await apiGet(page, `/api/v1/organisations/${victimOrgId}${path}`)
      expect(response.status(), `GET /organisations/{victim}${path}`).toBe(404)
    }
  })
})
