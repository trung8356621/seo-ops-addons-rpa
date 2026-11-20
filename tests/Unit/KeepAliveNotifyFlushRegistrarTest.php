<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Omnichannel\Addons\SocialAutomation\Scheduling\KeepAliveNotifyFlushRegistrar;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleTickRegistrar;
use PHPUnit\Framework\TestCase;

final class KeepAliveNotifyFlushRegistrarTest extends TestCase
{
    private ?Container $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance(EventMutex::class, new class implements EventMutex
        {
            public function create(\Illuminate\Console\Scheduling\Event $event): bool
            {
                return true;
            }

            public function exists(\Illuminate\Console\Scheduling\Event $event): bool
            {
                return false;
            }

            public function forget(\Illuminate\Console\Scheduling\Event $event): void {}
        });
        $app->instance(SchedulingMutex::class, new class implements SchedulingMutex
        {
            public function create(\Illuminate\Console\Scheduling\Event $event, \DateTimeInterface $time): bool
            {
                return true;
            }

            public function exists(\Illuminate\Console\Scheduling\Event $event, \DateTimeInterface $time): bool
            {
                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->previous instanceof Container) {
            Container::setInstance($this->previous);
        }
        parent::tearDown();
    }

    public function test_registers_exactly_one_every_minute_flush(): void
    {
        $schedule = new Schedule('UTC');
        $registrar = new KeepAliveNotifyFlushRegistrar;

        self::assertTrue($registrar->register($schedule));
        self::assertTrue($registrar->isRegistered($schedule));
        self::assertCount(1, $registrar->matchingEvents($schedule));
        self::assertSame('* * * * *', $registrar->matchingEvents($schedule)[0]->getExpression());
        self::assertStringContainsString('withoutOverlapping', (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Scheduling/KeepAliveNotifyFlushRegistrar.php'
        ));
    }

    public function test_second_register_is_noop_no_duplicate(): void
    {
        $schedule = new Schedule('UTC');
        $registrar = new KeepAliveNotifyFlushRegistrar;

        self::assertTrue($registrar->register($schedule));
        self::assertFalse($registrar->register($schedule));
        self::assertCount(1, $registrar->matchingEvents($schedule));
        self::assertCount(1, $schedule->events());
    }

    public function test_preserves_schedule_tick_when_both_registered(): void
    {
        $schedule = new Schedule('UTC');
        $tick = new ScheduleTickRegistrar;
        $flush = new KeepAliveNotifyFlushRegistrar;

        self::assertTrue($tick->register($schedule));
        self::assertTrue($flush->register($schedule));
        self::assertCount(1, $tick->matchingEvents($schedule));
        self::assertCount(1, $flush->matchingEvents($schedule));
        self::assertCount(2, $schedule->events());
        self::assertFalse($tick->register($schedule));
        self::assertFalse($flush->register($schedule));
        self::assertCount(2, $schedule->events());
    }

    public function test_detects_preexisting_host_registration(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command(KeepAliveNotifyFlushRegistrar::COMMAND)->hourly();

        $registrar = new KeepAliveNotifyFlushRegistrar;
        self::assertTrue($registrar->isRegistered($schedule));
        self::assertFalse($registrar->register($schedule));
        self::assertCount(1, $registrar->matchingEvents($schedule));
    }

    public function test_provider_wires_flush_registrar_and_command(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($root.'/src/SocialAutomationServiceProvider.php');
        $registrarSrc = (string) file_get_contents($root.'/src/Scheduling/KeepAliveNotifyFlushRegistrar.php');
        $publisher = (string) file_get_contents($root.'/src/Services/KeepAliveGatewayPublisher.php');

        self::assertStringContainsString('registerKeepAliveNotifyFlush', $provider);
        self::assertStringContainsString('KeepAliveNotifyFlushRegistrar', $provider);
        self::assertStringContainsString('KeepAliveNotifyFlushCommand', $provider);
        self::assertStringContainsString('ScheduleTickRegistrar', $provider);
        self::assertStringContainsString(KeepAliveNotifyFlushRegistrar::COMMAND, $registrarSrc);
        self::assertStringContainsString('withoutOverlapping', $registrarSrc);
        self::assertStringContainsString('gateway_unconfigured', $publisher);
        self::assertStringNotContainsString('soft no-op success', $publisher);
    }
}
