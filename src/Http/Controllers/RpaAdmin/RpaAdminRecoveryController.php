<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryErrorTag;
use Omnichannel\Addons\SocialAutomation\Models\SaRecoveryHandlerRequest;

final class RpaAdminRecoveryController extends Controller
{
    public function requests(): JsonResponse
    {
        $rows=SaRecoveryHandlerRequest::query()->orderByDesc('id')->limit(200)->get()->map(function($r){$tag=SaRecoveryErrorTag::query()->find($r->error_tag_id);return ['request_uuid'=>$r->request_uuid,'status'=>$r->status,'notes'=>$r->notes,'error_tag'=>$tag?['id'=>$tag->error_tag_uuid,'name'=>$tag->name,'platform'=>$tag->platform,'site_scope'=>$tag->site_scope]:null,'created_at'=>$r->created_at?->toIso8601String()];});
        return response()->json(['ok'=>true,'requests'=>$rows]);
    }
    public function claim(Request $request,string $id): JsonResponse
    {
        $user=$request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY); if(!$user instanceof User)return response()->json(['ok'=>false,'errors'=>['unauthenticated']],401);
        $row=SaRecoveryHandlerRequest::query()->where('request_uuid',$id)->where('status','pending')->first(); if(!$row)return response()->json(['ok'=>false,'errors'=>['request_not_claimable']],409);
        $row->update(['status'=>'developing','claimed_by_user_id'=>$user->id,'claimed_at'=>now()]); return response()->json(['ok'=>true,'request_uuid'=>$row->request_uuid,'status'=>'developing']);
    }
}
