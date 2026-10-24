import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { useAuth } from '../lib/auth'
import { useOrganisation } from '../lib/organisation'
import { Spinner } from './ui'

function FullPageSpinner() {
  return (
    <div className="flex h-full items-center justify-center">
      <Spinner label="Establishing secure session" />
    </div>
  )
}

/** Signed-in, verified users only. These checks are UX; the API enforces the same rules. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { principal, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) return <FullPageSpinner />
  if (!principal) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  if (!principal.user.email_verified) return <Navigate to="/verify-email" replace />

  return <>{children}</>
}

export function RequireOrganisation({ children }: { children: ReactNode }) {
  const { active } = useOrganisation()
  if (!active) return <Navigate to="/organisations/new" replace />
  return <>{children}</>
}

/** Signed-out users only (login, register…). */
export function GuestOnly({ children }: { children: ReactNode }) {
  const { principal, isLoading } = useAuth()
  if (isLoading) return <FullPageSpinner />
  if (principal) return <Navigate to={principal.user.email_verified ? '/' : '/verify-email'} replace />
  return <>{children}</>
}

export function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = useOrganisation()
  if (!can(permission)) return <Navigate to="/" replace />
  return <>{children}</>
}
