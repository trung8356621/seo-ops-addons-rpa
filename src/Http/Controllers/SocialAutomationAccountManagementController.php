<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Services\SaAccountService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

final class SocialAutomationAccountManagementController extends Controller
{
    public function __construct(
        private readonly SaAccountService $accountService,
        private readonly SocialAutomationAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();

        $accounts = $this->accountService->list()->map(fn (SaAccount $a) => $this->publicAccount($a));

        return new JsonResponse([
            'ok' => true,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->assertCanManageAccounts();

        $name = trim((string) ($request->input('name') ?? $request->input('account_name') ?? ''));
        if ($name === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Tên tài khoản (Account Name) là bắt buộc.',
            ], 422);
        }

        $userId = $request->user()?->id ? (int) $request->user()->id : null;
        try {
            $account = $this->accountService->create($request->all(), $userId);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => 'Tài khoản đã được tạo thành công.',
            'account' => $this->publicAccount($account),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->access->assertCanManageAccounts();

        try {
            $account = $this->accountService->update($id, $request->all());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
        if (! $account instanceof SaAccount) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Không tìm thấy tài khoản trong Installation hiện tại.',
            ], 404);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => 'Cập nhật tài khoản thành công.',
            'account' => $this->publicAccount($account),
        ], 200);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->access->assertCanManageAccounts();

        $deleted = $this->accountService->delete($id);
        if (! $deleted) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Không tìm thấy tài khoản hoặc không thuộc Installation hiện tại.',
            ], 404);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => 'Xóa tài khoản thành công.',
        ], 200);
    }

    /**
     * Public account metadata for Staff/Owner inventory. Never includes secrets.
     *
     * @return array<string, mixed>
     */
    private function publicAccount(SaAccount $a): array
    {
        return [
            'id' => (int) $a->getAttribute('id'),
            'account_uuid' => (string) $a->getAttribute('account_uuid'),
            'installation_id' => (string) $a->getAttribute('installation_id'),
            'platform' => (string) $a->getAttribute('platform'),
            'name' => (string) $a->getAccountName(),
            'account_name' => (string) $a->getAccountName(),
            'username' => $a->getDisplayUsername(),
            'email' => $a->getDisplayEmail(),
            'site_url' => $a->getSiteUrl(),
            'has_password' => $a->hasPassword(),
            'note' => $a->getAttribute('note'),
            'last_interaction_at' => $a->last_interaction_at?->toIso8601String(),
            'created_by' => $a->created_by ? (int) $a->created_by : null,
            'created_by_name' => $a->getCreatorName(),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}
