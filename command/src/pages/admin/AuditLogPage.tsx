import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, FileClock, Search } from 'lucide-react'
import { Fragment, useState } from 'react'
import { Alert, Button, Card, EmptyState, Mono, PageHeader, Spinner } from '../../components/ui'
import { formatDateTime } from '../../lib/format'
import { useOrganisation } from '../../lib/organisation'
import { fetchAuditLogs, queryKeys } from '../../lib/queries'

const ACTION_PRESETS = ['', 'auth.', 'organisation.', 'membership.']

export function AuditLogPage() {
  const { active } = useOrganisation()
  const organisationId = active!.organisation.id
  const [action, setAction] = useState('')
  const [draft, setDraft] = useState('')
  const [page, setPage] = useState(1)
  const [expanded, setExpanded] = useState<string | null>(null)

  const params = { action: action || undefined, page, per_page: 25, sort: '-created_at' }
  const logs = useQuery({
    queryKey: queryKeys.auditLogs(organisationId, params),
    queryFn: () => fetchAuditLogs(organisationId, params),
    placeholderData: keepPreviousData,
  })
  const pagination = logs.data?.meta.pagination

  const applyFilter = (value: string) => {
    setAction(value)
    setDraft(value)
    setPage(1)
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title="Audit log" description="Append-only record of security-relevant actions in this organisation." />

      <Card>
        <form
          className="mb-5 flex flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            applyFilter(draft.trim().toLowerCase())
          }}
        >
          <div className="relative min-w-56 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-600" aria-hidden />
            <input
              aria-label="Filter by action prefix"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              placeholder="Filter by action prefix, e.g. membership."
              pattern="[a-z0-9_.]*"
              className="h-9 w-full rounded-md border border-graphite-600 bg-obsidian-900 pr-3 pl-9 font-mono text-xs text-ink-100 placeholder:font-sans placeholder:text-ink-600 focus:border-crimson-500 focus:outline-none"
            />
          </div>
          {ACTION_PRESETS.map((preset) => (
            <Button key={preset || 'all'} size="sm" variant={action === preset ? 'secondary' : 'ghost'} onClick={() => applyFilter(preset)}>
              {preset || 'All'}
            </Button>
          ))}
        </form>

        {logs.isLoading ? (
          <Spinner />
        ) : logs.isError ? (
          <Alert kind="error">Unable to load the audit log.</Alert>
        ) : !logs.data?.data.length ? (
          <EmptyState icon={<FileClock className="size-8" />} title="No matching audit records" />
        ) : (
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-[11px] tracking-[0.12em] text-ink-500 uppercase">
                  <th className="w-8 px-3 py-2" />
                  <th className="px-3 py-2 font-medium">Time</th>
                  <th className="px-3 py-2 font-medium">Action</th>
                  <th className="px-3 py-2 font-medium">Actor</th>
                  <th className="hidden px-3 py-2 font-medium lg:table-cell">Source IP</th>
                </tr>
              </thead>
              <tbody>
                {logs.data.data.map((entry) => {
                  const open = expanded === entry.id
                  return (
                    <Fragment key={entry.id}>
                      <tr className="cursor-pointer border-t border-graphite-700 hover:bg-graphite-750" onClick={() => setExpanded(open ? null : entry.id)}>
                        <td className="px-3 py-2.5 text-ink-600">
                          <button type="button" aria-expanded={open} aria-label="Toggle details" className="align-middle">
                            {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                          </button>
                        </td>
                        <td className="px-3 py-2.5 whitespace-nowrap text-ink-300">{formatDateTime(entry.created_at)}</td>
                        <td className="px-3 py-2.5">
                          <Mono>{entry.action}</Mono>
                        </td>
                        <td className="px-3 py-2.5 text-ink-300">{entry.actor ? entry.actor.email : <span className="text-ink-600">system</span>}</td>
                        <td className="hidden px-3 py-2.5 lg:table-cell">
                          <Mono>{entry.ip_address ?? '—'}</Mono>
                        </td>
                      </tr>
                      {open ? (
                        <tr className="bg-obsidian-900/60">
                          <td />
                          <td colSpan={4} className="px-3 py-3">
                            <dl className="grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2">
                              <div>
                                <dt className="text-ink-500">Target</dt>
                                <dd>
                                  <Mono>{entry.target ? `${entry.target.type}:${entry.target.id}` : '—'}</Mono>
                                </dd>
                              </div>
                              <div>
                                <dt className="text-ink-500">Request ID</dt>
                                <dd>
                                  <Mono>{entry.request_id ?? '—'}</Mono>
                                </dd>
                              </div>
                              <div className="sm:col-span-2">
                                <dt className="text-ink-500">User agent</dt>
                                <dd className="break-all text-ink-300">{entry.user_agent ?? '—'}</dd>
                              </div>
                              <div className="sm:col-span-2">
                                <dt className="text-ink-500">Metadata</dt>
                                <dd>
                                  <pre className="mt-1 overflow-x-auto rounded border border-graphite-700 bg-obsidian-950 p-3 font-mono text-[11px] text-ink-300">
                                    {JSON.stringify(entry.metadata, null, 2)}
                                  </pre>
                                </dd>
                              </div>
                            </dl>
                          </td>
                        </tr>
                      ) : null}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        {pagination && pagination.last_page > 1 ? (
          <div className="mt-5 flex items-center justify-end gap-2 text-xs text-ink-500">
            <span>
              {pagination.total} records · page {pagination.page} of {pagination.last_page}
            </span>
            <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <Button size="sm" variant="secondary" disabled={page >= pagination.last_page} onClick={() => setPage((p) => p + 1)}>
              Next
            </Button>
          </div>
        ) : null}
      </Card>
    </div>
  )
}
