<?php

namespace App\Domain\Audit;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;

/**
 * Translates framework authentication events into audit records so that no
 * controller can forget to audit a security-relevant authentication change.
 */
final class AuthEventSubscriber
{
    /**
     * Resolved per event rather than injected: the logger depends on the
     * current request, and subscribers are instantiated once at boot.
     */
    private function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    public function onLogin(Login $event): void
    {
        $user = $this->user($event->user);
        $this->audit()->record('auth.login.succeeded', $user, ['remember' => $event->remember], actor: $user);
    }

    public function onFailed(Failed $event): void
    {
        // The attempted identifier is recorded so brute-force patterns are
        // visible; the attempted password never is.
        $this->audit()->record('auth.login.failed', $event->user instanceof User ? $event->user : null, [
            'email' => isset($event->credentials['email']) ? mb_strtolower((string) $event->credentials['email']) : null,
            'known_user' => $event->user !== null,
        ]);
    }

    public function onLockout(Lockout $event): void
    {
        $this->audit()->record('auth.login.locked_out', null, [
            'email' => mb_strtolower((string) $event->request->input('email')),
        ]);
    }

    public function onLogout(Logout $event): void
    {
        $user = $this->user($event->user);
        if ($user !== null) {
            $this->audit()->record('auth.logout', $user, actor: $user);
        }
    }

    public function onRegistered(Registered $event): void
    {
        $user = $this->user($event->user);
        $this->audit()->record('auth.registered', $user, actor: $user);
    }

    public function onVerified(Verified $event): void
    {
        $user = $this->user($event->user);
        $this->audit()->record('auth.email.verified', $user, actor: $user);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $user = $this->user($event->user);
        $this->audit()->record('auth.password.reset', $user, actor: $user);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Logout::class => 'onLogout',
            Registered::class => 'onRegistered',
            Verified::class => 'onVerified',
            PasswordReset::class => 'onPasswordReset',
        ];
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
