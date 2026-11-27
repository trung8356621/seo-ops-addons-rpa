<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\RecoveryRegistryService;

final class ClientRecoveryController extends Controller
{
    public function __construct(private readonly RecoveryRegistryService $registry) {}
    public function registry(Request $r): JsonResponse
    {
        $key=$this->key($r); if(!$key||!$key->created_by)return response()->json(['ok'=>false,'errors'=>['client_owner_required']],403);
        return response()->json($this->registry->runtimeRegistry((int)$key->created_by,(string)$key->installation_id,$r->query('platform')));
    }
    public function report(Request $r): JsonResponse
    {
        $key=$this->key($r); if(!$key||!$key->created_by)return response()->json(['ok'=>false,'errors'=>['client_owner_required']],403);
        $result=$this->registry->report((int)$key->created_by,(string)$key->installation_id,$r->all());
        $errors=$result['errors']??[]; $status=($result['ok']??false)?(($result['idempotent']??false)?200:201):(in_array('stale_attempt_or_lease',$errors,true)?409:422);
        return response()->json($result,$status);
    }
    private function key(Request $r): ?SaClientApiKey { $key=$r->attributes->get(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY); return $key instanceof SaClientApiKey?$key:null; }
}
