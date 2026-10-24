import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Boxes, Plus, Search } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { CriticalityLabel } from '../components/eye/badges'
import { Alert, Badge, Button, Card, EmptyState, Field, Mono, PageHeader, Select, Spinner } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { formatDateTime, formatRelative } from '../lib/format'
import { useOrganisation } from '../lib/organisation'
import { fetchAssets, phase2Keys } from '../lib/queries'
import type { Asset, AssetType } from '../lib/types'

const TYPES: AssetType[] = ['server', 'application', 'database', 'network_device', 'endpoint', 'cloud_service']
const CRITICALITIES = ['low', 'medium', 'high', 'critical'] as const
const ENVIRONMENTS = ['production', 'staging', 'development'] as const

const label = (value: string) => value.replace('_', ' ')

function AddAssetForm({ organisationId, onDone }: { organisationId: string; onDone: () => void }) {
  const [form, setForm] = useState({ name: '', asset_type: 'server' as AssetType, hostname: '', ip_address: '', environment: 'production', criticality: 'medium', tags: '' })
  const create = useMutation({
    mutationFn: () =>
      api.post(`/organisations/${organisationId}/assets`, {
        name: form.name,
        asset_type: form.asset_type,
        hostname: form.hostname || null,
        ip_address: form.ip_address || null,
        environment: form.environment,
        criticality: form.criticality,
        tags: form.tags
          .split(',')
          .map((t) => t.trim().toLowerCase())
          .filter(Boolean),
      }),
    onSuccess: onDone,
  })
  const error = create.error instanceof ApiError ? create.error : null
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm((f) => ({ ...f, [key]: e.target.value }))

  return (
    <form
      className="space-y-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault()
        create.mutate()
      }}
    >
      {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Name" value={form.name} onChange={set('name')} error={error?.field('name')} placeholder="billing-api" required />
        <Select label="Type" value={form.asset_type} onChange={set('asset_type')}>
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {label(t)}
            </option>
          ))}
        </Select>
        <Select label="Environment" value={form.environment} onChange={set('environment')}>
          {ENVIRONMENTS.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </Select>
        <Select label="Criticality" value={form.criticality} onChange={set('criticality')}>
          {CRITICALITIES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </Select>
        <Field label="Hostname" value={form.hostname} onChange={set('hostname')} error={error?.field('hostname')} placeholder="billing.internal.example" />
        <Field label="IP address" value={form.ip_address} onChange={set('ip_address')} error={error?.field('ip_address')} placeholder="192.0.2.20" />
        <Field label="Tags" value={form.tags} onChange={set('tags')} error={error?.field('tags.0')} hint="Comma separated, e.g. pci, tier:1" className="sm:col-span-2" />
      </div>
      <div className="flex gap-2">
        <Button type="submit" loading={create.isPending}>
          Register asset
        </Button>
        <Button variant="ghost" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

function AssetRow({ asset, organisationId, canManage, onChanged }: { asset: Asset; organisationId: string; canManage: boolean; onChanged: () => void }) {
  const retire = useMutation({ mutationFn: () => api.delete(`/organisations/${organisationId}/assets/${asset.id}`), onSuccess: onChanged })

  return (
    <tr className="border-t border-graphite-700 align-middle">
      <td className="px-3 py-2.5">
        <div className="text-ink-100">{asset.name}</div>
        <div className="text-xs text-ink-500 capitalize">
          {label(asset.asset_type)} · {asset.environment}
        </div>
      </td>
      <td className="px-3 py-2.5">
        <CriticalityLabel value={asset.criticality} />
      </td>
      <td className="hidden px-3 py-2.5 md:table-cell">
        <Mono>{asset.hostname ?? '—'}</Mono>
        <div>
          <Mono>{asset.ip_address ?? ''}</Mono>
        </div>
      </td>
      <td className="hidden px-3 py-2.5 lg:table-cell">
        <div className="flex flex-wrap gap-1">
          {asset.tags.map((t) => (
            <Badge key={t}>{t}</Badge>
          ))}
        </div>
      </td>
      <td className="px-3 py-2.5 text-right">
        <Link to={`/events?asset_id=${asset.id}`} className="font-mono text-ink-100 tabular-nums hover:underline" title="Events in the last 24 hours">
          {asset.events_last_24h ?? 0}
        </Link>
      </td>
      <td className="px-3 py-2.5 text-xs whitespace-nowrap text-ink-300" title={formatDateTime(asset.last_seen_at)}>
        {asset.retired_at ? <Badge>retired</Badge> : asset.last_seen_at ? formatRelative(asset.last_seen_at) : <span className="text-ink-600">never</span>}
      </td>
      <td className="px-3 py-2.5 text-right">
        {canManage && !asset.retired_at ? (
          <Button
            size="sm"
            variant="ghost"
            icon={<Archive className="size-3.5" />}
            loading={retire.isPending}
            aria-label={`Retire ${asset.name}`}
            onClick={() => window.confirm(`Retire ${asset.name}? Its event history is kept.`) && retire.mutate()}
          >
            Retire
          </Button>
        ) : null}
      </td>
    </tr>
  )
}

export function InfrastructurePage() {
  const { active, can } = useOrganisation()
  const organisationId = active!.organisation.id
  const queryClient = useQueryClient()
  const canManage = can('assets.manage')
  const [adding, setAdding] = useState(false)
  const [draft, setDraft] = useState('')
  const [params, setParams] = useState<Record<string, string | number | undefined>>({ sort: 'name' })

  const assets = useQuery({
    queryKey: phase2Keys.assets(organisationId, params),
    queryFn: () => fetchAssets(organisationId, params),
    placeholderData: keepPreviousData,
  })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['organisations', organisationId, 'assets'] })

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader
        title="Infrastructure"
        description="Monitored assets. Events reference an asset by name, or are linked automatically by hostname during processing."
        actions={
          canManage && !adding ? (
            <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>
              Register asset
            </Button>
          ) : null
        }
      />

      {adding ? (
        <Card title="Register asset">
          <AddAssetForm
            organisationId={organisationId}
            onDone={() => {
              setAdding(false)
              refresh()
            }}
          />
        </Card>
      ) : null}

      <Card>
        <form
          className="mb-5 flex flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            setParams((p) => ({ ...p, q: draft.trim() || undefined }))
          }}
        >
          <div className="relative min-w-56 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-600" aria-hidden />
            <input
              aria-label="Search name or hostname"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              placeholder="Search name or hostname…"
              className="h-9 w-full rounded-md border border-graphite-600 bg-obsidian-900 pr-3 pl-9 text-sm text-ink-100 placeholder:text-ink-600 focus:border-crimson-500 focus:outline-none"
            />
          </div>
          <Select aria-label="Filter by criticality" className="w-40" value={String(params.criticality ?? '')} onChange={(e) => setParams((p) => ({ ...p, criticality: e.target.value || undefined }))}>
            <option value="">Any criticality</option>
            {CRITICALITIES.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </Select>
          <label className="flex items-center gap-2 text-xs text-ink-500">
            <input
              type="checkbox"
              className="size-3.5 accent-crimson-500"
              checked={Boolean(params.include_retired)}
              onChange={(e) => setParams((p) => ({ ...p, include_retired: e.target.checked ? 1 : undefined }))}
            />
            Include retired
          </label>
        </form>

        {assets.isLoading ? (
          <Spinner />
        ) : assets.isError ? (
          <Alert kind="error">Unable to load assets.</Alert>
        ) : !assets.data?.data.length ? (
          <EmptyState icon={<Boxes className="size-8" />} title="No assets registered">
            Register the servers, applications and services whose security telemetry KRATOS should track.
          </EmptyState>
        ) : (
          <div className="-mx-5 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-[11px] tracking-[0.12em] text-ink-500 uppercase">
                  <th className="px-3 py-2 font-medium">Asset</th>
                  <th className="px-3 py-2 font-medium">Criticality</th>
                  <th className="hidden px-3 py-2 font-medium md:table-cell">Address</th>
                  <th className="hidden px-3 py-2 font-medium lg:table-cell">Tags</th>
                  <th className="px-3 py-2 text-right font-medium">Events · 24 h</th>
                  <th className="px-3 py-2 font-medium">Last seen</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody>
                {assets.data.data.map((asset) => (
                  <AssetRow key={asset.id} asset={asset} organisationId={organisationId} canManage={canManage} onChanged={refresh} />
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="mt-5 text-xs text-ink-600">Agent status, heartbeats and resource metrics arrive with KRATOS AGENT in Phase 5.</p>
      </Card>
    </div>
  )
}
