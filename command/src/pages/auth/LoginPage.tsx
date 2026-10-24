import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { AuthLayout } from '../../components/layout/AuthLayout'
import { Alert, Button, Field } from '../../components/ui'
import { ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const state = location.state as { from?: string; notice?: string } | null
  const from = state?.from ?? '/'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      const principal = await login({ email, password, remember })
      navigate(principal.user.email_verified ? from : '/verify-email', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'network_error', 'Unable to reach KRATOS. Check your connection.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Sign in"
      subtitle="Access the security operations console."
      footer={
        <>
          New to KRATOS?{' '}
          <Link to="/register" className="text-ink-100 underline-offset-4 hover:underline">
            Create an account
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {state?.notice && !error ? <Alert kind="success">{state.notice}</Alert> : null}
        {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
        <Field label="Email" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} />
        <Field
          label="Password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={error?.field('password')}
        />
        <div className="flex items-center justify-between text-sm">
          <label className="flex items-center gap-2 text-ink-500">
            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="size-3.5 accent-crimson-500" />
            Keep me signed in
          </label>
          <Link to="/forgot-password" className="text-ink-500 hover:text-ink-100">
            Forgot password?
          </Link>
        </div>
        <Button type="submit" loading={submitting} className="w-full">
          Sign in
        </Button>
      </form>
    </AuthLayout>
  )
}
