<?php

namespace App\Models;

use App\Models\Concerns\HasCourseGrants;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $name
 * @property string|null $email
 * @property string|null $password
 * @property string $status
 * @property string $kind
 * @property string $locale
 * @property Carbon|null $date_of_birth
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_seen_at
 */
#[Fillable(['name', 'email', 'password', 'status', 'kind', 'locale', 'date_of_birth'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasCourseGrants, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    public const KIND_LOCAL = 'local';

    public const KIND_CLIENT_PROVISIONED = 'client_provisioned';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<UserIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * The client context of the current session, or null for B2C.
     *
     * Read from the access token minted by the launch exchange. It must never be
     * read from a request parameter — that is how one school ends up reading
     * another school's data.
     */
    public function currentClientId(): ?string
    {
        // Sanctum types this as PersonalAccessToken, but a session-authenticated
        // request yields a TransientToken, which is not a model and has no
        // client. isset() answers correctly for both without an instanceof that
        // static analysis can prove is always true.
        $token = $this->currentAccessToken();

        return isset($token->client_id) ? (string) $token->client_id : null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Client-provisioned users arrive through an SIS launch. They hold no
     * password identity, so there is no credential to phish and no password
     * reset flow to abuse. See docs/10-clients-and-launch.md §7.
     */
    public function isClientProvisioned(): bool
    {
        return $this->kind === self::KIND_CLIENT_PROVISIONED;
    }
}
