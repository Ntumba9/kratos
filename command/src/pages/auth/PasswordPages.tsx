import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { AuthLayout } from '../../components/layout/AuthLayout'
import { Alert, Button, Field } from '../../components/ui'
import { api, ApiError } from '../../lib/api'

const networkError = () => new ApiError(0, 'network_error', 'Unable to reach KRATOS. Check your connection.')

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await api.post('/auth/forgot-password', { email })
      setSent(true)
    } catch (err) {
      setError(err instanceof ApiError ? err : networkError())
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout title="Reset password" subtitle="We will email you a single-use reset link." footer={<Link to="/login" className="hover:text-ink-100">Back to sign in</Link>}>
      {sent ? (
        <Alert kind="success">If an account exists for that address, a reset link is on its way. The link expires in 60 minutes.</Alert>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
          <Field label="Email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} />
          <Button type="submit" loading={submitting} className="w-full">
            Send reset link
          </Button>
        </form>
      )}
    </AuthLayout>
  )
}

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const token = params.get('token') ?? ''
  const [email, setEmail] = useState(params.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await api.post('/auth/reset-password', { token, email, password, password_confirmation: confirmation })
      navigate('/login', { replace: true, state: { notice: 'Password updated. Sign in with your new password.' } })
    } catch (err) {
      setError(err instanceof ApiError ? err : networkError())
    } finally {
      setSubmitting(false)
    }
  }

  if (!token) {
    return (
      <AuthLayout title="Reset password">
        <Alert kind="error">This reset link is incomplete. Request a new one from the sign-in page.</Alert>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Choose a new password" subtitle="All other sessions will be signed out.">
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
        <Field label="Email" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} />
        <Field
          label="New password"
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={error?.field('password')}
          hint="At least 12 characters, with upper- and lower-case letters and a number."
        />
        <Field label="Confirm password" type="password" autoComplete="new-password" required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
        <Button type="submit" loading={submitting} className="w-full">
          Update password
        </Button>
      </form>
    </AuthLayout>
  )
}
