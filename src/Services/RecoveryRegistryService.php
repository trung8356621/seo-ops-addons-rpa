<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryErrorTag;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryHandlerRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryResult;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryVisualTemplate;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class RecoveryRegistryService
{
    /** @return array<string,mixed> */
    public function createTag(int $userId, string $installationId, array $input): array
    {
        $source = $this->authorizedFailedSource($userId, (string)($input['attempt_uuid'] ?? ''), (string)($input['artifact_uuid'] ?? ''));
        if (!($source['ok'] ?? false)) return $source;
        $errors = $this->validateTag($input);
        $templateErrors = $this->validateTemplate($input);
        if ($errors !== [] || $templateErrors !== []) return ['ok'=>false,'errors'=>array_merge($errors,$templateErrors)];

        return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($userId,$installationId,$input,$source): array {
            $tag = SaRecoveryErrorTag::query()->create([
                'error_tag_uuid'=>(string)Str::uuid(),'owner_user_id'=>$userId,'installation_id'=>$installationId,
                'name'=>trim((string)$input['name']),'description'=>$input['description'] ?? null,
                'platform'=>strtolower(trim((string)$input['platform'])),'site_scope'=>$input['site_scope'] ?? null,
                'is_enabled'=>(bool)($input['is_enabled'] ?? true),'recovery_policy'=>$input['recovery_policy'] ?? [],
                'max_attempts_per_step'=>min(5,max(1,(int)($input['max_attempts_per_step'] ?? 1))),
            ]);
            $template = $this->insertTemplate($tag, $source['attempt'], $source['artifact'], $input);
            return ['ok'=>true,'tag'=>$this->tagDto($tag->fresh() ?? $tag, [$template])];
        });
    }

    /** @return array<string,mixed> */
    public function addTemplate(int $userId, string $tagUuid, array $input): array
    {
        $tag = $this->ownedTag($userId,$tagUuid);
        if (!$tag) return ['ok'=>false,'errors'=>['error_tag_not_found']];
        $source = $this->authorizedFailedSource($userId,(string)($input['attempt_uuid'] ?? ''),(string)($input['artifact_uuid'] ?? ''));
        if (!($source['ok'] ?? false)) return $source;
        $errors=$this->validateTemplate($input); if($errors!==[]) return ['ok'=>false,'errors'=>$errors];
        $template=$this->insertTemplate($tag,$source['attempt'],$source['artifact'],$input);
        return ['ok'=>true,'template'=>$this->templateDto($template)];
    }

    /** @return array<string,mixed> */
    public function listTags(int $userId, array $filters=[]): array
    {
        $q=SaRecoveryErrorTag::query()->with('templates')->where('owner_user_id',$userId);
        if(trim((string)($filters['search']??''))!=='') $q->where(fn($x)=>$x->where('name','like','%'.trim((string)$filters['search']).'%')->orWhere('description','like','%'.trim((string)$filters['search']).'%'));
        if(trim((string)($filters['platform']??''))!=='') $q->where('platform',strtolower(trim((string)$filters['platform'])));
        return ['ok'=>true,'error_tags'=>$q->orderByDesc('id')->limit(200)->get()->map(fn($t)=>$this->tagDto($t,$t->templates->all()))->all()];
    }

    /** @return array<string,mixed> */
    public function updateTag(int $userId,string $uuid,array $input): array
    {
        $tag=$this->ownedTag($userId,$uuid); if(!$tag)return ['ok'=>false,'errors'=>['error_tag_not_found']];
        $allowed=array_intersect_key($input,array_flip(['name','description','platform','site_scope','is_enabled','recovery_policy','max_attempts_per_step']));
        if(isset($allowed['max_attempts_per_step']))$allowed['max_attempts_per_step']=min(5,max(1,(int)$allowed['max_attempts_per_step']));
        $tag->update($allowed); return ['ok'=>true,'tag'=>$this->tagDto($tag->fresh()??$tag)];
    }

    /** @return array<string,mixed> */
    public function updateTemplate(int $userId,string $tagUuid,string $templateUuid,array $input): array
    {
        $tag=$this->ownedTag($userId,$tagUuid); if(!$tag)return ['ok'=>false,'errors'=>['error_tag_not_found']];
        $tpl=SaRecoveryVisualTemplate::query()->where('error_tag_id',$tag->id)->where('template_uuid',$templateUuid)->first();
        if(!$tpl)return ['ok'=>false,'errors'=>['visual_template_not_found']];
        $allowed=array_intersect_key($input,array_flip(['modal_roi','ocr_anchors','target_roi','recognition_config','match_threshold','is_enabled']));
        if(isset($allowed['match_threshold']))$allowed['match_threshold']=max(0.5,min(1.0,(float)$allowed['match_threshold']));
        if(array_diff_key($allowed,['is_enabled'=>true])!==[])$allowed['version']=(int)$tpl->version+1;
        $tpl->update($allowed); return ['ok'=>true,'template'=>$this->templateDto($tpl->fresh()??$tpl)];
    }

    /** @return array<string,mixed> */
    public function bind(int $userId,string $tagUuid,?int $releaseId): array
    {
        $tag=$this->ownedTag($userId,$tagUuid); if(!$tag)return ['ok'=>false,'errors'=>['error_tag_not_found']];
        if($releaseId===null){$tag->update(['handler_package_id'=>null,'handler_release_id'=>null]);return ['ok'=>true,'tag'=>$this->tagDto($tag->fresh()??$tag)];}
        $release=SaPackageRelease::query()->with('package')->whereKey($releaseId)->where('status','published')->first();
        $package=$release?->package;
        if(!$release || !$package || $package->kind!=='script' || !($package->is_enabled ?? true) || (int)($package->created_by_user_id??0)!==$userId || !$this->compatibleHandler($release))
            return ['ok'=>false,'errors'=>['published_compatible_owned_handler_required']];
        $tag->update(['handler_package_id'=>$package->id,'handler_release_id'=>$release->id]);
        SaRecoveryHandlerRequest::query()->where('error_tag_id',$tag->id)->whereIn('status',['pending','developing'])->update(['status'=>'published']);
        return ['ok'=>true,'tag'=>$this->tagDto($tag->fresh()??$tag)];
    }

    /** @return array<string,mixed> */
    public function requestHandler(int $userId,string $tagUuid,?string $notes): array
    {
        $tag=$this->ownedTag($userId,$tagUuid); if(!$tag)return ['ok'=>false,'errors'=>['error_tag_not_found']];
        $existing=SaRecoveryHandlerRequest::query()->where('error_tag_id',$tag->id)->whereIn('status',['pending','developing'])->first();
        if($existing)return ['ok'=>true,'idempotent'=>true,'request_uuid'=>$existing->request_uuid];
        $row=SaRecoveryHandlerRequest::query()->create(['request_uuid'=>(string)Str::uuid(),'error_tag_id'=>$tag->id,'owner_user_id'=>$userId,'status'=>'pending','notes'=>$notes]);
        return ['ok'=>true,'idempotent'=>false,'request_uuid'=>$row->request_uuid];
    }

    /** @return array<string,mixed> */
    public function runtimeRegistry(int $ownerUserId,string $installationId,?string $platform=null): array
    {
        $q=SaRecoveryErrorTag::query()->with(['templates'=>fn($x)=>$x->where('is_enabled',true)])->where('owner_user_id',$ownerUserId)->where('installation_id',$installationId)->where('is_enabled',true)->whereNotNull('handler_release_id');
        if($platform)$q->where('platform',strtolower($platform));
        $rows=[]; foreach($q->get() as $tag){
            $release=SaPackageRelease::query()->with('package')->whereKey($tag->handler_release_id)->where('status','published')->first();
            if(!$release||!$release->package||!($release->package->is_enabled??true)||(int)($release->package->created_by_user_id??0)!==$ownerUserId||!$this->compatibleHandler($release)||$tag->templates->isEmpty())continue;
            $dto=$this->tagDto($tag,$tag->templates->all()); $dto['handler']=$this->releaseDto($release); $rows[]=$dto;
        }
        return ['ok'=>true,'schema_version'=>1,'runtime_input_contract'=>$this->runtimeInputContract(),'error_tags'=>$rows];
    }

    /** @return array<string,mixed> */
    public function report(int $ownerUserId,string $installationId,array $input): array
    {
        foreach(['attempt_uuid','lease_token','error_tag_id','visual_template_id','handler_release_id','failed_step_id','recovery_attempt_no','match_confidence','verification_outcome','idempotency_key'] as $f)if(!isset($input[$f])||$input[$f]==='')return ['ok'=>false,'errors'=>['recovery_'.$f.'_required']];
        $attempt=SaJobAttempt::query()->with('job')->where('attempt_uuid',(string)$input['attempt_uuid'])->first();
        if(!$attempt||!$attempt->job||!hash_equals((string)$attempt->lease_token,(string)$input['lease_token']))return ['ok'=>false,'errors'=>['stale_attempt_or_lease']];
        $campaign=$attempt->job->campaign_id?SaCampaign::query()->find($attempt->job->campaign_id):null;
        if(!$campaign||$campaign->installation_id!==$installationId||(int)$campaign->created_by_user_id!==$ownerUserId)return ['ok'=>false,'errors'=>['attempt_not_authorized']];
        $tag=SaRecoveryErrorTag::query()->where('owner_user_id',$ownerUserId)->where('id',(int)$input['error_tag_id'])->first();
        $tpl=$tag?SaRecoveryVisualTemplate::query()->where('error_tag_id',$tag->id)->where('id',(int)$input['visual_template_id'])->first():null;
        if(!$tag||!$tag->is_enabled||!$tpl||!$tpl->is_enabled||(int)$tag->handler_release_id!==(int)$input['handler_release_id'])return ['ok'=>false,'errors'=>['recovery_configuration_mismatch']];
        if((float)$input['match_confidence']<(float)$tpl->match_threshold)return ['ok'=>false,'errors'=>['confidence_below_threshold']];
        if((int)$input['recovery_attempt_no']<1||(int)$input['recovery_attempt_no']>(int)$tag->max_attempts_per_step)return ['ok'=>false,'errors'=>['recovery_attempt_limit_exceeded']];
        if((bool)($input['is_handler_execution']??false))return ['ok'=>false,'errors'=>['recursive_handler_execution_forbidden']];
        $verification=(string)$input['verification_outcome'];
        if(!in_array($verification,['passed','failed'],true))return ['ok'=>false,'errors'=>['verification_outcome_invalid']];
        if(in_array((string)($input['final_job_outcome']??''),['resumed','succeeded','completed'],true)&&$verification!=='passed')return ['ok'=>false,'errors'=>['verified_recovery_required']];
        $row=SaRecoveryResult::query()->firstOrCreate(['idempotency_key'=>(string)$input['idempotency_key']],array_merge(array_intersect_key($input,array_flip(['failed_step_id','recovery_attempt_no','match_confidence','handler_started_at','handler_finished_at','verification_outcome','final_job_outcome','runtime_input','evidence'])),['recovery_uuid'=>(string)Str::uuid(),'attempt_id'=>$attempt->id,'error_tag_id'=>$tag->id,'visual_template_id'=>$tpl->id,'handler_release_id'=>$tag->handler_release_id,'source_artifact_id'=>$tpl->source_artifact_id,'runtime_input'=>$input['runtime_input']??[]]));
        return ['ok'=>true,'idempotent'=>!$row->wasRecentlyCreated,'recovery_uuid'=>$row->recovery_uuid,'attempt_uuid'=>$attempt->attempt_uuid];
    }

    private function authorizedFailedSource(int $userId,string $attemptUuid,string $artifactUuid): array
    {
        $attempt=SaJobAttempt::query()->with('job')->where('attempt_uuid',$attemptUuid)->first();
        if(!$attempt||!$attempt->job||!in_array(strtolower((string)$attempt->status),['failed','error'],true))return ['ok'=>false,'errors'=>['failed_attempt_required']];
        $campaign=$attempt->job->campaign_id?SaCampaign::query()->find($attempt->job->campaign_id):null;
        if(!$campaign||(int)$campaign->created_by_user_id!==$userId)return ['ok'=>false,'errors'=>['attempt_not_found_or_forbidden']];
        $artifact=SaArtifact::query()->where('artifact_uuid',$artifactUuid)->where('attempt_id',$attempt->id)->where('kind','screenshot')->first();
        if(!$artifact)return ['ok'=>false,'errors'=>['failed_attempt_screenshot_required']];
        return ['ok'=>true,'attempt'=>$attempt,'artifact'=>$artifact];
    }

    private function insertTemplate(SaRecoveryErrorTag $tag,SaJobAttempt $attempt,SaArtifact $artifact,array $input): SaRecoveryVisualTemplate
    { return SaRecoveryVisualTemplate::query()->create(['template_uuid'=>(string)Str::uuid(),'error_tag_id'=>$tag->id,'source_attempt_id'=>$attempt->id,'source_artifact_id'=>$artifact->id,'modal_roi'=>$input['modal_roi'],'ocr_anchors'=>$input['ocr_anchors']??null,'target_roi'=>$input['target_roi']??null,'recognition_config'=>$input['recognition_config']??[],'match_threshold'=>max(.5,min(1,(float)($input['match_threshold']??.85))),'version'=>1,'is_enabled'=>(bool)($input['template_enabled']??true)]); }
    private function ownedTag(int $userId,string $uuid): ?SaRecoveryErrorTag { return SaRecoveryErrorTag::query()->where('owner_user_id',$userId)->where('error_tag_uuid',$uuid)->first(); }
    private function validateTag(array $i): array { $e=[]; foreach(['name','platform'] as $f)if(trim((string)($i[$f]??''))==='')$e[]=$f.'_required'; return $e; }
    private function validateTemplate(array $i): array { $e=[]; foreach(['attempt_uuid','artifact_uuid','modal_roi'] as $f)if(!isset($i[$f])||$i[$f]===''||($f==='modal_roi'&&!is_array($i[$f])))$e[]=$f.'_required'; foreach(['modal_roi','target_roi'] as $f)if(isset($i[$f])&&!$this->validRoi($i[$f]))$e[]=$f.'_invalid'; return $e; }
    private function validRoi(mixed $v): bool { if(!is_array($v))return false; foreach(['x','y','width','height'] as $k)if(!isset($v[$k])||!is_numeric($v[$k])||(float)$v[$k]<0||(float)$v[$k]>1)return false; return (float)$v['width']>0&&(float)$v['height']>0&&(float)$v['x']+(float)$v['width']<=1.00001&&(float)$v['y']+(float)$v['height']<=1.00001; }
    private function compatibleHandler(SaPackageRelease $r): bool { $c=is_array($r->runtime_compatibility)?$r->runtime_compatibility:[]; $m=is_array($r->manifest)?$r->manifest:[]; return ($c['recovery_handler_v1']??false)===true||($m['runtime_contract']??null)==='recovery_handler_v1'; }
    private function releaseDto(SaPackageRelease $r): array { return ['package_key'=>$r->package->package_key,'release_id'=>(int)$r->id,'version'=>$r->version,'content_digest'=>$r->content_digest,'execution_digest'=>$r->execution_digest,'manifest'=>$r->manifest,'status'=>'published']; }
    private function tagDto(SaRecoveryErrorTag $t,array $templates=[]): array { return ['id'=>(int)$t->id,'error_tag_id'=>$t->error_tag_uuid,'name'=>$t->name,'description'=>$t->description,'platform'=>$t->platform,'site_scope'=>$t->site_scope,'enabled'=>(bool)$t->is_enabled,'executable'=>$t->handler_release_id!==null&&count($templates)>0,'handler_release_id'=>$t->handler_release_id? (int)$t->handler_release_id:null,'recovery_policy'=>$t->recovery_policy,'max_attempts_per_step'=>(int)$t->max_attempts_per_step,'templates'=>array_map(fn($x)=>$this->templateDto($x),$templates),'created_at'=>$t->created_at?->toIso8601String(),'updated_at'=>$t->updated_at?->toIso8601String()]; }
    private function templateDto(SaRecoveryVisualTemplate $t): array { return ['id'=>(int)$t->id,'visual_template_id'=>$t->template_uuid,'source_attempt_id'=>(int)$t->source_attempt_id,'source_artifact_id'=>(int)$t->source_artifact_id,'modal_roi'=>$t->modal_roi,'ocr_anchors'=>$t->ocr_anchors,'target_roi'=>$t->target_roi,'recognition_config'=>$t->recognition_config,'match_threshold'=>(float)$t->match_threshold,'version'=>(int)$t->version,'enabled'=>(bool)$t->is_enabled]; }
    private function runtimeInputContract(): array { return ['matched_error_tag_id'=>'integer','matched_visual_template_id'=>'integer','modal_bounding_box'=>'normalized_roi','selected_target_bounding_box'=>'normalized_roi','click_coordinates'=>'viewport_pixels','viewport'=>['width'=>'integer','height'=>'integer']]; }
}
