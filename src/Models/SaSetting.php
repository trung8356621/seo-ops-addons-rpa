<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $installation_id
 * @property string $setting_key
 * @property array<string, mixed>|null $value_json
 * @property int|null $updated_by_user_id
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class SaSetting extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_settings';

    protected $fillable = [
        'installation_id',
        'setting_key',
        'value_json',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'updated_by_user_id' => 'integer',
        ];
    }
}
