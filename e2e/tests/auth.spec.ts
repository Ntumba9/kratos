import { expect, test } from '@playwright/test'
import { apiGet, login, newAccount, register, verify } from './support'

test.describe('authentication', () => {
  test('register → verify email → reach dashboard → sign out → sign in', async ({ page }) => {
    const account = newAccount('owner')

    await register(page, account)
    await expect(page.getByText(`Signed in as ${account.email}`)).toBeVisible()

    // Unverified users cannot reach the console.
    await page.goto('/')
    await expect(page).toHaveURL(/\/verify-email$/)

    await verify(page, account)
    await page.getByRole('button', { name: 'Continue to KRATOS' }).click()

    await expect(page.getByRole('heading', { name: 'Security overview' })).toBeVisible()
    await expect(page.getByRole('main').getByText(account.organisation)).toBeVisible()
    await expect(page.getByText('Organisation Administrator').first()).toBeVisible()
    await expect(page.getByText('organisation.created')).toBeVisible()

    await page.getByRole('button', { name: 'Sign out' }).click()
    await expect(page).toHaveURL(/\/login$/)

    // Session is really gone server-side, not just in the SPA.
    const me = await apiGet(page, '/api/v1/auth/me')
    expect(me.status()).toBe(401)

    await login(page, account)
    await expect(page.getByRole('heading', { name: 'Security overview' })).toBeVisible()
  })

  test('invalid credentials show an error and do not sign in', async ({ page }) => {
    await login(page, { email: 'nobody@kratos.test', password: 'Not-The-Password-1' })

    await expect(page.getByRole('alert')).toContainText('These credentials do not match our records.')
    await expect(page).toHaveURL(/\/login$/)
  })

  test('protected pages redirect anonymous visitors to sign in', async ({ page }) => {
    await page.goto('/admin/members')
    await expect(page).toHaveURL(/\/login$/)
  })
})
