<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleTickRegistrar;
use PHPUnit\Framework\TestCase;

final class ScheduleTickRegistrarTest extends TestCase
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

    public function test_registers_exactly_one_every_minute_tick(): void
    {
        $schedule = new Schedule('UTC');
        $registrar = new ScheduleTickRegistrar;

        self::assertTrue($registrar->register($schedule));
        self::assertTrue($registrar->isRegistered($schedule));
        self::assertCount(1, $registrar->matchingEvents($schedule));
        self::assertSame('* * * * *', $registrar->matchingEvents($schedule)[0]->getExpression());
    }

    public function test_second_register_is_noop_no_duplicate(): void
    {
        $schedule = new Schedule('UTC');
        $registrar = new ScheduleTickRegistrar;

        self::assertTrue($registrar->register($schedule));
        self::assertFalse($registrar->register($schedule));
        self::assertCount(1, $registrar->matchingEvents($schedule));
        self::assertCount(1, $schedule->events());
    }

    public function test_detects_preexisting_host_registration(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->command(ScheduleTickRegistrar::COMMAND)->hourly();

        $registrar = new ScheduleTickRegistrar;
        self::assertTrue($registrar->isRegistered($schedule));
        self::assertFalse($registrar->register($schedule));
        self::assertCount(1, $registrar->matchingEvents($schedule));
    }

    public function test_provider_wires_call_after_resolving_schedule(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($root.'/src/SocialAutomationServiceProvider.php');
        $registrarSrc = (string) file_get_contents($root.'/src/Scheduling/ScheduleTickRegistrar.php');

        self::assertStringContainsString('registerScheduleTick', $provider);
        self::assertStringContainsString('callAfterResolving(Schedule::class', $provider);
        self::assertStringContainsString('ScheduleTickRegistrar', $provider);
        self::assertStringContainsString('withoutOverlapping', $registrarSrc);
        self::assertStringContainsString(ScheduleTickRegistrar::COMMAND, $registrarSrc);
        self::assertStringNotContainsString('routes/console.php', $provider);
    }

    public function test_tick_command_still_calls_recheck_due_campaigns(): void
    {
        $cmd = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/SocialAutomationScheduleTickCommand.php');
        self::assertStringContainsString('recheckDueCampaigns', $cmd);
        self::assertStringContainsString('social-automation:schedule-tick', $cmd);
    }
}
