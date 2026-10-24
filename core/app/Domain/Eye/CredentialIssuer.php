<?php

namespace App\Domain\Eye;

use App\Domain\Audit\AuditLogger;
use App\Models\ApiCredential;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Issues and revokes ingestion credentials. The plaintext secret exists only
 * in the return value of issue(); it is stored encrypted (ADR-0006).
 */
final class CredentialIssuer
{
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{credential: ApiCredential, secret: string}
     */
    public function issue(Organisation $organisation, string $name, ?Carbon $expiresAt, User $actor): array
    {
        $secret = 'krs_'.Str::random(40);

        $credential = ApiCredential::create([
            'organisation_id' => $organisation->getKey(),
            'name' => $name,
            'key_id' => 'krk_'.Str::lower(Str::random(24)),
            'secret_encrypted' => $this->encrypter->encryptString($secret),
            'scopes' => [ApiCredential::SCOPE_INGEST],
            'expires_at' => $expiresAt,
            'created_by' => $actor->getKey(),
        ]);

        $this->audit->record('api_credential.created', $credential, [
            'name' => $name,
            'key_id' => $credential->key_id,
            'expires_at' => $expiresAt?->toIso8601String(),
        ], $organisation, $actor);

        return ['credential' => $credential, 'secret' => $secret];
    }

    public function revoke(ApiCredential $credential): void
    {
        if ($credential->revoked_at !== null) {
            return;
        }

        $credential->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('api_credential.revoked', $credential, ['key_id' => $credential->key_id]);
    }

    public function secretFor(ApiCredential $credential): string
    {
        return $this->encrypter->decryptString($credential->secret_encrypted);
    }
}
