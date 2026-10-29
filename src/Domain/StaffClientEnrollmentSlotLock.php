<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Serializes Staff Client enrollment for one staff user.
 *
 * Empty-row SELECT … FOR UPDATE on sa_client_api_keys does NOT block concurrent
 * first inserts. This lock therefore:
 * 1. Prefer locking the stable core `users` row (always exists for authenticated Staff).
 * 2. Fall back to MySQL GET_LOCK on the Social Automation connection.
 * 3. Still lock existing sa_client_api_keys rows for the staff (incl. revoked) inside the SA txn.
 */
final class StaffClientEnrollmentSlotLock
{
    public const ERROR_LOCK_TIMEOUT = 'enrollment_lock_timeout';

    public const ERROR_STAFF_NOT_FOUND = 'staff_not_found';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function synchronize(int $staffUserId, string $installationId, callable $callback): mixed
    {
        $staffUserId = max(1, $staffUserId);
        $installationId = trim($installationId);

        $saCritical = function () use ($callback, $installationId, $staffUserId): mixed {
            // Hold any existing key rows (active or revoked) for this staff slot.
            SaClientApiKey::query()
                ->where('installation_id', $installationId)
                ->where('created_by', $staffUserId)
                ->lockForUpdate()
                ->get();

            return $callback();
        };

        if ($this->canLockUserRow()) {
            return DB::connection($this->coreConnectionName())->transaction(function () use ($staffUserId, $saCritical): mixed {
                $user = User::query()->whereKey($staffUserId)->lockForUpdate()->first();
                if ($user === null) {
                    throw new \RuntimeException(self::ERROR_STAFF_NOT_FOUND);
                }

                return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction($saCritical);
            });
        }

        return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($saCritical, $installationId, $staffUserId): mixed {
            $lockName = $this->namedLock($installationId, $staffUserId);
            $got = DB::connection(SocialAutomationServiceConfig::CONNECTION)
                ->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            $acquired = is_object($got) ? (int) ($got->acquired ?? 0) : 0;
            if ($acquired !== 1) {
                throw new \RuntimeException(self::ERROR_LOCK_TIMEOUT);
            }
            try {
                return $saCritical();
            } finally {
                DB::connection(SocialAutomationServiceConfig::CONNECTION)
                    ->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        });
    }

    public function namedLock(string $installationId, int $staffUserId): string
    {
        // MySQL GET_LOCK name limit is 64 chars.
        return 'sa_enr_'.substr(hash('sha256', $installationId.'|'.$staffUserId), 0, 57);
    }

    private function canLockUserRow(): bool
    {
        if (! class_exists(User::class)) {
            return false;
        }
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return false;
            }
            DB::connection($this->coreConnectionName())->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function coreConnectionName(): string
    {
        try {
            if (function_exists('config')) {
                $name = (string) config('database.core_connection', '');
                if ($name !== '') {
                    return $name;
                }
                $default = (string) config('database.default', '');
                if ($default !== '') {
                    return $default;
                }
            }
        } catch (\Throwable) {
        }

        return 'mysql';
    }
}
