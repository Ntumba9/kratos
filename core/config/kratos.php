<?php

/*
|--------------------------------------------------------------------------
| KRATOS platform configuration
|--------------------------------------------------------------------------
|
| The RBAC catalogue below is the source of truth for roles and permissions.
| `Database\Seeders\RbacSeeder` synchronises it into the roles, permissions
| and permission_role tables, so the catalogue is versioned in code and
| still queryable in SQL. Permissions for features delivered in later phases
| are declared now so that role definitions stay stable.
|
*/

return [

    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:8080'), '/'),

    'demo_mode' => (bool) env('KRATOS_DEMO_MODE', false),

    'database' => [
        // Least-privilege role used by the running application. Migrations
        // revoke UPDATE/DELETE on append-only tables from this role.
        'app_role' => env('DB_APP_ROLE', 'kratos_app'),
    ],

    'tokens' => [
        'default_expiry_days' => 90,
        'max_expiry_days' => 365,
        'abilities' => ['read', 'write'],
    ],

    /*
    |--------------------------------------------------------------------------
    | KRATOS EYE — event ingestion
    |--------------------------------------------------------------------------
    */
    'ingestion' => [
        // Accepted clock skew for signed requests (ADR-0006).
        'signature_tolerance_seconds' => 300,
        'max_body_bytes' => 262_144,          // 256 KiB per request
        'max_batch_size' => 100,
        'rate_limit_per_minute' => (int) env('KRATOS_INGEST_RATE_LIMIT', 600), // per credential
        // Events may arrive late, but not from the future beyond small skew.
        'max_future_skew_seconds' => 300,
        'metadata' => [
            'max_keys' => 64,
            'max_depth' => 4,
            'max_bytes' => 16_384,
            'max_string_length' => 2_048,
        ],
        'queue' => 'events',
        // Retry policy for ProcessSecurityEvent (exponential backoff, seconds).
        'tries' => 5,
        'backoff' => [10, 30, 90, 270],
    ],

    /*
    |--------------------------------------------------------------------------
    | KRATOS MIND — intelligence service (ADR-0007)
    |--------------------------------------------------------------------------
    */
    'mind' => [
        'url' => rtrim((string) env('MIND_URL', 'http://mind:8000'), '/'),
        'shared_secret' => env('MIND_SHARED_SECRET'),
        'timeout_seconds' => (float) env('MIND_TIMEOUT', 5),
        'connect_timeout_seconds' => 2.0,
        // While MIND is unreachable, jobs release themselves with backoff
        // (not counted as failures) for up to this long.
        'unavailable_retry_window_minutes' => 360,
        'unavailable_max_backoff_seconds' => 300,
    ],

    /*
    | Built-in detection rules (logic lives in KRATOS MIND, mind/kratos_mind/rules.py).
    | Organisations may disable a rule or adjust its parameters within [min, max];
    | defaults here must equal DEFAULT_PARAMETERS in the Python rules module (tested).
    */
    'detection_rules' => [
        'brute_force_source' => [
            'category' => 'credential_attack',
            'name' => 'Brute force from a single source',
            'description' => 'Many failed logins from one IP address in a short window.',
            'severity' => 'high',
            'parameters' => ['failures_15m' => ['default' => 10, 'min' => 3, 'max' => 1000]],
        ],
        'credential_stuffing' => [
            'category' => 'credential_attack',
            'name' => 'Credential stuffing',
            'description' => 'One IP address trying many different usernames.',
            'severity' => 'high',
            'parameters' => ['distinct_users_1h' => ['default' => 5, 'min' => 2, 'max' => 1000]],
        ],
        'targeted_account_attack' => [
            'category' => 'credential_attack',
            'name' => 'Targeted account attack',
            'description' => 'Repeated failed logins against one account, from any source.',
            'severity' => 'medium',
            'parameters' => ['failures_15m' => ['default' => 8, 'min' => 3, 'max' => 1000]],
        ],
        'lockout_after_failures' => [
            'category' => 'credential_attack',
            'name' => 'Account lockout after failures',
            'description' => 'An account was locked out following repeated failures.',
            'severity' => 'high',
            'parameters' => ['failures_15m' => ['default' => 3, 'min' => 1, 'max' => 100]],
        ],
        'permission_probing' => [
            'category' => 'reconnaissance',
            'name' => 'Permission probing',
            'description' => 'A user repeatedly denied access to resources.',
            'severity' => 'medium',
            'parameters' => ['denials_1h' => ['default' => 10, 'min' => 2, 'max' => 1000]],
        ],
        'api_volume_spike' => [
            'category' => 'abuse',
            'name' => 'Unusual API request volume',
            'description' => 'Request rate reported by an API anomaly or rate-limit event above a threshold.',
            'severity' => 'medium',
            'parameters' => ['requests_per_second' => ['default' => 10, 'min' => 1, 'max' => 100000]],
        ],
        'off_hours_privileged_change' => [
            'category' => 'privilege_misuse',
            'name' => 'Off-hours privileged change',
            'description' => 'Privilege escalation or configuration change outside business hours (UTC) or at weekends.',
            'severity' => 'medium',
            'parameters' => [
                'business_start_hour' => ['default' => 7, 'min' => 0, 'max' => 23],
                'business_end_hour' => ['default' => 19, 'min' => 1, 'max' => 24],
            ],
        ],
        'activity_surge' => [
            'category' => 'anomalous_activity',
            'name' => 'Asset activity surge',
            'description' => 'An asset\'s event volume in the last hour far above its 7-day hourly baseline.',
            'severity' => 'medium',
            'parameters' => [
                'z_threshold' => ['default' => 4, 'min' => 2, 'max' => 50],
                'min_events_1h' => ['default' => 20, 'min' => 1, 'max' => 100000],
                // No baseline, no surge: a new asset's first burst is not judged.
                'min_baseline_mean' => ['default' => 0.5, 'min' => 0, 'max' => 100000],
            ],
        ],
        'abnormal_timing' => [
            'category' => 'anomalous_activity',
            'name' => 'Abnormal event timing',
            'description' => 'An event type occurring at an hour when it is rarely seen in this organisation.',
            'severity' => 'low',
            'parameters' => [
                'max_hour_share' => ['default' => 0.004, 'min' => 0.0001, 'max' => 0.2],
                'min_type_events_7d' => ['default' => 200, 'min' => 10, 'max' => 1000000],
            ],
        ],
        'critical_signature' => [
            'category' => 'compromise',
            'name' => 'Critical signature reported',
            'description' => 'Malware, suspicious process or data exfiltration reported by a sensor.',
            'severity' => 'critical',
            'parameters' => [],
        ],
    ],

    /*
    | Known event types. Each lists its typed metadata fields; metadata keys
    | outside the list are still accepted (subject to the global limits) so
    | integrations can add context, but listed keys must have the listed type.
    | Organisation-specific types use the `custom.` prefix and have no typed
    | fields.
    |
    | Field types: ip, string, integer, boolean, username
    */
    'event_types' => [
        'authentication_failure' => ['source_ip' => 'ip', 'username' => 'username', 'failed_attempts' => 'integer', 'auth_method' => 'string'],
        'authentication_success' => ['source_ip' => 'ip', 'username' => 'username', 'auth_method' => 'string'],
        'account_lockout' => ['source_ip' => 'ip', 'username' => 'username'],
        'mfa_failure' => ['source_ip' => 'ip', 'username' => 'username'],
        'permission_denied' => ['source_ip' => 'ip', 'username' => 'username', 'resource' => 'string', 'action' => 'string'],
        'privilege_escalation' => ['username' => 'username', 'from_role' => 'string', 'to_role' => 'string'],
        'api_request_anomaly' => ['source_ip' => 'ip', 'endpoint' => 'string', 'request_count' => 'integer', 'window_seconds' => 'integer'],
        'rate_limit_exceeded' => ['source_ip' => 'ip', 'endpoint' => 'string', 'request_count' => 'integer'],
        'malware_detected' => ['file_path' => 'string', 'signature' => 'string', 'hostname' => 'string'],
        'suspicious_process' => ['process_name' => 'string', 'pid' => 'integer', 'hostname' => 'string', 'username' => 'username'],
        'firewall_block' => ['source_ip' => 'ip', 'destination_ip' => 'ip', 'destination_port' => 'integer', 'protocol' => 'string'],
        'port_scan_detected' => ['source_ip' => 'ip', 'ports_scanned' => 'integer'],
        'configuration_change' => ['username' => 'username', 'setting' => 'string'],
        'data_exfiltration_suspected' => ['source_ip' => 'ip', 'destination_ip' => 'ip', 'bytes_transferred' => 'integer'],
        'service_unavailable' => ['service' => 'string', 'hostname' => 'string'],
        'health_check_failure' => ['service' => 'string', 'hostname' => 'string'],
    ],

    'permissions' => [
        'organisation.view' => 'View organisation profile and settings',
        'organisation.update' => 'Update organisation profile and settings',
        'members.view' => 'View organisation members',
        'members.manage' => 'Add, remove and change roles of organisation members',
        'audit_logs.view' => 'View the organisation audit log',
        'api_credentials.manage' => 'Create, rotate and revoke ingestion credentials',
        'assets.view' => 'View monitored assets',
        'assets.manage' => 'Create, update and retire monitored assets',
        'events.view' => 'View security events',
        'events.manage' => 'Replay failed or dead-lettered security events',
        'detections.view' => 'View detection results and model information',
        'detections.manage' => 'Enable, disable and tune detection rules',
        'incidents.view' => 'View incidents and investigation reports',
        'incidents.manage' => 'Triage, assign, update and resolve incidents',
        'incidents.approve_response' => 'Approve or reject potentially disruptive defensive actions',
        'reports.generate' => 'Generate investigation reports',
        'agents.view' => 'View monitoring agents and health',
        'agents.manage' => 'Register and revoke monitoring agents',
        'platform.organisations.view' => 'View all organisations on the platform',
        'platform.users.manage' => 'Manage platform administrators',
    ],

    'roles' => [
        'platform_admin' => [
            'name' => 'Platform Administrator',
            'scope' => 'platform',
            'description' => 'Operates the KRATOS platform. Tenant data still requires organisation membership.',
            'permissions' => ['platform.organisations.view', 'platform.users.manage'],
        ],
        'org_admin' => [
            'name' => 'Organisation Administrator',
            'scope' => 'organisation',
            'description' => 'Full control of a single organisation.',
            'permissions' => [
                'organisation.view', 'organisation.update',
                'members.view', 'members.manage',
                'audit_logs.view', 'api_credentials.manage',
                'assets.view', 'assets.manage',
                'events.view', 'events.manage',
                'detections.view', 'detections.manage',
                'incidents.view', 'incidents.manage', 'incidents.approve_response',
                'reports.generate',
                'agents.view', 'agents.manage',
            ],
        ],
        'analyst' => [
            'name' => 'Security Analyst',
            'scope' => 'organisation',
            'description' => 'Investigates events and incidents and records decisions.',
            'permissions' => [
                'organisation.view', 'members.view',
                'assets.view', 'events.view', 'events.manage', 'detections.view',
                'incidents.view', 'incidents.manage',
                'reports.generate', 'agents.view',
            ],
        ],
        'viewer' => [
            'name' => 'Read-Only Viewer',
            'scope' => 'organisation',
            'description' => 'Read-only access to organisation security data.',
            'permissions' => [
                'organisation.view', 'members.view',
                'assets.view', 'events.view', 'detections.view',
                'incidents.view', 'agents.view',
            ],
        ],
    ],

];
