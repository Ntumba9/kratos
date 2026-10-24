import { Route, Routes } from 'react-router'
import { AppShell } from './components/layout/AppShell'
import { GuestOnly, RequireAuth, RequireOrganisation, RequirePermission } from './components/RouteGuards'
import { SecurityPage } from './pages/account/SecurityPage'
import { AuditLogPage } from './pages/admin/AuditLogPage'
import { IngestionKeysPage } from './pages/admin/IngestionKeysPage'
import { MembersPage } from './pages/admin/MembersPage'
import { OrganisationSettingsPage } from './pages/admin/OrganisationSettingsPage'
import { LoginPage } from './pages/auth/LoginPage'
import { ForgotPasswordPage, ResetPasswordPage } from './pages/auth/PasswordPages'
import { RegisterPage } from './pages/auth/RegisterPage'
import { VerifyEmailPage } from './pages/auth/VerifyEmailPage'
import { EventsPage } from './pages/EventsPage'
import { InfrastructurePage } from './pages/InfrastructurePage'
import { IntelligencePage } from './pages/IntelligencePage'
import { NewOrganisationPage, NotFoundPage, PlannedModulePage } from './pages/MiscPages'
import { OverviewPage } from './pages/OverviewPage'

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<GuestOnly><LoginPage /></GuestOnly>} />
      <Route path="/register" element={<GuestOnly><RegisterPage /></GuestOnly>} />
      <Route path="/forgot-password" element={<GuestOnly><ForgotPasswordPage /></GuestOnly>} />
      <Route path="/reset-password" element={<ResetPasswordPage />} />
      <Route path="/verify-email" element={<VerifyEmailPage />} />
      <Route path="/organisations/new" element={<RequireAuth><NewOrganisationPage /></RequireAuth>} />

      <Route
        element={
          <RequireAuth>
            <RequireOrganisation>
              <AppShell />
            </RequireOrganisation>
          </RequireAuth>
        }
      >
        <Route index element={<OverviewPage />} />
        <Route path="events" element={<RequirePermission permission="events.view"><EventsPage /></RequirePermission>} />
        <Route path="intelligence" element={<RequirePermission permission="detections.view"><IntelligencePage /></RequirePermission>} />
        <Route
          path="incidents"
          element={<PlannedModulePage title="Incidents" phase={4} summary="Correlated incidents with severity, assignment, investigation timeline, evidence viewer and a human-approved response workflow." />}
        />
        <Route path="infrastructure" element={<RequirePermission permission="assets.view"><InfrastructurePage /></RequirePermission>} />
        <Route path="admin/organisation" element={<RequirePermission permission="organisation.view"><OrganisationSettingsPage /></RequirePermission>} />
        <Route path="admin/members" element={<RequirePermission permission="members.view"><MembersPage /></RequirePermission>} />
        <Route path="admin/ingestion-keys" element={<RequirePermission permission="api_credentials.manage"><IngestionKeysPage /></RequirePermission>} />
        <Route path="admin/audit-log" element={<RequirePermission permission="audit_logs.view"><AuditLogPage /></RequirePermission>} />
        <Route path="account/security" element={<SecurityPage />} />
      </Route>

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}
