import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, BrainCircuit, FlaskConical, RotateCcw, ScanSearch, ShieldAlert, Siren, Timer } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { RiskBadge } from '../components/eye/badges'
import { AnomalyTrendChart, RiskHistogram, TopEventTypes } from '../components/eye/charts'
import { Alert, Badge, Button, Card, cx, EmptyState, Mono, PageHeader, Spinner } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { formatDateTime, formatRelative } from '../lib/format'
import { useOrganisation } from '../lib/organisation'
import { fetchDetectionRules, fetchDetections, fetchIntelligenceStats, fetchModelCard, phase3Keys } from '../lib/queries'
import type { DetectionRule, ModelCard } from '../lib/types'

function Tile({ label, value, icon, tone }: { label: string; value: ReactNode; icon: ReactNode; tone?: 'alert' | 'warn' }) {
  return (
    <div role="group" aria-label={label} className="rounded-lg border border-graphite-700 bg-graphite-800/80 p-4">
      <div className="flex items-center justify-between text-ink-500">
        <span className="text-[11px] font-medium tracking-[0.14em] uppercase">{label}</span>
        <span className="text-ink-600">{icon}</span>
      </div>
      <div className={cx('mt-3 text-2xl font-semibold tabular-nums', tone === 'alert' ? 'text-crimson-300' : tone === 'warn' ? 'text-signal-warn' : 'text-ink-100')}>{value}</div>
    </div>
  )
}

const pct = (v: number | undefined) => (v === undefined ? '—' : `${(v * 100).toFixed(1)}%`)
const num = (v: number | undefined) => (v === undefined ? '—' : v.toFixed(3))

