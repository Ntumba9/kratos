import { useQuery } from '@tanstack/react-query'
import { Activity, Boxes, Database, FileClock, Gauge, ShieldAlert, Siren, Skull } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { SeverityDistribution, SeverityTimeline, ThroughputChart, TopEventTypes } from '../components/eye/charts'
import { Badge, Card, cx, EmptyState, Mono, PageHeader, Spinner } from '../components/ui'
import { formatDateTime, formatRelative } from '../lib/format'
import { useOrganisation } from '../lib/organisation'
import { fetchAuditLogs, fetchHealth, fetchOverviewStats, phase2Keys, queryKeys } from '../lib/queries'

function Stat({ label, value, icon, footnote, tone }: { label: string; value: ReactNode; icon: ReactNode; footnote?: ReactNode; tone?: 'alert' | 'muted' }) {
  return (
    <div role="group" aria-label={label} className="rounded-lg border border-graphite-700 bg-graphite-800/80 p-4">
      <div className="flex items-center justify-between text-ink-500">
        <span className="text-[11px] font-medium tracking-[0.14em] uppercase">{label}</span>
        <span className="text-ink-600">{icon}</span>
      </div>
      <div className={cx('mt-3 text-2xl font-semibold tabular-nums', tone === 'muted' ? 'text-ink-600' : tone === 'alert' ? 'text-crimson-300' : 'text-ink-100')}>{value}</div>
      {footnote ? <div className="mt-1 text-xs text-ink-500">{footnote}</div> : null}
    </div>
  )
}

function SystemHealth({ deadLettered, backlog }: { deadLettered: number; backlog: number }) {
  const health = useQuery({ queryKey: queryKeys.health, queryFn: fetchHealth, refetchInterval: 30_000 })

  if (health.isLoading) return <Spinner label="Probing dependencies" />
  if (health.isError || !health.data) {
    return (
      <div className="flex items-center gap-2 text-sm text-crimson-300">
        <span className="size-2 rounded-full bg-crimson-500" /> KRATOS CORE unreachable
      </div>
    )
  }

  const rows = [{ name: 'core api', status: 'up' as const, latency_ms: null }, ...Object.entries(health.data.checks).map(([name, check]) => ({ name, ...check }))]

  return (
    <div className="space-y-3">
      <ul className="divide-y divide-graphite-700">
        {rows.map((row) => (
          <li key={row.name} className="flex items-center justify-between py-2 text-sm">
            <span className="flex items-center gap-2.5 text-ink-300 capitalize">
              <span className={cx('relative size-2 rounded-full', row.status === 'up' ? 'bg-signal-ok' : 'bg-crimson-500')}>
                {row.status === 'up' ? <span className="absolute inset-0 animate-pulse-slow rounded-full bg-signal-ok/60" /> : null}
              </span>
              {row.name}
            </span>
            <span className="flex items-center gap-3">
              {row.latency_ms !== null ? <Mono>{row.latency_ms.toFixed(1)} ms</Mono> : null}
              <Badge tone={row.status === 'up' ? 'ok' : 'crimson'}>{row.status}</Badge>
            </span>
          </li>
        ))}
        <li className="flex items-center justify-between py-2 text-sm">
          <span className="text-ink-300">Pipeline backlog</span>
          <Badge tone={backlog > 0 ? 'warn' : 'ok'}>{backlog} pending</Badge>
        </li>
        <li className="flex items-center justify-between py-2 text-sm">
          <span className="text-ink-300">Dead-lettered events</span>
          {deadLettered > 0 ? (
            <Link to="/events?status=dead_lettered">
              <Badge tone="crimson">{deadLettered} need replay</Badge>
            </Link>
          ) : (
            <Badge tone="ok">none</Badge>
          )}
        </li>
      </ul>
      <p className="text-xs text-ink-600">Dependencies checked {formatRelative(health.data.checked_at)} · refreshes every 30 s</p>
    </div>
  )
}

function RecentActivity({ organisationId }: { organisationId: string }) {
  const params = { per_page: 6, sort: '-created_at' }
  const logs = useQuery({ queryKey: queryKeys.auditLogs(organisationId, params), queryFn: () => fetchAuditLogs(organisationId, params) })

  if (logs.isLoading) return <Spinner />
  if (logs.isError) return <p className="text-sm text-crimson-300">Unable to load audit activity.</p>
  if (!logs.data?.data.length) return <EmptyState title="No audit activity yet" />

  return (
    <ul className="space-y-3">
      {logs.data.data.map((entry) => (
        <li key={entry.id} className="flex items-start justify-between gap-4 text-sm">
          <div className="min-w-0">
            <Mono>{entry.action}</Mono>
            <div className="truncate text-xs text-ink-500">{entry.actor ? entry.actor.name : 'System'}</div>
          </div>
          <time className="shrink-0 text-xs text-ink-500" dateTime={entry.created_at} title={formatDateTime(entry.created_at)}>
            {formatRelative(entry.created_at)}
          </time>
        </li>
      ))}
    </ul>
  )
}

