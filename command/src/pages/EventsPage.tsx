import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Activity, RotateCcw, Search, X } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router'
import { RiskBadge, SeverityBadge, StatusBadge } from '../components/eye/badges'
import { Alert, Button, Card, cx, EmptyState, Mono, PageHeader, Select, Spinner } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { formatDateTime, formatRelative } from '../lib/format'
import { useOrganisation } from '../lib/organisation'
import { fetchEvent, fetchEvents, phase2Keys, type EventFilters } from '../lib/queries'
import { SEVERITIES, type ProcessingStatus, type Severity } from '../lib/types'

const STATUSES: ProcessingStatus[] = ['received', 'queued', 'processing', 'processed', 'failed', 'dead_lettered']

/** datetime-local value (local time) → ISO string for the API. */
const localToIso = (value: string) => (value ? new Date(value).toISOString() : undefined)

function EventDetail({ organisationId, eventId, onClose }: { organisationId: string; eventId: string; onClose: () => void }) {
  const { can } = useOrganisation()
  const queryClient = useQueryClient()
  const event = useQuery({ queryKey: phase2Keys.event(organisationId, eventId), queryFn: () => fetchEvent(organisationId, eventId) })

  const replay = useMutation({
    mutationFn: () => api.post(`/organisations/${organisationId}/events/${eventId}/replay`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organisations', organisationId, 'events'] })
      void queryClient.invalidateQueries({ queryKey: phase2Keys.stats(organisationId) })
    },
  })

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const e = event.data
  const replayable = e && (e.processing_status === 'failed' || e.processing_status === 'dead_lettered')

  return (
    <div className="fixed inset-0 z-40" role="dialog" aria-modal="true" aria-label="Event detail">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      <aside className="absolute inset-y-0 right-0 flex w-full max-w-xl animate-fade-in flex-col border-l border-graphite-700 bg-obsidian-900 shadow-2xl">
        <header className="flex items-center justify-between border-b border-graphite-700 px-5 py-4">
          <div className="min-w-0">
            <h2 className="truncate font-mono text-sm text-ink-100">{e?.event_type ?? 'Event'}</h2>
            <p className="font-mono text-[11px] text-ink-600">{eventId}</p>
          </div>
          <button type="button" onClick={onClose} className="text-ink-500 hover:text-ink-100" aria-label="Close">
            <X className="size-5" />
          </button>
        </header>

        <div className="flex-1 space-y-5 overflow-y-auto p-5 text-sm">
          {event.isLoading ? <Spinner /> : null}
          {event.isError ? <Alert kind="error">Unable to load this event.</Alert> : null}
          {e ? (
            <>
              <dl className="grid grid-cols-2 gap-x-6 gap-y-3">
                <div>
                  <dt className="text-xs text-ink-500">Severity</dt>
                  <dd className="mt-1">
                    <SeverityBadge severity={e.severity} />
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Processing</dt>
                  <dd className="mt-1">
                    <StatusBadge status={e.processing_status} />
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Occurred</dt>
                  <dd className="text-ink-300">{formatDateTime(e.occurred_at)}</dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Received</dt>
                  <dd className="text-ink-300">{formatDateTime(e.received_at)}</dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Source</dt>
                  <dd>
                    <Mono>{e.source}</Mono>
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Source IP</dt>
                  <dd>
                    <Mono>{e.source_ip ?? '—'}</Mono>
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Asset</dt>
                  <dd className="text-ink-300">{e.asset ? e.asset.name : '—'}</dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Ingestion key</dt>
                  <dd className="text-ink-300">{e.credential ? `${e.credential.name} (${e.credential.key_id.slice(0, 10)}…)` : '—'}</dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Risk</dt>
                  <dd className="mt-1">
                    <RiskBadge risk={e.risk_score} verdict={e.verdict} />
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-ink-500">Idempotency key</dt>
                  <dd>
                    <Mono>{e.idempotency_key ?? '—'}</Mono>
                  </dd>
                </div>
              </dl>

              {e.analysis ? (
                <section aria-label="Analysis">
                  <h3 className="mb-2 text-xs font-medium tracking-wide text-ink-500 uppercase">
                    Analysis {e.analysis.model_version ? <span className="font-mono normal-case">· model {e.analysis.model_version}</span> : null}
                  </h3>
                  {e.analysis.summary ? (
                    <p className="rounded border border-graphite-700 bg-obsidian-950 p-3 text-xs leading-relaxed text-ink-300">{e.analysis.summary}</p>
                  ) : (
                    <p className="text-xs text-ink-500">Not analysed yet{e.processing_status === 'failed' ? ' — waiting for the analysis service' : ''}.</p>
                  )}
                  {e.analysis.detections?.length ? (
                    <ul className="mt-3 space-y-2">
                      {e.analysis.detections.map((d) => (
                        <li key={d.id} className="rounded border border-graphite-700 p-2.5">
                          <div className="flex items-center justify-between gap-2 text-xs">
                            <span className="font-mono text-ink-100">{d.detector === 'rule' ? d.rule_key : d.detector.replace('_', ' ')}</span>
                            <span className="text-ink-500">
                              {d.detector === 'rule' ? `score ${d.score}` : d.detector === 'anomaly_model' ? `percentile ${d.score}` : `robust z ${d.score}`}
                            </span>
                          </div>
                          <p className="mt-1 text-xs text-ink-300">{d.explanation}</p>
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </section>
              ) : null}

              <section>
                <h3 className="mb-2 text-xs font-medium tracking-wide text-ink-500 uppercase">Metadata</h3>
                <pre className="overflow-x-auto rounded border border-graphite-700 bg-obsidian-950 p-3 font-mono text-[11px] text-ink-300">{JSON.stringify(e.metadata, null, 2)}</pre>
              </section>

              {e.processing ? (
                <section>
                  <h3 className="mb-2 text-xs font-medium tracking-wide text-ink-500 uppercase">Pipeline</h3>
                  <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-xs">
                    <dt className="text-ink-500">Published to queue</dt>
                    <dd className="text-ink-300">{e.processing.published_at ? formatDateTime(e.processing.published_at) : 'not yet'}</dd>
                    <dt className="text-ink-500">Processing attempts</dt>
                    <dd className="text-ink-300">{e.processing.attempts}</dd>
                    <dt className="text-ink-500">Processed</dt>
                    <dd className="text-ink-300">{formatDateTime(e.processing.processed_at)}</dd>
                  </dl>
                  {e.processing.last_error ? (
                    <pre className="mt-2 overflow-x-auto rounded border border-crimson-600/40 bg-crimson-900/20 p-2 font-mono text-[11px] text-crimson-300">{e.processing.last_error}</pre>
                  ) : null}
                </section>
              ) : null}

              {replayable && can('events.manage') ? (
                <div className="space-y-2">
                  {replay.isSuccess ? <Alert kind="success">Event re-queued for processing.</Alert> : null}
                  {replay.error instanceof ApiError ? <Alert kind="error">{replay.error.message}</Alert> : null}
                  <Button variant="secondary" icon={<RotateCcw className="size-4" />} loading={replay.isPending} onClick={() => replay.mutate()}>
                    Replay event
                  </Button>
                </div>
              ) : null}
            </>
          ) : null}
        </div>
      </aside>
    </div>
  )
}

export function EventsPage() {
  const { active } = useOrganisation()
  const organisationId = active!.organisation.id
  const [params, setParams] = useSearchParams()

  const filters: EventFilters = {
    severity: params.get('severity') ?? undefined,
    event_type: params.get('event_type') ?? undefined,
    status: params.get('status') ?? undefined,
    asset_id: params.get('asset_id') ?? undefined,
    verdict: params.get('verdict') ?? undefined,
    q: params.get('q') ?? undefined,
    from: params.get('from') ?? undefined,
    to: params.get('to') ?? undefined,
    sort: params.get('sort') ?? '-occurred_at',
    page: Number(params.get('page') ?? 1),
  }

  const selected = params.get('event')
  const setSelected = (id: string | null) => {
    const next = new URLSearchParams(params)
    if (id) next.set('event', id)
    else next.delete('event')
    setParams(next, { replace: true })
  }

  const [search, setSearch] = useState(filters.q ?? '')
  const activeSeverities = new Set((filters.severity ?? '').split(',').filter(Boolean) as Severity[])

  const update = (changes: Record<string, string | undefined>) => {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(changes)) {
      if (value) next.set(key, value)
      else next.delete(key)
    }
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }

  const events = useQuery({
    queryKey: phase2Keys.events(organisationId, filters),
    queryFn: () => fetchEvents(organisationId, filters),
    placeholderData: keepPreviousData,
    refetchInterval: 15_000,
  })
  const pagination = events.data?.meta.pagination

  const toggleSeverity = (s: Severity) => {
    const next = new Set(activeSeverities)
    if (next.has(s)) next.delete(s)
    else next.add(s)
    update({ severity: SEVERITIES.filter((x) => next.has(x)).join(',') || undefined })
  }

  const onSearch = (e: FormEvent) => {
    e.preventDefault()
    update({ q: search.trim() || undefined })
  }

  const hasFilters = ['severity', 'event_type', 'status', 'asset_id', 'verdict', 'q', 'from', 'to'].some((k) => params.has(k))

  return (
    <div className="animate-fade-in">
      <PageHeader title="Security events" description="Normalised telemetry received by KRATOS EYE. Refreshes every 15 seconds." />

      <Card>
        <div className="mb-5 space-y-3">
          <form onSubmit={onSearch} className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-56 flex-1">
              <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-600" aria-hidden />
              <input
                aria-label="Search event type, source or source IP"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search type, source or IP…"
                className="h-9 w-full rounded-md border border-graphite-600 bg-obsidian-900 pr-3 pl-9 text-sm text-ink-100 placeholder:text-ink-600 focus:border-crimson-500 focus:outline-none"
              />
            </div>
            <Select aria-label="Filter by processing status" value={filters.status ?? ''} onChange={(e) => update({ status: e.target.value || undefined })} className="w-44">
              <option value="">Any status</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {s.replace('_', ' ')}
                </option>
              ))}
            </Select>
            <Select aria-label="Filter by verdict" value={filters.verdict ?? ''} onChange={(e) => update({ verdict: e.target.value || undefined })} className="w-44">
              <option value="">Any verdict</option>
              <option value="malicious">Malicious</option>
              <option value="suspicious,malicious">Suspicious or worse</option>
              <option value="suspicious">Suspicious</option>
              <option value="benign">Benign</option>
            </Select>
            <Select aria-label="Sort" value={filters.sort} onChange={(e) => update({ sort: e.target.value })} className="w-48">
              <option value="-occurred_at">Newest first</option>
              <option value="occurred_at">Oldest first</option>
              <option value="-risk_score">Highest risk first</option>
              <option value="-severity">Most severe first</option>
              <option value="-received_at">Recently received</option>
            </Select>
          </form>

          <div className="flex flex-wrap items-center gap-2 text-xs">
            <span className="text-ink-500">Severity</span>
            {SEVERITIES.map((s) => (
              <button
                key={s}
                type="button"
                aria-pressed={activeSeverities.has(s)}
                onClick={() => toggleSeverity(s)}
                className={cx(
                  'rounded-full border px-2.5 py-1 capitalize transition-colors',
                  activeSeverities.has(s) ? 'border-crimson-500 bg-crimson-900/40 text-ink-100' : 'border-graphite-600 text-ink-500 hover:text-ink-100',
                )}
              >
                {s}
              </button>
            ))}
            <span className="ml-3 text-ink-500">From</span>
            <input
              type="datetime-local"
              aria-label="Occurred from"
              className="h-8 rounded-md border border-graphite-600 bg-obsidian-900 px-2 text-xs text-ink-100"
              onChange={(e) => update({ from: localToIso(e.target.value) })}
            />
            <span className="text-ink-500">to</span>
            <input
              type="datetime-local"
              aria-label="Occurred to"
              className="h-8 rounded-md border border-graphite-600 bg-obsidian-900 px-2 text-xs text-ink-100"
              onChange={(e) => update({ to: localToIso(e.target.value) })}
            />
            {filters.asset_id ? (
              <button type="button" onClick={() => update({ asset_id: undefined })} className="ml-2 inline-flex items-center gap-1 rounded-full border border-crimson-500 bg-crimson-900/40 px-2.5 py-1 text-ink-100">
                One asset <X className="size-3" aria-hidden />
              </button>
            ) : null}
            {hasFilters ? (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  setSearch('')
                  setParams(new URLSearchParams(), { replace: true })
                }}
              >
                Clear filters
              </Button>
            ) : null}
          </div>
        </div>

        {events.isLoading ? (
          <Spinner />
        ) : events.error instanceof ApiError ? (
          <Alert kind="error">{events.error.code === 'validation_failed' ? 'Some filters are invalid.' : 'Unable to load events.'}</Alert>
        ) : !events.data?.data.length ? (
          <EmptyState icon={<Activity className="size-8" />} title={hasFilters ? 'No events match these filters' : 'No events received yet'} />
        ) : (
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-[11px] tracking-[0.12em] text-ink-500 uppercase">
                  <th className="px-3 py-2 font-medium">Occurred</th>
                  <th className="px-3 py-2 font-medium">Severity</th>
                  <th className="px-3 py-2 font-medium">Event type</th>
                  <th className="hidden px-3 py-2 font-medium md:table-cell">Source</th>
                  <th className="hidden px-3 py-2 font-medium lg:table-cell">Asset</th>
                  <th className="hidden px-3 py-2 font-medium lg:table-cell">Source IP</th>
                  <th className="px-3 py-2 font-medium">Risk</th>
                  <th className="hidden px-3 py-2 font-medium sm:table-cell">Status</th>
                </tr>
              </thead>
              <tbody>
                {events.data.data.map((ev) => (
                  <tr
                    key={ev.id}
                    tabIndex={0}
                    onClick={() => setSelected(ev.id)}
                    onKeyDown={(k) => k.key === 'Enter' && setSelected(ev.id)}
                    className="cursor-pointer border-t border-graphite-700 hover:bg-graphite-750 focus:bg-graphite-750 focus:outline-none"
                  >
                    <td className="px-3 py-2.5 whitespace-nowrap text-ink-300" title={formatDateTime(ev.occurred_at)}>
                      {formatRelative(ev.occurred_at)}
                    </td>
                    <td className="px-3 py-2.5">
                      <SeverityBadge severity={ev.severity} />
                    </td>
                    <td className="px-3 py-2.5">
                      <Mono>{ev.event_type}</Mono>
                    </td>
                    <td className="hidden px-3 py-2.5 text-ink-300 md:table-cell">{ev.source}</td>
                    <td className="hidden px-3 py-2.5 text-ink-300 lg:table-cell">{ev.asset?.name ?? <span className="text-ink-600">—</span>}</td>
                    <td className="hidden px-3 py-2.5 lg:table-cell">
                      <Mono>{ev.source_ip ?? '—'}</Mono>
                    </td>
                    <td className="px-3 py-2.5 whitespace-nowrap">
                      <RiskBadge risk={ev.risk_score} verdict={ev.verdict} />
                    </td>
                    <td className="hidden px-3 py-2.5 sm:table-cell">
                      <StatusBadge status={ev.processing_status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {pagination && pagination.total > 0 ? (
          <div className="mt-5 flex items-center justify-end gap-2 text-xs text-ink-500">
            <span>
              {pagination.total.toLocaleString()} events · page {pagination.page} of {pagination.last_page}
            </span>
            <Button size="sm" variant="secondary" disabled={pagination.page <= 1} onClick={() => update({ page: String(pagination.page - 1) })}>
              Previous
            </Button>
            <Button size="sm" variant="secondary" disabled={pagination.page >= pagination.last_page} onClick={() => update({ page: String(pagination.page + 1) })}>
              Next
            </Button>
          </div>
        ) : null}
      </Card>

      {selected ? <EventDetail organisationId={organisationId} eventId={selected} onClose={() => setSelected(null)} /> : null}
    </div>
  )
}
