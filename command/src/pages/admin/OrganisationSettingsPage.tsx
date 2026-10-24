import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Alert, Button, Card, Field, Mono, PageHeader, Spinner } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { ME_QUERY_KEY } from '../../lib/auth'
import { formatDateTime } from '../../lib/format'
import { useOrganisation } from '../../lib/organisation'
import { fetchOrganisation, queryKeys } from '../../lib/queries'
import type { ApiEnvelope, Organisation } from '../../lib/types'

function SettingsForm({ organisation, editable }: { organisation: Organisation; editable: boolean }) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(organisation.name)
  const [retention, setRetention] = useState(String(organisation.event_retention_days))
  const [saved, setSaved] = useState(false)

  const update = useMutation({
    mutationFn: async () =>
      (await api.patch<ApiEnvelope<Organisation>>(`/organisations/${organisation.id}`, { name, event_retention_days: Number(retention) })).data,
    onSuccess: (updated) => {
      setSaved(true)
      queryClient.setQueryData(queryKeys.organisation(organisation.id), updated)
      void queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY })
    },
  })
  const error = update.error instanceof ApiError ? update.error : null

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    setSaved(false)
    update.mutate()
  }

  return (
    <form onSubmit={onSubmit} className="max-w-lg space-y-4">
      {saved ? <Alert kind="success">Settings saved and recorded in the audit log.</Alert> : null}
      {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
      <Field label="Organisation name" value={name} onChange={(e) => setName(e.target.value)} disabled={!editable} error={error?.field('name')} />
      <Field
        label="Security event retention (days)"
        type="number"
        min={1}
        max={3650}
        value={retention}
        onChange={(e) => setRetention(e.target.value)}
        disabled={!editable}
        error={error?.field('event_retention_days')}
        hint="Telemetry older than this is purged by the retention job (applies from Phase 2)."
      />
      {editable ? (
        <Button type="submit" loading={update.isPending}>
          Save changes
        </Button>
      ) : (
        <p className="text-xs text-ink-500">Only Organisation Administrators can change these settings.</p>
      )}
    </form>
  )
}

export function OrganisationSettingsPage() {
  const { active, can } = useOrganisation()
  const organisationId = active!.organisation.id
  const organisation = useQuery({ queryKey: queryKeys.organisation(organisationId), queryFn: () => fetchOrganisation(organisationId) })

  return (
    <div className="animate-fade-in">
      <PageHeader title="Organisation" description="Profile and data-handling settings for this tenant." />

      {organisation.isLoading ? (
        <Spinner />
      ) : organisation.data ? (
        <div className="grid gap-6 lg:grid-cols-3">
          <Card title="Settings" className="lg:col-span-2">
            {/* Keyed so switching organisation re-initialises the form. */}
            <SettingsForm key={organisation.data.id} organisation={organisation.data} editable={can('organisation.update')} />
          </Card>
          <Card title="Identifiers">
            <dl className="space-y-3 text-sm">
              <div>
                <dt className="text-xs text-ink-500">Organisation ID</dt>
                <dd>
                  <Mono>{organisation.data.id}</Mono>
                </dd>
              </div>
              <div>
                <dt className="text-xs text-ink-500">Slug</dt>
                <dd>
                  <Mono>{organisation.data.slug}</Mono>
                </dd>
              </div>
              <div>
                <dt className="text-xs text-ink-500">Created</dt>
                <dd className="text-ink-300">{formatDateTime(organisation.data.created_at)}</dd>
              </div>
            </dl>
          </Card>
        </div>
      ) : (
        <Alert kind="error">Unable to load organisation.</Alert>
      )}
    </div>
  )
}
