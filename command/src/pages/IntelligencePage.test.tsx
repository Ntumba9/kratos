import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { makePrincipal, renderWithProviders } from '../test/render'
import { IntelligencePage } from './IntelligencePage'

const rates = { precision: 0.97, recall: 0.89, f1: 0.93, false_positive_rate: 0.0087 }
const model = {
  name: 'isolation_forest',
  version: 'fs1-s42',
  algorithm: 'IsolationForest',
  artifact_sha256: 'e0c7d6a5a764ea40d13e5ca910d2c6d83ed7be8e740649a61e8004763db2ae16',
  trained_at: '2026-10-23T22:46:09Z',
  training_dataset: { synthetic: true, seed: 42 },
  metrics: {
    test_size: { normal: 10000, attack: 3200 },
    anomaly_model: { roc_auc: 0.92, pr_auc: 0.8, at_threshold: rates, detection_rate_by_scenario: { normal: 0.009, low_and_slow: 0.13 } },
    rules_only: { ...rates, detection_rate_by_scenario: { normal: 0.0, low_and_slow: 0.04 } },
    risk_score: {
      roc_auc: 0.96,
      pr_auc: 0.95,
      suspicious_or_worse: { ...rates, detection_rate_by_scenario: { normal: 0.0087, low_and_slow: 0.6 } },
      malicious: { ...rates, detection_rate_by_scenario: { normal: 0.0, low_and_slow: 0.0 } },
    },
  },
  limitations: ['Trained and evaluated exclusively on synthetic data.'],
  synthetic_evaluation: true,
  last_seen_at: null,
}
const rule = {
  key: 'brute_force_source',
  name: 'Brute force from a single source',
  description: 'Many failed logins from one IP.',
  category: 'credential_attack',
  severity: 'high',
  enabled: true,
  parameters: { failures_15m: 10 },
  defaults: { failures_15m: 10 },
  bounds: { failures_15m: { min: 3, max: 1000 } },
  customised: false,
}
const stats = {
  generated_at: '2026-10-24T10:00:00Z',
  analysed_last_24h: 161,
  awaiting_analysis: 0,
  verdicts: { benign: 53, suspicious: 43, malicious: 65 },
  categories: [{ category: 'credential_attack', count: 39 }],
  risk_histogram: Array.from({ length: 10 }, (_, i) => ({ from: i * 10, to: i * 10 + 10, count: i })),
  rule_hits: [{ rule_key: 'brute_force_source', count: 32, max_score: 90 }],
  anomaly_trend: [{ hour: '2026-10-24T09:00:00Z', anomalous: 2, flagged: 5, analysed: 20 }],
}

function mockApi() {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = String(input)
    if (url === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=t'
      return new Response(null, { status: 204 })
    }
    if (url.includes('/detection-rules/') && init?.method === 'PATCH') return new Response(JSON.stringify({ data: { ...rule, enabled: false } }))
    if (url.includes('/detection-rules')) return new Response(JSON.stringify({ data: [rule] }))
    if (url.includes('/stats/intelligence')) return new Response(JSON.stringify({ data: stats }))
    if (url.includes('/detections')) return new Response(JSON.stringify({ data: [], meta: { pagination: { page: 1, per_page: 8, total: 0, last_page: 1 } } }))
    if (url.includes('/intelligence/model')) return new Response(JSON.stringify({ data: model }))
    return new Response(JSON.stringify({ error: { code: 'not_found', message: 'nf' } }), { status: 404 })
  })
}

describe('IntelligencePage', () => {
  it('labels model metrics as synthetic and shows them with limitations', async () => {
    mockApi()
    renderWithProviders(<IntelligencePage />, { principal: makePrincipal({}, ['detections.view']), route: '/intelligence', path: '/intelligence' })

    expect(await screen.findByText(/Synthetic evaluation\./)).toBeInTheDocument()
    expect(screen.getByText(/not/, { selector: 'strong' })).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Malicious · 24 h' })).toHaveTextContent('65')
    const metrics = screen.getByRole('table', { name: /synthetic test set/i })
    expect(within(metrics).getByText('Risk ≥ 40 (suspicious+)').closest('tr')).toHaveTextContent('0.97')
    expect(screen.getByText('Trained and evaluated exclusively on synthetic data.')).toBeInTheDocument()
  })

  it('lets managers toggle rules but not viewers', async () => {
    const fetchMock = mockApi()
    renderWithProviders(<IntelligencePage />, { principal: makePrincipal({}, ['detections.view', 'detections.manage']), route: '/intelligence', path: '/intelligence' })

    await userEvent.click(await screen.findByRole('switch', { name: /disable brute force/i }))

    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')!
    expect(String(patch[0])).toContain('/detection-rules/brute_force_source')
    expect(JSON.parse(String(patch[1]?.body))).toEqual({ enabled: false })
  })

  it('shows rules read-only to viewers', async () => {
    mockApi()
    renderWithProviders(<IntelligencePage />, { principal: makePrincipal({}, ['detections.view']), route: '/intelligence', path: '/intelligence' })

    expect(await screen.findByText('Brute force from a single source')).toBeInTheDocument()
    expect(screen.queryByRole('switch')).not.toBeInTheDocument()
    expect(screen.getByLabelText(/Brute force from a single source: failures_15m/)).toBeDisabled()
  })
})
