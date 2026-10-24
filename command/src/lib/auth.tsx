import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useMemo, type ReactNode } from 'react'
import { api, ApiError } from './api'
import type { ApiEnvelope, CurrentUser } from './types'

export const ME_QUERY_KEY = ['auth', 'me'] as const

async function fetchMe(): Promise<CurrentUser | null> {
  try {
    const { data } = await api.get<ApiEnvelope<CurrentUser>>('/auth/me')
    return data
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null
    throw error
  }
}

export interface LoginInput {
  email: string
  password: string
  remember?: boolean
}

export interface RegisterInput {
  name: string
  email: string
  password: string
  password_confirmation: string
  organisation_name: string
}

interface AuthContextValue {
  principal: CurrentUser | null
  isLoading: boolean
  login: (input: LoginInput) => Promise<CurrentUser>
  register: (input: RegisterInput) => Promise<CurrentUser>
  logout: () => Promise<void>
  refresh: () => Promise<unknown>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const me = useQuery({ queryKey: ME_QUERY_KEY, queryFn: fetchMe, staleTime: 60_000, retry: false })

  const loginMutation = useMutation({
    mutationFn: async (input: LoginInput) => (await api.post<ApiEnvelope<CurrentUser>>('/auth/login', input)).data,
    onSuccess: (principal) => {
      queryClient.clear()
      queryClient.setQueryData(ME_QUERY_KEY, principal)
    },
  })

  const registerMutation = useMutation({
    mutationFn: async (input: RegisterInput) => (await api.post<ApiEnvelope<CurrentUser>>('/auth/register', input)).data,
    onSuccess: (principal) => queryClient.setQueryData(ME_QUERY_KEY, principal),
  })

  const logoutMutation = useMutation({
    mutationFn: () => api.post<void>('/auth/logout'),
    onSettled: () => {
      // Drop every cached tenant response so nothing leaks to the next user.
      queryClient.clear()
      queryClient.setQueryData(ME_QUERY_KEY, null)
    },
  })

  const refresh = useCallback(() => queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY }), [queryClient])

  const value = useMemo<AuthContextValue>(
    () => ({
      principal: me.data ?? null,
      isLoading: me.isLoading,
      login: loginMutation.mutateAsync,
      register: registerMutation.mutateAsync,
      logout: async () => {
        await logoutMutation.mutateAsync()
      },
      refresh,
    }),
    [me.data, me.isLoading, loginMutation.mutateAsync, registerMutation.mutateAsync, logoutMutation, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
