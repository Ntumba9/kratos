import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { AuthLayout } from '../../components/layout/AuthLayout'
import { Alert, Button, Field } from '../../components/ui'
import { ApiError } from '../../lib/api'
import { useAuth, type RegisterInput } from '../../lib/auth'

const empty: RegisterInput = { name: '', email: '', password: '', password_confirmation: '', organisation_name: '' }

export function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState<RegisterInput>(empty)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const set = (key: keyof RegisterInput) => (event: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [key]: event.target.value }))

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await register(form)
      navigate('/verify-email', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'network_error', 'Unable to reach KRATOS. Check your connection.'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Create your workspace"
      subtitle="You will be the administrator of a new organisation."
      footer={
        <>
          Already have an account?{' '}
          <Link to="/login" className="text-ink-100 underline-offset-4 hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {error && !error.details ? <Alert kind="error">{error.message}</Alert> : null}
        <Field label="Full name" autoComplete="name" required value={form.name} onChange={set('name')} error={error?.field('name')} />
        <Field label="Work email" type="email" autoComplete="email" required value={form.email} onChange={set('email')} error={error?.field('email')} />
        <Field
          label="Organisation"
          autoComplete="organization"
          required
          value={form.organisation_name}
          onChange={set('organisation_name')}
          error={error?.field('organisation_name')}
        />
        <Field
          label="Password"
          type="password"
          autoComplete="new-password"
          required
          value={form.password}
          onChange={set('password')}
          error={error?.field('password')}
          hint="At least 12 characters, with upper- and lower-case letters and a number."
        />
        <Field
          label="Confirm password"
          type="password"
          autoComplete="new-password"
          required
          value={form.password_confirmation}
          onChange={set('password_confirmation')}
        />
        <Button type="submit" loading={submitting} className="w-full">
          Create account
        </Button>
      </form>
    </AuthLayout>
  )
}
