import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, KeyRound } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, Mono, PageHeader, Spinner } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { formatDateTime, formatRelative } from '../../lib/format'
import { useOrganisation } from '../../lib/organisation'
import { fetchCredentials, phase2Keys } from '../../lib/queries'
import type { ApiEnvelope, IngestionCredential } from '../../lib/types'

type Issued = { credential: IngestionCredential; secret: string }

function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <Button size="sm" variant="secondary" icon={<Copy className="size-3.5" />} onClick={() => void navigator.clipboard?.writeText(value).then(() => setCopied(true))}>
      {copied ? 'Copied' : 'Copy'}
    </Button>
  )
}

function IssuedSecret({ issued }: { issued: Issued }) {
  const origin = window.location.origin
  const env = `export KRATOS_URL=${origin}\nexport KRATOS_KEY_ID=${issued.credential.key_id}\nexport KRATOS_SECRET=<paste the secret>`

  return (
    <Alert kind="warning">
      <p className="mb-3 font-medium">Copy the secret now — it is shown only once and stored encrypted.</p>
      <div className="space-y-2">
        <div className="flex items-center gap-2">
          <span className="w-16 text-xs text-ink-500">Key ID</span>
          <code className="min-w-0 flex-1 truncate rounded border border-graphite-600 bg-obsidian-950 px-2 py-1.5 font-mono text-xs text-ink-100">{issued.credential.key_id}</code>
          <CopyButton value={issued.credential.key_id} />
        </div>
        <div className="flex items-center gap-2">
          <span className="w-16 text-xs text-ink-500">Secret</span>
          <code className="min-w-0 flex-1 truncate rounded border border-graphite-600 bg-obsidian-950 px-2 py-1.5 font-mono text-xs text-ink-100">{issued.secret}</code>
          <CopyButton value={issued.secret} />
        </div>
      </div>
      <p className="mt-4 mb-1 text-xs text-ink-300">Send events with the bundled client:</p>
      <pre className="overflow-x-auto rounded border border-graphite-700 bg-obsidian-950 p-3 font-mono text-[11px] text-ink-300">
        {env}
        {'\n'}python tools/kratos-send/kratos_send.py simulate --scenario mixed --count 50
      </pre>
    </Alert>
  )
}

export function IngestionKeysPage() {
  const { active } = useOrganisation()
  const organisationId = active!.organisation.id
  const queryClient = useQueryClient()
  const [name, setName] = useState('')
  const [days, setDays] = useState('365')
  const [issued, setIssued] = useState<Issued | null>(null)

  const credentials = useQuery({ queryKey: phase2Keys.credentials(organisationId), queryFn: () => fetchCredentials(organisationId) })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: phase2Keys.credentials(organisationId) })

  const create = useMutation({
    mutationFn: async () =>
      (await api.post<ApiEnvelope<Issued>>(`/organisations/${organisationId}/api-credentials`, { name, expires_in_days: days ? Number(days) : null })).data,
    onSuccess: (result) => {
      setIssued(result)
      setName('')
      refresh()
    },
  })
  const revoke = useMutation({ mutationFn: (id: string) => api.delete(`/organisations/${organisationId}/api-credentials/${id}`), onSuccess: refresh })
  const error = create.error instanceof ApiError ? create.error : null

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader
        title="Ingestion keys"
        description="Machine credentials that sign event submissions (HMAC-SHA256). Each key writes only into this organisation."
      />

      <Card title="Issue a key">
        <div className="space-y-4">
          {issued ? <IssuedSecret issued={issued} /> : null}
          <form
            className="grid gap-3 sm:grid-cols-[1fr_10rem_auto] sm:items-end"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              create.mutate()
            }}
          >
            <Field label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. web tier, identity provider" error={error?.field('name')} />
            <Field label="Expires (days)" type="number" min={1} max={730} value={days} onChange={(e) => setDays(e.target.value)} error={error?.field('expires_in_days')} hint="Blank = no expiry" />
            <Button type="submit" loading={create.isPending} icon={<KeyRound className="size-4" />}>
              Issue key
            </Button>
          </form>
          {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
        </div>
      </Card>

      <Card title="Keys">
        {credentials.isLoading ? (
          <Spinner />
        ) : credentials.isError ? (
          <Alert kind="error">Unable to load keys.</Alert>
        ) : !credentials.data?.data.length ? (
          <EmptyState icon={<KeyRound className="size-8" />} title="No ingestion keys yet" />
        ) : (
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-[11px] tracking-[0.12em] text-ink-500 uppercase">
                  <th className="px-3 py-2 font-medium">Name</th>
                  <th className="px-3 py-2 font-medium">Key ID</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="hidden px-3 py-2 font-medium md:table-cell">Last used</th>
                  <th className="hidden px-3 py-2 font-medium md:table-cell">Expires</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody>
                {credentials.data.data.map((c) => (
                  <tr key={c.id} className="border-t border-graphite-700">
                    <td className="px-3 py-2.5 text-ink-100">
                      {c.name}
                      <div className="text-xs text-ink-500">{c.created_by ? `by ${c.created_by.name}` : null}</div>
                    </td>
                    <td className="px-3 py-2.5">
                      <Mono>{c.key_id}</Mono>
                    </td>
                    <td className="px-3 py-2.5">
                      <Badge tone={c.status === 'active' ? 'ok' : c.status === 'revoked' ? 'crimson' : 'warn'}>{c.status}</Badge>
                    </td>
                    <td className="hidden px-3 py-2.5 text-xs text-ink-300 md:table-cell">
                      {c.last_used_at ? `${formatRelative(c.last_used_at)} from ${c.last_used_ip ?? '?'}` : 'never'}
                    </td>
                    <td className="hidden px-3 py-2.5 text-xs text-ink-300 md:table-cell">{c.expires_at ? formatDateTime(c.expires_at) : 'never'}</td>
                    <td className="px-3 py-2.5 text-right">
                      {c.status === 'active' ? (
                        <Button
                          size="sm"
                          variant="danger"
                          loading={revoke.isPending && revoke.variables === c.id}
                          onClick={() => window.confirm(`Revoke "${c.name}"? Clients using it are rejected immediately.`) && revoke.mutate(c.id)}
                        >
                          Revoke
                        </Button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
