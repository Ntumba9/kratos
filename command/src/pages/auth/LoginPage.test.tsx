import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makePrincipal, renderWithProviders } from '../../test/render'
import { LoginPage } from './LoginPage'

function mockApi(loginResponse: Response) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    if (url === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=t'
      return new Response(null, { status: 204 })
    }
    if (url === '/api/v1/auth/login') return loginResponse
    return new Response(JSON.stringify({ error: { code: 'not_found', message: 'nf' } }), { status: 404 })
  })
}

describe('LoginPage', () => {
  it('signs in and navigates to the overview', async () => {
    const fetchMock = mockApi(new Response(JSON.stringify({ data: makePrincipal() }), { status: 200 }))
    renderWithProviders(<LoginPage />, { route: '/login', path: '/login' })

    await userEvent.type(screen.getByLabelText(/email/i), 'analyst@example.test')
    await userEvent.type(screen.getByLabelText(/^password/i), 'Correct-Horse-42')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText('overview-page')).toBeInTheDocument()
    const loginCall = fetchMock.mock.calls.find(([url]) => String(url) === '/api/v1/auth/login')!
    expect(JSON.parse(String(loginCall[1]?.body))).toEqual({ email: 'analyst@example.test', password: 'Correct-Horse-42', remember: false })
  })

  it('shows the API error message for invalid credentials', async () => {
    mockApi(new Response(JSON.stringify({ error: { code: 'invalid_credentials', message: 'These credentials do not match our records.' } }), { status: 401 }))
    renderWithProviders(<LoginPage />, { route: '/login', path: '/login' })

    await userEvent.type(screen.getByLabelText(/email/i), 'analyst@example.test')
    await userEvent.type(screen.getByLabelText(/^password/i), 'wrong')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent('These credentials do not match our records.')
  })

  it('routes unverified users to the verification page', async () => {
    const unverified = makePrincipal()
    unverified.user.email_verified = false
    mockApi(new Response(JSON.stringify({ data: unverified }), { status: 200 }))
    renderWithProviders(<LoginPage />, { route: '/login', path: '/login' })

    await userEvent.type(screen.getByLabelText(/email/i), 'analyst@example.test')
    await userEvent.type(screen.getByLabelText(/^password/i), 'Correct-Horse-42')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    await waitFor(() => expect(screen.getByText('verify-email-page')).toBeInTheDocument())
  })
})
