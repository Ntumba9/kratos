<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled maintenance (run by the `scheduler` service)
|--------------------------------------------------------------------------
|
| Retention for Phase 1 data; see docs/database.md. Includes security
| event retention (Phase 2).
|
*/

// Expired personal access tokens (kept 24h after expiry for audit context).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer()->withoutOverlapping();

// Dead-lettered jobs older than 30 days.
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer()->withoutOverlapping();

// Security event retention per organisation (organisations.event_retention_days).
Schedule::command('kratos:events:prune')->dailyAt('03:15')->onOneServer()->withoutOverlapping();

// Expired password reset tokens.
Schedule::command('auth:clear-resets')->hourly()->onOneServer();
