<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\RecoveryClientAuthorization;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\RecoveryRegistryService;
use Omnichannel\Addons\SocialAutomation\Services\RecoveryRuntimeAssetService;

final class ClientRecoveryController extends Controller
{
    public function __construct(
        private readonly RecoveryRegistryService $registry,
        private readonly RecoveryRuntimeAssetService $assets,
        private readonly RecoveryClientAuthorization $auth = new RecoveryClientAuthorization,
    ) {}
    public function registry(Request $r): JsonResponse
    {
        $ctx=$this->authorizedContext($r); if($ctx===null)return response()->json(['ok'=>false,'errors'=>['client_owner_required']],403);
        return response()->json($this->registry->runtimeRegistry($ctx['owner_ids'],$ctx['installation_id'],$r->query('platform')));
    }
    public function report(Request $r): JsonResponse
    {
        $ctx=$this->authorizedContext($r); if($ctx===null)return response()->json(['ok'=>false,'errors'=>['client_owner_required']],403);
        $result=$this->registry->report($ctx['owner_ids'],$ctx['installation_id'],$r->all(),$ctx['key_id']);
        $errors=$result['errors']??[]; $status=($result['ok']??false)?(($result['idempotent']??false)?200:201):(in_array('stale_attempt_or_lease',$errors,true)?409:422);
        return response()->json($result,$status);
    }
    public function templateImage(Request $r): JsonResponse|\Illuminate\Http\Response
    {
        $ctx=$this->authorizedContext($r); if($ctx===null)return response()->json(['ok'=>false,'errors'=>['client_owner_required']],403);
        $result=$this->assets->templateImage($ctx['owner_ids'],$ctx['installation_id'],(string)$r->input('visual_template_id',''));
        return $this->binary($r,$result,'template_image_failed');
    }
    public function pullHandler(Request $r): JsonResponse
    {
        $ctx=$this->authorizedContext($r); if($ctx===null)return response()->json(['ok'=>false,'errors'=>['client_owner_required']],403);
        $result=$this->assets->pullHandler($ctx['owner_ids'],$ctx['installation_id'],(string)$r->input('error_tag_id',''),(int)$r->input('release_id',0));
        return response()->json($result,(bool)($result['ok']??false)?200:(int)($result['http']??403));
    }
    public function handlerAsset(Request $r): JsonResponse|\Illuminate\Http\Response
    {
        $ctx=$this->authorizedContext($r); if($ctx===null)return response()->json(['ok'=>false,'errors'=>['client_owner_required']],403);
        $result=$this->assets->handlerAsset($ctx['owner_ids'],$ctx['installation_id'],(string)$r->input('error_tag_id',''),(int)$r->input('release_id',0),(string)$r->input('asset_path',''),strtolower((string)$r->input('expected_sha256','')));
        return $this->binary($r,$result,'handler_asset_failed');
    }
    private function binary(Request $r,array $result,string $fallback): JsonResponse|\Illuminate\Http\Response
    {
        if(!($result['ok']??false))return response()->json(['ok'=>false,'error'=>$result['errors'][0]??$fallback,'errors'=>$result['errors']??[$fallback]],(int)($result['http']??404));
        if(strtolower((string)$r->input('format',''))==='binary')return response($result['bytes'],200,['Content-Type'=>(string)$result['mime'],'Content-Length'=>(string)strlen($result['bytes']),'Cache-Control'=>'private, no-cache','X-Checksum-Sha256'=>(string)$result['sha256']]);
        $payload=$result; unset($payload['bytes']); $payload['asset_base64']=base64_encode($result['bytes']); return response()->json($payload);
    }
    /** @return array{owner_ids: list<int>, installation_id: string, key_id: int}|null */
    private function authorizedContext(Request $r): ?array
    {
        $key=$this->key($r);
        if(!$key||!$key->created_by)return null;
        $ownerIds=$this->auth->resolvePermittedOwnerIds($key);
        if($ownerIds===[])return null;
        return ['owner_ids'=>$ownerIds,'installation_id'=>(string)$key->installation_id,'key_id'=>(int)$key->id];
    }
    private function key(Request $r): ?SaClientApiKey { $key=$r->attributes->get(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY); return $key instanceof SaClientApiKey?$key:null; }
}
