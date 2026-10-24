import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { useAuth } from './auth'
import type { MembershipSummary } from './types'

const STORAGE_KEY = 'kratos.activeOrganisation'

interface OrganisationContextValue {
  /** The membership for the organisation currently being viewed. */
  active: MembershipSummary | null
  memberships: MembershipSummary[]
  select: (organisationId: string) => void
  /** UI hint only — the API enforces every permission server-side. */
  can: (permission: string) => boolean
}

const OrganisationContext = createContext<OrganisationContextValue | null>(null)

function readStored(): string | null {
  try {
    return window.localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

export function OrganisationProvider({ children }: { children: ReactNode }) {
  const { principal } = useAuth()
  const memberships = useMemo(() => principal?.memberships ?? [], [principal])
  const [selectedId, setSelectedId] = useState<string | null>(readStored)

  const active = memberships.find((m) => m.organisation.id === selectedId) ?? memberships[0] ?? null

  const select = useCallback((organisationId: string) => {
    setSelectedId(organisationId)
    try {
      window.localStorage.setItem(STORAGE_KEY, organisationId)
    } catch {
      // Storage unavailable (private mode); selection lasts for this tab only.
    }
  }, [])

  const can = useCallback((permission: string) => active?.permissions.includes(permission) ?? false, [active])

  const value = useMemo(() => ({ active, memberships, select, can }), [active, memberships, select, can])

  return <OrganisationContext.Provider value={value}>{children}</OrganisationContext.Provider>
}

export function useOrganisation(): OrganisationContextValue {
  const context = useContext(OrganisationContext)
  if (!context) throw new Error('useOrganisation must be used inside <OrganisationProvider>')
  return context
}
