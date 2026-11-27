<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Services\RecoveryRegistryService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;

final class RecoveryRegistryController extends Controller
{
    public function __construct(private readonly RecoveryRegistryService $registry, private readonly SocialAutomationInstallationResolver $installations) {}
    public function index(Request $r): JsonResponse { return response()->json($this->registry->listTags((int)$r->user()->id,$r->query())); }
    public function store(Request $r): JsonResponse { return $this->respond($this->registry->createTag((int)$r->user()->id,$this->installations->resolveInstallationId(),$r->all()),201); }
    public function update(Request $r,string $id): JsonResponse { return $this->respond($this->registry->updateTag((int)$r->user()->id,$id,$r->all())); }
    public function addTemplate(Request $r,string $id): JsonResponse { return $this->respond($this->registry->addTemplate((int)$r->user()->id,$id,$r->all()),201); }
    public function updateTemplate(Request $r,string $id,string $templateId): JsonResponse { return $this->respond($this->registry->updateTemplate((int)$r->user()->id,$id,$templateId,$r->all())); }
    public function bind(Request $r,string $id): JsonResponse { $release=$r->input('release_id'); return $this->respond($this->registry->bind((int)$r->user()->id,$id,$release===null?null:(int)$release)); }
    public function requestHandler(Request $r,string $id): JsonResponse { return $this->respond($this->registry->requestHandler((int)$r->user()->id,$id,$r->input('notes')),201); }
    private function respond(array $result,int $success=200): JsonResponse { $ok=(bool)($result['ok']??false); $errors=$result['errors']??[]; $status=$ok?$success:(in_array('error_tag_not_found',$errors,true)||in_array('visual_template_not_found',$errors,true)?404:422); return response()->json($result,$status); }
}
