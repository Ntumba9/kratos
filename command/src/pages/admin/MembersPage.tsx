import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, UserPlus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, Select, Spinner } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { formatDateTime } from '../../lib/format'
import { useOrganisation } from '../../lib/organisation'
import { fetchMembers, queryKeys } from '../../lib/queries'
import type { Membership, RoleSlug } from '../../lib/types'

const PER_PAGE = 25

const ROLE_OPTIONS: Array<{ slug: RoleSlug; name: string }> = [
  { slug: 'org_admin', name: 'Organisation Administrator' },
  { slug: 'analyst', name: 'Security Analyst' },
  { slug: 'viewer', name: 'Read-Only Viewer' },
]

function AddMemberForm({ organisationId, onAdded }: { organisationId: string; onAdded: () => void }) {
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<RoleSlug>('analyst')
  const add = useMutation({
    mutationFn: () => api.post(`/organisations/${organisationId}/members`, { email, role }),
    onSuccess: () => {
      setEmail('')
      onAdded()
    },
  })
  const error = add.error instanceof ApiError ? add.error : null

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    add.mutate()
  }

  return (
    <form onSubmit={onSubmit} className="space-y-3">
      {add.isSuccess ? <Alert kind="success">Member added.</Alert> : null}
      {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
      <div className="grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-end">
        <Field label="Email of a registered user" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} required />
        <Select label="Role" value={role} onChange={(e) => setRole(e.target.value as RoleSlug)}>
          {ROLE_OPTIONS.map((option) => (
            <option key={option.slug} value={option.slug}>
              {option.name}
            </option>
          ))}
        </Select>
        <Button type="submit" loading={add.isPending} icon={<UserPlus className="size-4" />}>
          Add
        </Button>
      </div>
      <p className="text-xs text-ink-500">The user must already have a verified KRATOS account. Email invitations are planned for a later phase.</p>
    </form>
  )
}

function MemberRow({ member, organisationId, canManage, isSelf, onChanged }: { member: Membership; organisationId: string; canManage: boolean; isSelf: boolean; onChanged: () => void }) {
  const [error, setError] = useState<string | null>(null)
  const changeRole = useMutation({
    mutationFn: (role: RoleSlug) => api.patch(`/organisations/${organisationId}/members/${member.id}`, { role }),
    onSuccess: onChanged,
    onError: (err) => setError(err instanceof ApiError ? err.message : 'Request failed'),
  })
  const remove = useMutation({
    mutationFn: () => api.delete(`/organisations/${organisationId}/members/${member.id}`),
    onSuccess: onChanged,
    onError: (err) => setError(err instanceof ApiError ? err.message : 'Request failed'),
  })

  return (
    <tr className="border-t border-graphite-700 align-middle">
      <td className="px-4 py-3">
        <div className="text-sm text-ink-100">
          {member.user.name} {isSelf ? <Badge>you</Badge> : null}
        </div>
        <div className="text-xs text-ink-500">{member.user.email}</div>
        {error ? <div className="mt-1 text-xs text-crimson-300">{error}</div> : null}
      </td>
      <td className="px-4 py-3">
        {canManage ? (
          <Select
            aria-label={`Role for ${member.user.name}`}
            value={member.role.slug}
            disabled={changeRole.isPending}
            onChange={(e) => {
              setError(null)
              changeRole.mutate(e.target.value as RoleSlug)
            }}
          >
            {ROLE_OPTIONS.map((option) => (
              <option key={option.slug} value={option.slug}>
                {option.name}
              </option>
            ))}
          </Select>
        ) : (
          <Badge tone={member.role.slug === 'org_admin' ? 'crimson' : 'neutral'}>{member.role.name}</Badge>
        )}
      </td>
      <td className="hidden px-4 py-3 text-xs text-ink-500 md:table-cell">{formatDateTime(member.created_at)}</td>
      <td className="px-4 py-3 text-right">
        {canManage ? (
          <Button
            variant="danger"
            size="sm"
            loading={remove.isPending}
            icon={<Trash2 className="size-3.5" />}
            aria-label={`Remove ${member.user.name}`}
            onClick={() => {
              if (window.confirm(`Remove ${member.user.name} from this organisation? They will lose access immediately.`)) {
                setError(null)
                remove.mutate()
              }
            }}
          >
            Remove
          </Button>
        ) : null}
      </td>
    </tr>
  )
}

export function MembersPage() {
  const { active, can } = useOrganisation()
  const { principal } = useAuth()
  const organisationId = active!.organisation.id
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const canManage = can('members.manage')

  const members = useQuery({
    queryKey: queryKeys.members(organisationId, page, PER_PAGE),
    queryFn: () => fetchMembers(organisationId, page, PER_PAGE),
    placeholderData: keepPreviousData,
  })

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['organisations', organisationId, 'members'] })
  const pagination = members.data?.meta.pagination

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="Members" description="People with access to this organisation and their roles." />

      {canManage ? (
        <Card title="Add member">
          <AddMemberForm organisationId={organisationId} onAdded={refresh} />
        </Card>
      ) : null}

      <Card title={pagination ? `${pagination.total} member${pagination.total === 1 ? '' : 's'}` : 'Members'}>
        {members.isLoading ? (
          <Spinner />
        ) : members.isError ? (
          <Alert kind="error">Unable to load members.</Alert>
        ) : (
          <div className="-m-5 overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="text-[11px] tracking-[0.12em] text-ink-500 uppercase">
                  <th className="px-4 py-2.5 font-medium">User</th>
                  <th className="px-4 py-2.5 font-medium">Role</th>
                  <th className="hidden px-4 py-2.5 font-medium md:table-cell">Joined</th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {members.data?.data.map((member) => (
                  <MemberRow
                    key={member.id}
                    member={member}
                    organisationId={organisationId}
                    canManage={canManage}
                    isSelf={member.user.id === principal?.user.id}
                    onChanged={refresh}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
        {pagination && pagination.last_page > 1 ? (
          <div className="mt-8 flex items-center justify-end gap-2 text-xs text-ink-500">
            <span>
              Page {pagination.page} of {pagination.last_page}
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
