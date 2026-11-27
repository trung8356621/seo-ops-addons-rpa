<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaRecoveryVisualTemplate extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_recovery_visual_templates';
    protected $guarded = [];
    protected function casts(): array { return ['modal_roi'=>'array','ocr_anchors'=>'array','target_roi'=>'array','recognition_config'=>'array','match_threshold'=>'float','version'=>'integer','is_enabled'=>'boolean']; }
    public function errorTag(): BelongsTo { return $this->belongsTo(SaRecoveryErrorTag::class, 'error_tag_id'); }
}
