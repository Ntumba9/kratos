import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makePrincipal, renderWithProviders } from '../test/render'
import { EventsPage } from './EventsPage'

const event = {
  id: 'evt-1',
  event_type: 'authentication_failure',
  source: 'identity_provider',
  severity: 'high',
  occurred_at: '2026-10-23T12:00:00Z',
  received_at: '2026-10-23T12:00:01Z',
  source_ip: '192.0.2.10',
  asset: null,
  processing_status: 'dead_lettered',
  risk_score: null,
  metadata: { failed_attempts: 12 },
}

function mockApi() {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    if (url.includes('/events/evt-1')) {
      return new Response(JSON.stringify({ data: { ...event, processing: { attempts: 5, last_error: 'boom', started_at: null, processed_at: null } } }))
    }
    if (url.includes('/events')) {
      return new Response(JSON.stringify({ data: [event], meta: { pagination: { page: 1, per_page: 25, total: 1, last_page: 1 } } }))
    }
    return new Response(JSON.stringify({ error: { code: 'not_found', message: 'nf' } }), { status: 404 })
  })
}

describe('EventsPage', () => {
  it('sends URL filters to the API and renders results', async () => {
    const fetchMock = mockApi()
    renderWithProviders(<EventsPage />, {
      principal: makePrincipal({}, ['events.view']),
      route: '/events?severity=high,critical&status=dead_lettered',
      path: '/events',
    })

    expect(await screen.findByText('authentication_failure')).toBeInTheDocument()
    const url = decodeURIComponent(String(fetchMock.mock.calls.find(([u]) => String(u).includes('/events'))![0]))
    expect(url).toContain('/organisations/org-1/events?')
    expect(url).toContain('filter[severity]=high,critical')
    expect(url).toContain('filter[status]=dead_lettered')
    expect(screen.getByRole('button', { name: 'high' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('opens the detail drawer and offers replay only to roles that may replay', async () => {
    mockApi()
    renderWithProviders(<EventsPage />, { principal: makePrincipal({}, ['events.view']), route: '/events', path: '/events' })

    await userEvent.click(await screen.findByText('authentication_failure'))

    expect(await screen.findByText('boom')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /replay event/i })).not.toBeInTheDocument()
  })

  it('shows replay for analysts on dead-lettered events', async () => {
    mockApi()
    renderWithProviders(<EventsPage />, { principal: makePrincipal({}, ['events.view', 'events.manage']), route: '/events', path: '/events' })

    await userEvent.click(await screen.findByText('authentication_failure'))

    expect(await screen.findByRole('button', { name: /replay event/i })).toBeInTheDocument()
  })
})
