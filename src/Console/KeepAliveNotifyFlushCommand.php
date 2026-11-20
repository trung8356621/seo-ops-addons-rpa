<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveNotifyService;

/**
 * Flush Keep Alive Socket.IO notify outbox (best-effort; never touches Job plane).
 */
final class KeepAliveNotifyFlushCommand extends Command
{
    protected $signature = 'social-automation:keepalive-notify-flush {--limit=50 : Max pending rows}';

    protected $description = 'Publish pending Keep Alive Socket.IO outbox notifications to the gateway';

    public function handle(KeepAliveNotifyService $notify): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $notify->flush($limit);
        $this->info(sprintf(
            'keepalive-notify flushed=%d failed=%d pending=%d',
            $result['flushed'],
            $result['failed'],
            $result['pending'],
        ));

        return self::SUCCESS;
    }
}
