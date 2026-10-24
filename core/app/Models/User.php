<?php

namespace App\Models;

use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Emails are compared case-insensitively (see the lower(email) unique index).
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /** @return HasMany<OrganisationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    /** @return BelongsToMany<Organisation, $this> */
    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class, 'organisation_memberships')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /** @return BelongsTo<Role, $this> */
    public function platformRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'platform_role_id');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platformRole?->slug === Role::PLATFORM_ADMIN;
    }

    public function membershipIn(Organisation|string $organisation): ?OrganisationMembership
    {
        $organisationId = $organisation instanceof Organisation ? $organisation->getKey() : $organisation;

        return $this->memberships()
            ->with('role.permissions')
            ->where('organisation_id', $organisationId)
            ->first();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }
}
