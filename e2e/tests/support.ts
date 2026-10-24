import { expect, type APIRequestContext, type Page } from '@playwright/test'
import { randomBytes } from 'node:crypto'

const MAILPIT = process.env.KRATOS_MAILPIT_URL ?? 'http://localhost:8025'

export interface TestAccount {
  name: string
  email: string
  password: string
  organisation: string
}

/** Unique, throwaway credentials generated per test run (never reused). */
export function newAccount(label: string): TestAccount {
  const id = randomBytes(4).toString('hex')
  return {
    name: `E2E ${label} ${id}`,
    email: `e2e-${label}-${id}@kratos.test`,
    password: `E2e-${randomBytes(9).toString('base64url')}9a`,
    organisation: `E2E Org ${id}`,
  }
}

/** Polls Mailpit for the newest message to `email` and returns the first link matching `pattern`. */
export async function linkFromMail(request: APIRequestContext, email: string, pattern: RegExp): Promise<string> {
  let link: string | undefined

  await expect
    .poll(
      async () => {
        const search = await request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`)
        const { messages } = (await search.json()) as { messages: Array<{ ID: string }> }
        if (!messages.length) return false
        const message = await request.get(`${MAILPIT}/api/v1/message/${messages[0]!.ID}`)
        const { Text } = (await message.json()) as { Text: string }
        link = Text.match(pattern)?.[0]
        return Boolean(link)
      },
      { timeout: 60_000, intervals: [1_000, 2_000, 3_000] },
    )
    .toBe(true)

  return link!
}

export async function register(page: Page, account: TestAccount): Promise<void> {
  await page.goto('/register')
  await page.getByLabel('Full name').fill(account.name)
  await page.getByLabel('Work email').fill(account.email)
  await page.getByLabel('Organisation').fill(account.organisation)
  await page.getByLabel('Password', { exact: true }).fill(account.password)
  await page.getByLabel('Confirm password').fill(account.password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/verify-email$/)
}

export async function verify(page: Page, account: TestAccount): Promise<void> {
  const link = await linkFromMail(page.request, account.email, /http:\/\/localhost:8080\/api\/v1\/auth\/email\/verify\/[^\s\]>)]+/)
  await page.goto(link)
  await expect(page).toHaveURL(/\/verify-email\?status=verified/)
}

export async function login(page: Page, account: Pick<TestAccount, 'email' | 'password'>): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('Email').fill(account.email)
  await page.getByLabel('Password').fill(account.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

/** Sign in and wait until the console has loaded (session established). */
export async function loginToConsole(page: Page, account: Pick<TestAccount, 'email' | 'password'>): Promise<void> {
  await login(page, account)
  await expect(page.getByRole('heading', { name: 'Security overview' })).toBeVisible()
}

/**
 * Call the API from a browser context the way the SPA does. Sanctum only
 * attaches the session to requests that identify the first-party origin, so
 * the Referer header is required for cookie-authenticated calls.
 */
export async function apiGet(page: Page, path: string) {
  const base = process.env.KRATOS_BASE_URL ?? 'http://localhost:8080'
  return page.request.get(path, { headers: { Accept: 'application/json', Referer: `${base}/` } })
}
