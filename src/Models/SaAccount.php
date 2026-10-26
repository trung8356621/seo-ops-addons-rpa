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
 * @property string $account_uuid
 * @property string|null $installation_id
 * @property string $platform
 * @property string|null $name
 * @property string|null $domain
 * @property string|null $label
 * @property string|null $username
 * @property string|null $declared_username
 * @property string|null $email
 * @property string|null $declared_email
 * @property string|null $encrypted_password
 * @property string|null $note
 * @property int|null $created_by
 * @property string|null $platform_account_id
 * @property string $identity_source
 * @property bool $verified
 * @property \Illuminate\Support\Carbon|null $last_confirmed_at
 * @property \Illuminate\Support\Carbon|null $last_interaction_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class SaAccount extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_accounts';

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'account_uuid',
        'installation_id',
        'platform',
        'name',
        'domain',
        'label',
        'username',
        'declared_username',
        'email',
        'declared_email',
        'encrypted_password',
        'note',
        'created_by',
        'platform_account_id',
        'identity_source',
        'verified',
        'last_confirmed_at',
        'last_interaction_at',
    ];

    /**
     * Password ciphertext is strictly hidden from serialization and API lists.
     */
    protected $hidden = [
        'encrypted_password',
    ];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'verified' => 'boolean',
            'last_confirmed_at' => 'datetime',
            'last_interaction_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCreatorName(): ?string
    {
        try {
            if ($this->relationLoaded('creator')) {
                return $this->getRelation('creator')?->name;
            }
            if (function_exists('app') && app()->bound('config') && $this->created_by) {
                return $this->creator?->name ?? "User #{$this->created_by}";
            }
        } catch (\Throwable) {
        }

        return $this->created_by ? "User #{$this->created_by}" : null;
    }

    public function hasPassword(): bool
    {
        $encrypted = $this->getAttribute('encrypted_password');

        return is_string($encrypted) && $encrypted !== '';
    }

    public function getAccountName(): string
    {
        return (string) ($this->getAttribute('name') ?? $this->getAttribute('label') ?? '');
    }

    public function getDisplayUsername(): ?string
    {
        return $this->getAttribute('username') ?? $this->getAttribute('declared_username');
    }

    public function getDisplayEmail(): ?string
    {
        return $this->getAttribute('email') ?? $this->getAttribute('declared_email');
    }

    public function getDecryptedPassword(): ?string
    {
        $encrypted = $this->getAttribute('encrypted_password');
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
}
