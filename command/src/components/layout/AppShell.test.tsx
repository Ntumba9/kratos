import { screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { makePrincipal, renderWithProviders } from '../../test/render'
import { AppShell } from './AppShell'

describe('AppShell navigation', () => {
  it('hides administration links the active role cannot use', () => {
    renderWithProviders(<AppShell />, { principal: makePrincipal({}, ['organisation.view', 'members.view', 'events.view']), route: '/', path: '/' })

    const nav = within(screen.getByRole('navigation', { name: 'Primary' }))
    expect(nav.getByRole('link', { name: /members/i })).toBeInTheDocument()
    expect(nav.getByRole('link', { name: /security events/i })).toBeInTheDocument()
    expect(nav.queryByRole('link', { name: /audit log/i })).not.toBeInTheDocument()
    expect(nav.queryByRole('link', { name: /incidents/i })).not.toBeInTheDocument()
  })

  it('shows audit log access to organisation administrators', () => {
    const principal = makePrincipal({}, ['organisation.view', 'members.view', 'audit_logs.view'])
    principal.memberships[0]!.role = { slug: 'org_admin', name: 'Organisation Administrator' }
    renderWithProviders(<AppShell />, { principal, route: '/', path: '/' })

    expect(within(screen.getByRole('navigation', { name: 'Primary' })).getByRole('link', { name: /audit log/i })).toBeInTheDocument()
    expect(screen.getByText('Organisation Administrator')).toBeInTheDocument()
  })

  it('labels modules that are scheduled for later phases', () => {
    renderWithProviders(<AppShell />, { principal: makePrincipal({}, ['incidents.view']), route: '/', path: '/' })

    expect(within(screen.getByRole('link', { name: /incidents/i })).getByText('P4')).toBeInTheDocument()
  })
})
