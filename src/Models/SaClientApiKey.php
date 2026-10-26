<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $installation_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
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
        'created_by',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * Plaintext key hash is strictly sensitive and never serialized or exposed.
     */
    protected $hidden = [
        'key_hash',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
