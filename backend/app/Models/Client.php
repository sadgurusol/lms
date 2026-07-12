<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A B2B integrator: ABC School.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $integration
 * @property array<string, mixed> $settings
 */
#[Fillable(['name', 'slug', 'status', 'integration', 'contact_email', 'settings', 'report_webhook_url', 'webhook_secret'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, HasUuids;

    public const ACTIVE = 'active';

    public const LTI = 'lti_1_3';

    public const CUSTOM_JWT = 'custom_jwt';

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** @return HasMany<ClientKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(ClientKey::class);
    }

    /** @return HasMany<ClientUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }

    /** @return HasMany<ClientEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(ClientEntitlement::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Whether this client's learners may use the AI tutor. On unless turned off. */
    public function aiTutorEnabled(): bool
    {
        return (bool) ($this->settings['ai_tutor_enabled'] ?? true);
    }

    public function setAiTutorEnabled(bool $enabled): void
    {
        $this->update(['settings' => [...$this->settings ?? [], 'ai_tutor_enabled' => $enabled]]);
    }

    /** The identity provider string for users this client provisions. */
    public function identityProvider(): string
    {
        return UserIdentity::clientProvider($this->slug);
    }
}
