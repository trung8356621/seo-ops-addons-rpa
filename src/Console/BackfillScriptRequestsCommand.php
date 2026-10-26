<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ActionRegistry\ActionRegistryController;

final class BackfillScriptRequestsCommand extends Command
{
    protected $signature = 'omi:social-automation:backfill-script-requests {--apply : Persist created script requests into database (default is dry-run)}';

    protected $description = 'Idempotently backfill missing Script Requests for existing Action Tags';

    public function handle(): int
    {
        $isApply = (bool) $this->option('apply');
        $dryRun = ! $isApply;

        $this->info($dryRun ? 'Running in DRY-RUN mode (no DB changes)...' : 'Running in APPLY mode (persisting to DB)...');

        $result = ActionRegistryController::backfillMissingRequests($dryRun);

        $scanned = (int) ($result['scanned'] ?? 0);
        $eligible = (int) ($result['eligible'] ?? 0);
        $created = (int) ($result['created'] ?? 0);
        $skipped = $scanned - $eligible;

        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode', $dryRun ? 'DRY-RUN' : 'APPLIED'],
                ['Tags Checked', $scanned],
                ['Requests To Create / Created', $dryRun ? $eligible : $created],
                ['Skipped (already have request)', $skipped],
            ]
        );

        if (! empty($result['tags'])) {
            $this->newLine();
            $this->info('Target Tag Keys:');
            foreach ($result['tags'] as $key) {
                $this->line(" - {$key}");
            }
        }

        if ($dryRun && $eligible > 0) {
            $this->newLine();
            $this->comment('To apply these changes, re-run with:');
            $this->line('  php artisan omi:social-automation:backfill-script-requests --apply');
        }

        return self::SUCCESS;
    }
}
