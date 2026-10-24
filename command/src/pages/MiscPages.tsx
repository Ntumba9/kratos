import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Construction } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { AuthLayout } from '../components/layout/AuthLayout'
import { Alert, Button, Card, EmptyState, Field, PageHeader } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { ME_QUERY_KEY, useAuth } from '../lib/auth'
import { useOrganisation } from '../lib/organisation'
import type { ApiEnvelope, Organisation } from '../lib/types'

/**
 * Honest placeholder for modules scheduled in later phases. Shows what the
 * module will do and when — never sample data.
 */
export function PlannedModulePage({ title, phase, summary }: { title: string; phase: number; summary: string }) {
  return (
    <div className="animate-fade-in">
      <PageHeader title={title} />
      <Card>
        <EmptyState icon={<Construction className="size-8" />} title={`Scheduled for Phase ${phase}`}>
          {summary}
        </EmptyState>
      </Card>
    </div>
  )
}

export function NewOrganisationPage() {
  const { principal } = useAuth()
  const { select } = useOrganisation()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [name, setName] = useState('')

  const create = useMutation({
    mutationFn: async () => (await api.post<ApiEnvelope<Organisation>>('/organisations', { name })).data,
    onSuccess: async (organisation) => {
      await queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY })
      select(organisation.id)
      navigate('/', { replace: true })
    },
  })
  const error = create.error instanceof ApiError ? create.error : null
  const hasOrganisations = (principal?.memberships.length ?? 0) > 0

  return (
    <AuthLayout
      title="New organisation"
      subtitle="Each organisation is an isolated tenant. You will be its administrator."
      footer={hasOrganisations ? <Link to="/" className="hover:text-ink-100">Cancel</Link> : undefined}
    >
      <form
        className="space-y-4"
        onSubmit={(e: FormEvent) => {
          e.preventDefault()
          create.mutate()
        }}
      >
        {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
        <Field label="Organisation name" value={name} onChange={(e) => setName(e.target.value)} error={error?.field('name')} required />
        <Button type="submit" className="w-full" loading={create.isPending}>
          Create organisation
        </Button>
      </form>
    </AuthLayout>
  )
}

export function NotFoundPage() {
  return (
    <div className="flex h-full items-center justify-center">
      <EmptyState title="Page not found">
        <Link to="/" className="text-ink-300 hover:text-ink-100">
          Return to overview
        </Link>
      </EmptyState>
    </div>
  )
}
