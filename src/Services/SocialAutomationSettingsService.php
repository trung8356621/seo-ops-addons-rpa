<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Models\SaSetting;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class SocialAutomationSettingsService
{
    public const KEY_SCHEDULER_INTERVAL_SECONDS = 'campaign_scheduler_interval_seconds';

    public const DEFAULT_INTERVAL_SECONDS = 3600;

    public const MIN_INTERVAL_SECONDS = 60;

    public const MAX_INTERVAL_SECONDS = 86400;

    public function getSchedulerIntervalSeconds(string $installationId): int
    {
        if (! SocialAutomationSchema::settingsReady()) {
            return self::DEFAULT_INTERVAL_SECONDS;
        }

        $row = SaSetting::query()
            ->where('installation_id', $installationId)
            ->where('setting_key', self::KEY_SCHEDULER_INTERVAL_SECONDS)
            ->first();

        if (! $row instanceof SaSetting || ! is_array($row->value_json)) {
            return self::DEFAULT_INTERVAL_SECONDS;
        }

        $val = (int) ($row->value_json['seconds'] ?? self::DEFAULT_INTERVAL_SECONDS);

        return max(self::MIN_INTERVAL_SECONDS, min(self::MAX_INTERVAL_SECONDS, $val));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(string $installationId): array
    {
        $row = SocialAutomationSchema::settingsReady()
            ? SaSetting::query()
                ->where('installation_id', $installationId)
                ->where('setting_key', self::KEY_SCHEDULER_INTERVAL_SECONDS)
                ->first()
            : null;

        $seconds = ($row instanceof SaSetting && is_array($row->value_json))
            ? max(self::MIN_INTERVAL_SECONDS, min(self::MAX_INTERVAL_SECONDS, (int) ($row->value_json['seconds'] ?? self::DEFAULT_INTERVAL_SECONDS)))
            : self::DEFAULT_INTERVAL_SECONDS;

        return [
            'scheduler_interval_seconds' => $seconds,
            'default_interval_seconds' => self::DEFAULT_INTERVAL_SECONDS,
            'min_interval_seconds' => self::MIN_INTERVAL_SECONDS,
            'max_interval_seconds' => self::MAX_INTERVAL_SECONDS,
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http?: int, data?: array<string, mixed>, errors?: array<string, list<string>>, error_codes?: list<string>, message?: string}
     */
    public function updateSettings(string $installationId, array $input, ?int $userId = null): array
    {
        if (! SocialAutomationSchema::settingsReady()) {
            return [
                'ok' => false,
                'http' => 503,
                'error_codes' => ['schema_unavailable'],
                'errors' => ['schema' => ['sa_settings table is not migrated.']],
                'message' => 'Settings schema is not migrated.',
            ];
        }

        if (! array_key_exists('scheduler_interval_seconds', $input) || ! is_numeric($input['scheduler_interval_seconds'])) {
            return [
                'ok' => false,
                'http' => 422,
                'error_codes' => ['invalid_scheduler_interval'],
                'errors' => ['scheduler_interval_seconds' => ['scheduler_interval_seconds must be an integer between 60 and 86400.']],
                'message' => 'scheduler_interval_seconds must be an integer between 60 and 86400.',
            ];
        }

        $seconds = (int) $input['scheduler_interval_seconds'];
        if ((string) $seconds !== trim((string) $input['scheduler_interval_seconds'])
            && ! is_int($input['scheduler_interval_seconds'])) {
            return [
                'ok' => false,
                'http' => 422,
                'error_codes' => ['invalid_scheduler_interval'],
                'errors' => ['scheduler_interval_seconds' => ['scheduler_interval_seconds must be an integer.']],
                'message' => 'scheduler_interval_seconds must be an integer.',
            ];
        }

        if ($seconds < self::MIN_INTERVAL_SECONDS || $seconds > self::MAX_INTERVAL_SECONDS) {
            return [
                'ok' => false,
                'http' => 422,
                'error_codes' => ['scheduler_interval_out_of_range'],
                'errors' => ['scheduler_interval_seconds' => ['scheduler_interval_seconds must be between '.self::MIN_INTERVAL_SECONDS.' and '.self::MAX_INTERVAL_SECONDS.' seconds.']],
                'message' => 'scheduler_interval_seconds must be between 60 and 86400 seconds.',
            ];
        }

        SaSetting::query()->updateOrCreate(
            [
                'installation_id' => $installationId,
                'setting_key' => self::KEY_SCHEDULER_INTERVAL_SECONDS,
            ],
            [
                'value_json' => ['seconds' => $seconds],
                'updated_by_user_id' => $userId,
            ]
        );

        return [
            'ok' => true,
            'http' => 200,
            'data' => $this->getSettings($installationId),
        ];
    }
}
