import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { SeverityDistribution, SeverityTimeline, TopEventTypes } from './charts'

describe('severity charts', () => {
  it('labels every severity with text and its count (never colour alone)', () => {
    render(<SeverityDistribution counts={{ info: 4, low: 3, medium: 2, high: 1, critical: 7 }} />)

    const list = screen.getByRole('list', { name: /by severity/i })
    const items = within(list).getAllByRole('listitem')
    expect(items.map((li) => li.textContent)).toEqual(['critical7', 'high1', 'medium2', 'low3', 'info4'])
  })

  it('offers a table view of the timeline with the same numbers', async () => {
    const data = [
      { hour: '2026-10-23T10:00:00Z', info: 1, low: 0, medium: 2, high: 0, critical: 3 },
      { hour: '2026-10-23T11:00:00Z', info: 0, low: 5, medium: 0, high: 1, critical: 0 },
    ]
    render(<SeverityTimeline data={data} />)

    await userEvent.click(screen.getByRole('button', { name: 'Show table' }))

    const rows = screen.getAllByRole('row')
    expect(rows).toHaveLength(3) // header + 2 hours
    expect(within(rows[2]!).getAllByRole('cell').map((c) => c.textContent).slice(1)).toEqual(['0', '5', '0', '1', '0'])
  })

  it('lists top event types with counts', () => {
    render(<TopEventTypes data={[{ event_type: 'authentication_failure', count: 42 }]} />)

    expect(screen.getByText('authentication_failure')).toBeInTheDocument()
    expect(screen.getByText('42')).toBeInTheDocument()
  })
})