function ModelPanel({ model }: { model: ModelCard }) {
  const m = model.metrics
  const scenarios = Object.keys(m.anomaly_model?.detection_rate_by_scenario ?? {})

  return (
    <div className="space-y-5">
      <Alert kind="warning">
        <strong>Synthetic evaluation.</strong> These numbers were measured on generated data (seed {model.training_dataset.seed ?? '—'}). They describe how well the detector separates
        the synthetic scenarios — <strong>not</strong> real-world detection accuracy.
      </Alert>

      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <dt className="text-xs text-ink-500">Model</dt>
          <dd className="text-ink-100">
            {model.algorithm} <Mono>{model.version}</Mono>
          </dd>
        </div>
        <div>
          <dt className="text-xs text-ink-500">Trained</dt>
          <dd className="text-ink-300">{formatDateTime(model.trained_at)}</dd>
        </div>
        <div>
          <dt className="text-xs text-ink-500">Test set</dt>
          <dd className="text-ink-300">
            {m.test_size ? `${m.test_size.normal.toLocaleString()} normal · ${m.test_size.attack.toLocaleString()} attack` : '—'}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-ink-500">Artifact SHA-256</dt>
          <dd>
            <Mono>{model.artifact_sha256.slice(0, 16)}…</Mono>
          </dd>
        </div>
      </dl>

      <div className="-mx-5 overflow-x-auto">
        <table className="w-full text-right text-sm">
          <caption className="sr-only">Evaluation metrics on the synthetic test set</caption>
          <thead>
            <tr className="text-[11px] tracking-[0.12em] text-ink-500 uppercase">
              <th className="px-5 py-2 text-left font-medium">Decision</th>
              <th className="px-3 py-2 font-medium">Precision</th>
              <th className="px-3 py-2 font-medium">Recall</th>
              <th className="px-3 py-2 font-medium">F1</th>
              <th className="px-5 py-2 font-medium">False positives</th>
            </tr>
          </thead>
          <tbody className="font-mono text-ink-300">
            {[
              ['Anomaly model ≥ p99', m.anomaly_model?.at_threshold],
              ['Rules only', m.rules_only],
              ['Risk ≥ 40 (suspicious+)', m.risk_score?.suspicious_or_worse],
              ['Risk ≥ 70 (malicious)', m.risk_score?.malicious],
            ].map(([label, r]) => {
              const rates = r as ModelCard['metrics']['rules_only']
              return (
                <tr key={label as string} className="border-t border-graphite-700">
                  <td className="px-5 py-2 text-left font-sans text-ink-100">{label as string}</td>
                  <td className="px-3 py-2">{num(rates?.precision)}</td>
                  <td className="px-3 py-2">{num(rates?.recall)}</td>
                  <td className="px-3 py-2">{num(rates?.f1)}</td>
                  <td className="px-5 py-2">{pct(rates?.false_positive_rate)}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-ink-500">
        ROC-AUC: anomaly model <Mono>{num(m.anomaly_model?.roc_auc)}</Mono> · complete risk score <Mono>{num(m.risk_score?.roc_auc)}</Mono>
      </p>

      {scenarios.length ? (
        <div className="-mx-5 overflow-x-auto">
          <table className="w-full text-right text-sm">
            <caption className="px-5 pb-2 text-left text-xs text-ink-500">Detection rate by scenario (the “normal” row is the false-positive rate)</caption>
            <thead>
              <tr className="text-[11px] tracking-[0.12em] text-ink-500 uppercase">
                <th className="px-5 py-2 text-left font-medium">Scenario</th>
                <th className="px-3 py-2 font-medium">Anomaly model</th>
                <th className="px-3 py-2 font-medium">Rules</th>
                <th className="px-5 py-2 font-medium">Risk ≥ 40</th>
              </tr>
            </thead>
            <tbody className="font-mono text-ink-300">
              {scenarios.map((s) => (
                <tr key={s} className="border-t border-graphite-700">
                  <td className="px-5 py-1.5 text-left">{s}</td>
                  <td className="px-3 py-1.5">{pct(m.anomaly_model?.detection_rate_by_scenario[s])}</td>
                  <td className="px-3 py-1.5">{pct(m.rules_only?.detection_rate_by_scenario[s])}</td>
                  <td className="px-5 py-1.5">{pct(m.risk_score?.suspicious_or_worse.detection_rate_by_scenario[s])}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <div>
        <h3 className="mb-2 text-xs font-medium tracking-wide text-ink-500 uppercase">Known limitations</h3>
        <ul className="list-disc space-y-1.5 pl-5 text-sm text-ink-300">
          {model.limitations.map((l) => (
            <li key={l}>{l}</li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function RuleRow({ rule, organisationId, canManage }: { rule: DetectionRule; organisationId: string; canManage: boolean }) {
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(rule.parameters).map(([k, v]) => [k, String(v)])))
  const refresh = () => void queryClient.invalidateQueries({ queryKey: phase3Keys.rules(organisationId) })

  const update = useMutation({
    mutationFn: (body: { enabled?: boolean; parameters?: Record<string, number> }) => api.patch(`/organisations/${organisationId}/detection-rules/${rule.key}`, body),
    onSuccess: refresh,
  })
  const reset = useMutation({ mutationFn: () => api.delete(`/organisations/${organisationId}/detection-rules/${rule.key}`), onSuccess: refresh })
  const error = update.error instanceof ApiError ? update.error : null
  const dirty = Object.entries(draft).some(([k, v]) => Number(v) !== rule.parameters[k])

  return (
    <li className="border-t border-graphite-700 py-3.5 first:border-t-0">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className={cx('text-sm', rule.enabled ? 'text-ink-100' : 'text-ink-600 line-through')}>{rule.name}</span>
            <Badge>{rule.severity}</Badge>
            <Badge tone="info">{rule.category.replace('_', ' ')}</Badge>
            {rule.customised ? <Badge tone="warn">customised</Badge> : null}
          </div>
          <p className="mt-0.5 text-xs text-ink-500">{rule.description}</p>
          <Mono>{rule.key}</Mono>
        </div>
        {canManage ? (
          <label className="flex items-center gap-2 text-xs text-ink-300">
            <input
              type="checkbox"
              role="switch"
              aria-label={`${rule.enabled ? 'Disable' : 'Enable'} ${rule.name}`}
              checked={rule.enabled}
              disabled={update.isPending}
              onChange={(e) => update.mutate({ enabled: e.target.checked })}
              className="size-4 accent-crimson-500"
            />
            Enabled
          </label>
        ) : (
          <Badge tone={rule.enabled ? 'ok' : 'neutral'}>{rule.enabled ? 'enabled' : 'disabled'}</Badge>
        )}
      </div>

      {Object.keys(rule.parameters).length ? (
        <div className="mt-3 flex flex-wrap items-end gap-3">
          {Object.entries(rule.bounds).map(([name, b]) => (
            <label key={name} className="text-xs text-ink-500">
              <span className="mb-1 block font-mono">{name}</span>
              <input
                type="number"
                step="any"
                min={b.min}
                max={b.max}
                value={draft[name] ?? ''}
                disabled={!canManage}
                onChange={(e) => setDraft((d) => ({ ...d, [name]: e.target.value }))}
                aria-label={`${rule.name}: ${name}`}
                className="h-8 w-28 rounded-md border border-graphite-600 bg-obsidian-900 px-2 font-mono text-xs text-ink-100 disabled:text-ink-500"
              />
              <span className="mt-0.5 block text-[10px] text-ink-600">
                default {rule.defaults[name]} · {b.min}–{b.max}
              </span>
            </label>
          ))}
          {canManage ? (
            <div className="flex gap-2 pb-4">
              <Button size="sm" variant="secondary" disabled={!dirty} loading={update.isPending} onClick={() => update.mutate({ parameters: Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, Number(v)])) })}>
                Save
              </Button>
              {rule.customised ? (
                <Button size="sm" variant="ghost" icon={<RotateCcw className="size-3.5" />} loading={reset.isPending} onClick={() => reset.mutate()}>
                  Reset
                </Button>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
      {error ? <p className="mt-2 text-xs text-crimson-300">{error.details ? Object.values(error.details).flat().join(' ') : error.message}</p> : null}
    </li>
  )
}

export function IntelligencePage() {
  const { active, can } = useOrganisation()
  const organisationId = active!.organisation.id

  const stats = useQuery({ queryKey: phase3Keys.intelligence(organisationId), queryFn: () => fetchIntelligenceStats(organisationId), refetchInterval: 30_000 })
  const rules = useQuery({ queryKey: phase3Keys.rules(organisationId), queryFn: () => fetchDetectionRules(organisationId) })
  const detections = useQuery({
    queryKey: phase3Keys.detections(organisationId, { per_page: 8 }),
    queryFn: () => fetchDetections(organisationId, { per_page: 8 }),
    refetchInterval: 30_000,
  })
  const model = useQuery({ queryKey: phase3Keys.model, queryFn: fetchModelCard, staleTime: 300_000, retry: false })
  const s = stats.data

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="Threat intelligence" description="Detections by KRATOS MIND: deterministic rules, anomaly model and statistical outliers — every score explained." />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Tile label="Analysed · 24 h" value={s ? s.analysed_last_24h.toLocaleString() : '—'} icon={<ScanSearch className="size-4" />} />
        <Tile label="Malicious · 24 h" value={s ? s.verdicts.malicious.toLocaleString() : '—'} icon={<Siren className="size-4" />} tone={s && s.verdicts.malicious > 0 ? 'alert' : undefined} />
        <Tile label="Suspicious · 24 h" value={s ? s.verdicts.suspicious.toLocaleString() : '—'} icon={<ShieldAlert className="size-4" />} tone={s && s.verdicts.suspicious > 0 ? 'warn' : undefined} />
        <Tile label="Awaiting analysis" value={s ? s.awaiting_analysis.toLocaleString() : '—'} icon={<Timer className="size-4" />} />
      </div>

      {stats.isError ? <Alert kind="error">Unable to load intelligence statistics.</Alert> : null}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Anomaly trend · per hour (24 h)" className="lg:col-span-2">
          {s ? <AnomalyTrendChart data={s.anomaly_trend} /> : <Spinner />}
        </Card>
        <Card title="Risk distribution · 24 h">{s ? <RiskHistogram data={s.risk_histogram} /> : <Spinner />}</Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Rule hits · 24 h">
          {s && s.rule_hits.length ? (
            <TopEventTypes data={s.rule_hits.map((r) => ({ event_type: r.rule_key, count: r.count }))} />
          ) : s ? (
            <EmptyState title="No rule fired in the last 24 hours" />
          ) : (
            <Spinner />
          )}
          {s && s.categories.length ? (
            <div className="mt-5 flex flex-wrap gap-1.5">
              {s.categories.map((c) => (
                <Badge key={c.category}>
                  {c.category.replace('_', ' ')} · {c.count}
                </Badge>
              ))}
            </div>
          ) : null}
        </Card>

        <Card title="Latest detections" className="lg:col-span-2">
          {detections.isLoading ? (
            <Spinner />
          ) : !detections.data?.data.length ? (
            <EmptyState icon={<BrainCircuit className="size-8" />} title="No detections yet" />
          ) : (
            <ul className="space-y-3">
              {detections.data.data.map((d) => (
                <li key={d.id} className="flex items-start justify-between gap-4 border-t border-graphite-700 pt-3 first:border-t-0 first:pt-0">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone={d.detector === 'rule' ? 'crimson' : 'info'}>{d.detector === 'rule' ? d.rule_key : d.detector.replace('_', ' ')}</Badge>
                      {d.event ? <Mono>{d.event.event_type}</Mono> : null}
                    </div>
                    <p className="mt-1 text-sm text-ink-300">{d.explanation}</p>
                  </div>
                  <div className="shrink-0 text-right">
                    {d.event ? (
                      <Link to={`/events?event=${d.event.id}`} className="block hover:underline">
                        <RiskBadge risk={d.event.risk_score} verdict={d.event.verdict} />
                      </Link>
                    ) : null}
                    <time className="text-[11px] text-ink-600" dateTime={d.created_at}>
                      {formatRelative(d.created_at)}
                    </time>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card title="Detection rules" actions={can('detections.manage') ? <span className="text-xs text-ink-500">Changes apply to new analyses and are audited</span> : null}>
        {rules.isLoading ? (
          <Spinner />
        ) : rules.isError ? (
          <Alert kind="error">Unable to load rules.</Alert>
        ) : (
          <ul>
            {rules.data!.map((r) => (
              <RuleRow key={`${r.key}:${r.customised}:${JSON.stringify(r.parameters)}`} rule={r} organisationId={organisationId} canManage={can('detections.manage')} />
            ))}
          </ul>
        )}
      </Card>

      <Card title="Anomaly model" actions={<FlaskConical className="size-4 text-ink-600" aria-hidden />}>
        {model.isLoading ? (
          <Spinner />
        ) : model.data ? (
          <ModelPanel model={model.data} />
        ) : (
          <EmptyState icon={<AlertTriangle className="size-7" />} title="Model information unavailable">
            KRATOS MIND has not been reached yet. Detection resumes automatically when it is available.
          </EmptyState>
        )}
      </Card>
    </div>
  )
}
