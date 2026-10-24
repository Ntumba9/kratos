import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, KeyRound, LogOut, MonitorSmartphone } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, Mono, PageHeader, Spinner } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { describeUserAgent, formatDateTime, formatRelative } from '../../lib/format'
import { queryKeys } from '../../lib/queries'
import type { AccessToken, ApiEnvelope, SessionInfo } from '../../lib/types'

function ChangePassword() {
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const queryClient = useQueryClient()
  const change = useMutation({
    mutationFn: () => api.put('/auth/password', { current_password: current, password, password_confirmation: confirmation }),
    onSuccess: () => {
      setCurrent('')
      setPassword('')
      setConfirmation('')
      void queryClient.invalidateQueries({ queryKey: queryKeys.sessions })
    },
  })
  const error = change.error instanceof ApiError ? change.error : null

  return (
    <form
      className="max-w-md space-y-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault()
        change.mutate()
      }}
    >
      {change.isSuccess ? <Alert kind="success">Password changed. Your other sessions were signed out.</Alert> : null}
      {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
      <Field label="Current password" type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} error={error?.field('current_password')} />
      <Field
        label="New password"
        type="password"
        autoComplete="new-password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        error={error?.field('password')}
        hint="At least 12 characters, with upper- and lower-case letters and a number."
      />
      <Field label="Confirm new password" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
      <Button type="submit" loading={change.isPending}>
        Change password
      </Button>
    </form>
  )
}

function Sessions() {
  const queryClient = useQueryClient()
  const sessions = useQuery({ queryKey: queryKeys.sessions, queryFn: async () => (await api.get<ApiEnvelope<SessionInfo[]>>('/auth/sessions')).data })
  const invalidate = () => void queryClient.invalidateQueries({ queryKey: queryKeys.sessions })
  const revoke = useMutation({ mutationFn: (handle: string) => api.delete(`/auth/sessions/${handle}`), onSuccess: invalidate })
  const revokeOthers = useMutation({ mutationFn: () => api.delete('/auth/sessions'), onSuccess: invalidate })

  if (sessions.isLoading) return <Spinner />
  if (sessions.isError) return <Alert kind="error">Unable to load sessions.</Alert>

  const others = sessions.data?.filter((s) => !s.current).length ?? 0

  return (
    <div className="space-y-4">
      <ul className="divide-y divide-graphite-700">
        {sessions.data?.map((session) => (
          <li key={session.id} className="flex items-center justify-between gap-4 py-3">
            <div className="flex min-w-0 items-center gap-3">
              <MonitorSmartphone className="size-5 shrink-0 text-ink-600" aria-hidden />
              <div className="min-w-0">
                <div className="flex items-center gap-2 text-sm text-ink-100">
                  {describeUserAgent(session.user_agent)} {session.current ? <Badge tone="ok">this session</Badge> : null}
                </div>
                <div className="text-xs text-ink-500">
                  <Mono>{session.ip_address ?? 'unknown IP'}</Mono> · active {formatRelative(session.last_active_at)}
                </div>
              </div>
            </div>
            {!session.current ? (
              <Button size="sm" variant="danger" loading={revoke.isPending && revoke.variables === session.id} onClick={() => revoke.mutate(session.id)}>
                Revoke
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      {others > 0 ? (
        <Button variant="secondary" size="sm" icon={<LogOut className="size-3.5" />} loading={revokeOthers.isPending} onClick={() => revokeOthers.mutate()}>
          Sign out all other sessions
        </Button>
      ) : null}
    </div>
  )
}

function AccessTokens() {
  const queryClient = useQueryClient()
  const tokens = useQuery({ queryKey: queryKeys.tokens, queryFn: async () => (await api.get<ApiEnvelope<AccessToken[]>>('/auth/tokens')).data })
  const [name, setName] = useState('')
  const [write, setWrite] = useState(false)
  const [days, setDays] = useState('90')
  const [plainText, setPlainText] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: queryKeys.tokens })
  const create = useMutation({
    mutationFn: async () =>
      (
        await api.post<ApiEnvelope<{ token: AccessToken; plain_text_token: string }>>('/auth/tokens', {
          name,
          abilities: write ? ['read', 'write'] : ['read'],
          expires_in_days: Number(days),
        })
      ).data,
    onSuccess: (result) => {
      setPlainText(result.plain_text_token)
      setCopied(false)
      setName('')
      invalidate()
    },
  })
  const revoke = useMutation({ mutationFn: (id: string) => api.delete(`/auth/tokens/${id}`), onSuccess: invalidate })
  const error = create.error instanceof ApiError ? create.error : null

  return (
    <div className="space-y-5">
      {plainText ? (
        <Alert kind="warning">
          <p className="mb-2 font-medium">Copy this token now — it will not be shown again.</p>
          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 truncate rounded border border-graphite-600 bg-obsidian-950 px-2 py-1.5 font-mono text-xs text-ink-100">{plainText}</code>
            <Button
              size="sm"
              variant="secondary"
              icon={<Copy className="size-3.5" />}
              onClick={() => {
                void navigator.clipboard?.writeText(plainText).then(() => setCopied(true))
              }}
            >
              {copied ? 'Copied' : 'Copy'}
            </Button>
          </div>
        </Alert>
      ) : null}

      <form
        className="grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end"
        onSubmit={(e) => {
          e.preventDefault()
          create.mutate()
        }}
      >
        <Field label="Token name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. reporting script" error={error?.field('name')} />
        <Field label="Expires (days)" type="number" min={1} max={365} value={days} onChange={(e) => setDays(e.target.value)} error={error?.field('expires_in_days')} />
        <Button type="submit" loading={create.isPending} icon={<KeyRound className="size-4" />}>
          Create
        </Button>
        <label className="flex items-center gap-2 text-sm text-ink-500 sm:col-span-3">
          <input type="checkbox" checked={write} onChange={(e) => setWrite(e.target.checked)} className="size-3.5 accent-crimson-500" />
          Allow write access (otherwise the token is read-only)
        </label>
      </form>
      {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}

      {tokens.isLoading ? (
        <Spinner />
      ) : !tokens.data?.length ? (
        <EmptyState title="No active access tokens" />
      ) : (
        <ul className="divide-y divide-graphite-700">
          {tokens.data.map((token) => (
            <li key={token.id} className="flex items-center justify-between gap-4 py-3">
              <div className="min-w-0">
                <div className="flex items-center gap-2 text-sm text-ink-100">
                  {token.name}
                  {token.abilities.map((ability) => (
                    <Badge key={ability} tone={ability === 'write' ? 'warn' : 'neutral'}>
                      {ability}
                    </Badge>
                  ))}
                </div>
                <div className="text-xs text-ink-500">
                  Expires {formatDateTime(token.expires_at)} · last used {formatRelative(token.last_used_at)}
                </div>
              </div>
              <Button size="sm" variant="danger" loading={revoke.isPending && revoke.variables === token.id} onClick={() => revoke.mutate(token.id)}>
                Revoke
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function SecurityPage() {
  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="Account security" description="Password, active sessions, and personal access tokens for API use." />
      <div className="grid gap-6 xl:grid-cols-2">
        <Card title="Active sessions">
          <Sessions />
        </Card>
        <Card title="Password">
          <ChangePassword />
        </Card>
      </div>
      <Card title="Personal access tokens">
        <AccessTokens />
      </Card>
    </div>
  )
}
