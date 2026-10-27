<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Services\ClientNotificationService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
final class ClientManagementController extends Controller
{
    public function notify(Request $request, int $sessionId, ClientNotificationService $service, SocialAutomationInstallationResolver $installations): JsonResponse
    {
        $data = $request->validate(['type'=>'required|in:manual_intervention,login_required','message'=>'required|string|max:2000','idempotency_key'=>'required|string|max:128','job_id'=>'nullable|integer']);
        $installationId = $installations->resolveInstallationId();
        $session = SaBrowserSession::query()->whereKey($sessionId)->whereHas('agent', fn($q)=>$q->where('installation_id',$installationId))->firstOrFail();
        if ($data['type'] === 'login_required') {
            $row = $service->requestLogin($session, $installationId, (int)$request->user()->id, $data['message']);
            return new JsonResponse(['ok'=>true,'notification_id'=>$row->notification_uuid,'status'=>$row->status],201);
        }
        $row = $service->create(['installation_id'=>$installationId,'agent_id'=>$session->agent_id,
            'browser_session_id'=>$session->id,'account_id'=>$session->account_id,'job_id'=>$data['job_id'] ?? null,
            'type'=>$data['type'],'message'=>$data['message'],'idempotency_key'=>$data['idempotency_key']], (int)$request->user()->id);
        return new JsonResponse(['ok'=>true,'notification_id'=>$row->notification_uuid,'status'=>$row->status],201);
    }
}