export function OverviewPage() {
  const { active, can } = useOrganisation()
  const organisationId = active!.organisation.id
  const canSeeEvents = can('events.view')

  const stats = useQuery({
    queryKey: phase2Keys.stats(organisationId),
    queryFn: () => fetchOverviewStats(organisationId),
    enabled: canSeeEvents,
    refetchInterval: 15_000,
  })
  const s = stats.data

  const backlog = s ? s.events.by_status.received + s.events.by_status.queued + s.events.by_status.processing + s.events.by_status.failed : 0
  const noData = s !== undefined && s.events.total === 0

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Security overview"
        description={
          <>
            {active!.organisation.name} · <Mono>{active!.organisation.slug}</Mono>
            {s ? <span className="ml-2 text-ink-600">· live, updated {formatRelative(s.generated_at)}</span> : null}
          </>
        }
      />

      {stats.isError ? <p className="mb-4 text-sm text-crimson-300">Unable to load statistics.</p> : null}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <Stat label="Monitored assets" icon={<Boxes className="size-4" />} value={s ? s.assets.total : '—'} footnote={s ? `${s.assets.seen_last_24h} active in last 24 h` : undefined} />
        <Stat label="Events · 24 h" icon={<Activity className="size-4" />} value={s ? s.events.last_24h.toLocaleString() : '—'} footnote={s ? `${s.events.total.toLocaleString()} retained in total` : undefined} />
        <Stat
          label="High / critical · 24 h"
          icon={<Siren className="size-4" />}
          value={s ? s.events.high_or_critical_last_24h.toLocaleString() : '—'}
          tone={s && s.events.high_or_critical_last_24h > 0 ? 'alert' : undefined}
          footnote={<Link to="/events?severity=high,critical" className="hover:text-ink-100">Investigate →</Link>}
        />
        <Stat
          label="Processed / minute"
          icon={<Gauge className="size-4" />}
          value={s ? s.throughput.processed_last_minute : '—'}
          footnote={s?.throughput.average_processing_ms != null ? `avg ${Math.round(s.throughput.average_processing_ms)} ms receipt → processed (1 h)` : 'last complete minute'}
        />
        <Stat label="Active incidents" icon={<ShieldAlert className="size-4" />} value="—" tone="muted" footnote={<span>Available from <span className="text-ink-300">Phase 4</span></span>} />
      </div>

      {noData ? (
        <Card className="mt-6">
          <EmptyState icon={<Database className="size-8" />} title="No security events received yet">
            Issue an ingestion key under <Link to="/admin/ingestion-keys" className="text-ink-300 underline-offset-4 hover:underline">Ingestion keys</Link> and send events with the{' '}
            <Mono>tools/kratos-send</Mono> client or any HMAC-signing HTTP client. This dashboard updates automatically.
          </EmptyState>
        </Card>
      ) : null}

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Card title="Threat activity · events per hour by severity (24 h)" className="lg:col-span-2">
          {!canSeeEvents ? (
            <EmptyState title="Your role cannot view security events" />
          ) : s ? (
            <SeverityTimeline data={s.timeline} />
          ) : (
            <Spinner />
          )}
        </Card>

        <Card title="System health" actions={<Database className="size-4 text-ink-600" aria-hidden />}>
          <SystemHealth deadLettered={s?.events.by_status.dead_lettered ?? 0} backlog={backlog} />
        </Card>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Card title="Pipeline throughput · events per minute (1 h)" className="lg:col-span-2">
          {s ? <ThroughputChart data={s.throughput.per_minute} /> : <Spinner />}
        </Card>
        <Card title="Severity · last 24 h">{s ? <SeverityDistribution counts={s.events.last_24h_by_severity} /> : <Spinner />}</Card>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Card title="Top event types · last 24 h">
          {s && s.top_event_types.length ? <TopEventTypes data={s.top_event_types} /> : s ? <EmptyState title="No events in the last 24 hours" /> : <Spinner />}
        </Card>
        <Card
          title="Recent audit activity"
          className="lg:col-span-2"
          actions={
            can('audit_logs.view') ? (
              <Link to="/admin/audit-log" className="text-xs text-ink-500 hover:text-ink-100">
                View all
              </Link>
            ) : null
          }
        >
          {can('audit_logs.view') ? (
            <RecentActivity organisationId={organisationId} />
          ) : (
            <EmptyState icon={<FileClock className="size-7" />} title="Audit log access is limited to Organisation Administrators" />
          )}
        </Card>
      </div>

      {s && s.events.by_status.dead_lettered > 0 ? (
        <p className="mt-4 flex items-center gap-2 text-xs text-crimson-300">
          <Skull className="size-3.5" aria-hidden /> Some events exhausted their retries. They are kept, not dropped — replay them from Security Events.
        </p>
      ) : null}
    </div>
  )
}
