<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $installation_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property string|null $encrypted_key
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class SaClientApiKey extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_client_api_keys';

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'installation_id',
        'name',
        'key_prefix',
        'key_hash',
        'encrypted_key',
        'created_by',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * Plaintext key hash and encrypted payload are strictly sensitive and never serialized.
     */
    protected $hidden = [
        'key_hash',
        'encrypted_key',
    ];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return ($this->attributes['revoked_at'] ?? null) === null;
    }

    public function isRevoked(): bool
    {
        return ! $this->isActive();
    }

    public function revoke(): void
    {
        if ($this->isActive()) {
            if ($this->exists) {
                $this->update(['revoked_at' => now()]);
            } else {
                $attrs = $this->getAttributes();
                $attrs['revoked_at'] = date('Y-m-d H:i:s');
                $this->setRawAttributes($attrs, true);
            }
        }
    }

    public function touchLastUsed(): void
    {
        if ($this->exists) {
            $this->update(['last_used_at' => now()]);
        } else {
            $attrs = $this->getAttributes();
            $attrs['last_used_at'] = date('Y-m-d H:i:s');
            $this->setRawAttributes($attrs, true);
        }
    }

    public function getDecryptedRawKey(): ?string
    {
        $encrypted = $this->getAttribute('encrypted_key');
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        if (str_starts_with($encrypted, 'enc:')) {
            $decoded = base64_decode(substr($encrypted, 4), true);

            return $decoded !== false ? $decoded : null;
        }

        if (function_exists('app') && app()->bound('encrypter')) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                // Decryption failure
            }
        }

        return null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
