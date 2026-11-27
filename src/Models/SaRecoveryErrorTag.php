<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaRecoveryErrorTag extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_recovery_error_tags';
    protected $guarded = [];
    protected function casts(): array { return ['owner_user_id'=>'integer','is_enabled'=>'boolean','handler_package_id'=>'integer','handler_release_id'=>'integer','recovery_policy'=>'array','max_attempts_per_step'=>'integer']; }
    public function templates(): HasMany { return $this->hasMany(SaRecoveryVisualTemplate::class, 'error_tag_id'); }
}
