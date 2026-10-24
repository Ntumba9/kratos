import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { AuthLayout } from '../../components/layout/AuthLayout'
import { Alert, Button } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'

const outcomes: Record<string, { kind: 'success' | 'error' | 'info'; message: string }> = {
  verified: { kind: 'success', message: 'Email verified. Your account is active.' },
  'already-verified': { kind: 'info', message: 'This email address was already verified.' },
  invalid: { kind: 'error', message: 'This verification link is invalid or has expired. Request a new one below.' },
}

/**
 * Two roles: the landing page for the signed link in the verification email
 * (?status=…), and the holding page for signed-in but unverified users.
 */
export function VerifyEmailPage() {
  const [params] = useSearchParams()
  const { principal, refresh, logout } = useAuth()
  const navigate = useNavigate()
  const status = params.get('status')
  const outcome = status ? outcomes[status] : undefined
  const [resent, setResent] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  // After a successful verification, pick up the new state of the session.
  useEffect(() => {
    if (status === 'verified' || status === 'already-verified') void refresh()
  }, [status, refresh])

  const verified = principal?.user.email_verified ?? false

  const resend = async () => {
    setSending(true)
    setError(null)
    try {
      await api.post('/auth/email/verification-notification')
      setResent(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to reach KRATOS.')
    } finally {
      setSending(false)
    }
  }

  return (
    <AuthLayout title="Verify your email" subtitle={principal ? `Signed in as ${principal.user.email}` : undefined}>
      <div className="space-y-4">
        {outcome ? <Alert kind={outcome.kind}>{outcome.message}</Alert> : null}

        {verified ? (
          <Button className="w-full" onClick={() => navigate('/', { replace: true })}>
            Continue to KRATOS
          </Button>
        ) : principal ? (
          <>
            {!outcome ? (
              <p className="text-sm text-ink-300">
                We sent a verification link to your inbox. Verify your address to access organisation data. In local development, emails are captured by Mailpit.
              </p>
            ) : null}
            {resent ? <Alert kind="success">A new verification email has been queued.</Alert> : null}
            {error ? <Alert kind="error">{error}</Alert> : null}
            <Button variant="secondary" className="w-full" onClick={resend} loading={sending}>
              Resend verification email
            </Button>
            <Button variant="ghost" className="w-full" onClick={() => void logout().then(() => navigate('/login'))}>
              Sign out
            </Button>
          </>
        ) : (
          <Link to="/login" className="block text-center text-sm text-ink-300 hover:text-ink-100">
            Sign in to continue
          </Link>
        )}
      </div>
    </AuthLayout>
  )
}
