import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { AuthProvider, ME_QUERY_KEY } from '../lib/auth'
import { OrganisationProvider } from '../lib/organisation'
import type { CurrentUser } from '../lib/types'

export function makePrincipal(overrides: Partial<CurrentUser> = {}, permissions: string[] = ['organisation.view', 'members.view']): CurrentUser {
  return {
    user: {
      id: 'user-1',
      name: 'Test Analyst',
      email: 'analyst@example.test',
      email_verified: true,
      email_verified_at: '2026-10-23T12:00:00Z',
      last_login_at: null,
      created_at: '2026-10-23T12:00:00Z',
    },
    platform_role: null,
    memberships: [
      {
        id: 'membership-1',
        organisation: { id: 'org-1', name: 'Northwind', slug: 'northwind' },
        role: { slug: 'analyst', name: 'Security Analyst' },
        permissions,
      },
    ],
    ...overrides,
  }
}

/**
 * Render inside the real providers with a pre-seeded principal (or null for
 * a signed-out visitor), so no /auth/me request is needed.
 */
export function renderWithProviders(ui: ReactElement, { principal = null as CurrentUser | null, route = '/', path = '*' } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  queryClient.setQueryData(ME_QUERY_KEY, principal)

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <AuthProvider>
          <OrganisationProvider>
            <Routes>
              <Route path={path} element={ui} />
              <Route path="/verify-email" element={<div>verify-email-page</div>} />
              <Route path="/" element={path === '/' ? ui : <div>overview-page</div>} />
            </Routes>
          </OrganisationProvider>
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
