<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Backend\Support\ScaledClockLoop;
use SugarCraft\Crush\Tests\Backend\Support\StreamingDouble;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap N-P4a: the 120 s no-progress watchdog is the `turnIdleTimeoutSeconds`
 * setting, resolved by the PARENT before the fork, and the parallel-group
 * deadline is held under whatever ceiling that resolves to.
 *
 * Two halves. The resolver arms pin the parsing doctrine (nonsense falls back,
 * never clamps; there is no "off"). The clock arms drive a real fork on
 * {@see ScaledClockLoop} and prove the PARENT's timer is the configured one in
 * both directions: a raised ceiling lets a silence the default would kill
 * finish, and a lowered one kills a silence the default would let finish —
 * so neither can pass on a build that still arms the constant.
 */
final class TurnIdleTimeoutSettingTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    private string|false $originalDeadlineEnv;

    private ?LoopInterface $previousLoop = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_idle_setting_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');

        $this->originalDeadlineEnv = getenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE');
        putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE');
    }

    protected function tearDown(): void
    {
        if ($this->previousLoop !== null) {
            Loop::set($this->previousLoop);
            $this->previousLoop = null;
        }

        self::pinForkCeiling(null);

        $this->originalDeadlineEnv === false
            ? putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE')
            : putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE=' . $this->originalDeadlineEnv);
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    // =========================================================================
    // The resolver
    // =========================================================================

    public function testTheDefaultIsTheConstantTheSchemaNames(): void
    {
        self::assertSame(EngineBackend::COMPLETE_TIMEOUT_SECONDS, self::resolvedIdle(null));
        self::assertSame(EngineBackend::COMPLETE_TIMEOUT_SECONDS, SettingsSchema::byKey('turnIdleTimeoutSeconds')?->default);
        self::assertSame(EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS, SettingsSchema::byKey('turnIdleTimeoutSeconds')?->min);
    }

    public function testThePersistedSettingIsRead(): void
    {
        Bootstrap::writeUserConfig(['turnIdleTimeoutSeconds' => 300]);

        self::assertSame(300, self::resolvedIdle(null));
    }

    #[DataProvider('honoured')]
    public function testAUsableValueIsHonoured(mixed $value, int $expected): void
    {
        self::assertSame($expected, self::resolvedIdle(['turnIdleTimeoutSeconds' => $value]));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function honoured(): array
    {
        return [
            'the floor itself' => [EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS, EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS],
            'raised' => [1800, 1800],
            'numeric string from a hand-edited file' => ['300', 300],
            'fraction truncates' => [45.9, 45],
        ];
    }

    #[DataProvider('nonsense')]
    public function testNonsenseFallsBackToTheDefaultRatherThanBeingClamped(mixed $value): void
    {
        self::assertSame(EngineBackend::COMPLETE_TIMEOUT_SECONDS, self::resolvedIdle(['turnIdleTimeoutSeconds' => $value]));
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonsense(): array
    {
        return [
            'under the floor' => [EngineBackend::MIN_TURN_IDLE_TIMEOUT_SECONDS - 1],
            'zero is not "off"' => [0],
            'negative' => [-60],
            'word' => ['soon'],
            'empty string' => [''],
            'bool' => [true],
            'list' => [[300]],
            'infinite' => [INF],
            'not a number' => [NAN],
            'past the int range' => [1e19],
        ];
    }

    // =========================================================================
    // The parallel-group deadline sits under the configured ceiling
    // =========================================================================

    public function testARaisedCeilingAdmitsADeadlineTheDefaultWouldRefuse(): void
    {
        $deadline = EngineBackend::COMPLETE_TIMEOUT_SECONDS + 30;

        self::assertSame(
            Runtime::PARALLEL_TOOL_DEADLINE_SECONDS,
            self::resolvedDeadline(['parallelToolDeadlineSeconds' => $deadline]),
            'control: under the default ceiling the same deadline is refused',
        );
        self::assertSame($deadline, self::resolvedDeadline([
            'turnIdleTimeoutSeconds' => 600,
            'parallelToolDeadlineSeconds' => $deadline,
        ]));
    }

    public function testALoweredCeilingPullsTheDefaultDeadlineUnderIt(): void
    {
        self::assertSame(39, self::resolvedDeadline(['turnIdleTimeoutSeconds' => 40]));
        self::assertSame(39, self::resolvedDeadline(['turnIdleTimeoutSeconds' => 40, 'parallelToolDeadlineSeconds' => 45]), 'a deadline past the ceiling is refused');
        self::assertSame(20, self::resolvedDeadline(['turnIdleTimeoutSeconds' => 40, 'parallelToolDeadlineSeconds' => 20]));
    }

    public function testTheEnvDeadlineIsJudgedAgainstTheSameCeiling(): void
    {
        putenv('SUGARCRUSH_PARALLEL_TOOL_DEADLINE=150');

        self::assertSame(150, self::resolvedDeadline(['turnIdleTimeoutSeconds' => 600]));
        self::assertSame(Runtime::PARALLEL_TOOL_DEADLINE_SECONDS, self::resolvedDeadline([]));
    }

    /**
     * Inside a forked turn the ceiling is the one the PARENT armed, not a
     * second read of the file: a save between fork and the child's read must
     * not let the group outlive the timer that will kill it.
     */
    public function testInsideAForkedTurnTheParentsCeilingWins(): void
    {
        self::pinForkCeiling(50);

        self::assertSame(49, self::resolvedDeadline(['turnIdleTimeoutSeconds' => 600, 'parallelToolDeadlineSeconds' => 100]));
        self::assertSame(45, self::resolvedDeadline(['turnIdleTimeoutSeconds' => 600, 'parallelToolDeadlineSeconds' => 45]));
    }

    public function testTheSchemaRefusesADeadlineAtOrPastTheCeiling(): void
    {
        $definition = SettingsSchema::byKey('turnIdleTimeoutSeconds');
        self::assertNotNull($definition);

        self::assertNotNull($definition->validate(60, ['turnIdleTimeoutSeconds' => 60, 'parallelToolDeadlineSeconds' => 60]));
        self::assertNull($definition->validate(60, ['turnIdleTimeoutSeconds' => 60, 'parallelToolDeadlineSeconds' => 59]));
        self::assertNull($definition->validate(60, ['turnIdleTimeoutSeconds' => 60]), 'an unset deadline is not judged');
    }

    // =========================================================================
    // The parent arms the configured ceiling — real fork, scaled clock
    // =========================================================================

    /**
     * A provider silent for 400+ virtual seconds — the default ceiling's own
     * known-positive kill ({@see ReasoningProgressTest}) — finishes when the
     * operator raised the ceiling past it.
     */
    public function testARaisedCeilingLetsALongSilenceFinish(): void
    {
        $this->requirePcntlFork();
        Bootstrap::writeUserConfig(['turnIdleTimeoutSeconds' => 3600]);

        $run = $this->runForkOnScaledClock(new StreamingDouble(40, 20_000, 'silent', 'the answer'));

        self::assertFalse($run['realCeiling'], 'the harness ran out of REAL time - nothing below is a verdict');
        if ($run['error'] !== null) {
            self::fail('a silence under the configured ceiling was killed: ' . $run['error']->getMessage());
        }
        self::assertSame('the answer', $run['message']?->content);
        self::assertGreaterThan(
            (float) EngineBackend::COMPLETE_TIMEOUT_SECONDS,
            $run['virtualSeconds'],
            'the silence never outlasted the DEFAULT ceiling, so surviving it proves nothing',
        );
    }

    /**
     * The mirror: a silence of 60+ virtual seconds — one the DEFAULT ceiling
     * lets finish — is killed under a 30 s ceiling, and the rejection names
     * the configured figure. A build that still armed the constant would
     * deliver the answer here instead.
     */
    public function testALoweredCeilingKillsAtTheConfiguredFigure(): void
    {
        $this->requirePcntlFork();
        Bootstrap::writeUserConfig(['turnIdleTimeoutSeconds' => 30]);

        $run = $this->runForkOnScaledClock(new StreamingDouble(6, 20_000, 'silent', 'the answer'));

        self::assertFalse($run['realCeiling'], 'the harness ran out of REAL time - nothing below is a verdict');
        self::assertTrue($run['settled']);
        self::assertNotNull($run['error'], 'a silence past the configured ceiling must be killed');
        self::assertStringContainsString('timed out after 30s without progress', $run['error']->getMessage());
    }

    // =========================================================================
    // helpers
    // =========================================================================

    /** @param ?array<string, mixed> $config */
    private static function resolvedIdle(?array $config): int
    {
        return (new \ReflectionMethod(EngineBackend::class, 'turnIdleTimeoutSeconds'))->invoke(null, $config);
    }

    /** @param array<string, mixed> $config */
    private static function resolvedDeadline(array $config): int
    {
        return (new \ReflectionMethod(EngineBackend::class, 'parallelToolDeadlineSeconds'))->invoke(null, $config);
    }

    private static function pinForkCeiling(?int $seconds): void
    {
        (new \ReflectionProperty(EngineBackend::class, 'forkIdleCeilingSeconds'))->setValue(null, $seconds);
    }

    private function requirePcntlFork(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() falls back to a blocking, timer-less path without pcntl');
        }
    }

    /**
     * @return array{settled: bool, message: ?Message, error: ?\Throwable, virtualSeconds: float, realCeiling: bool}
     */
    private function runForkOnScaledClock(ProviderInterface $provider): array
    {
        $backend = EngineBackend::new($provider, 'scaled');

        $loop = new ScaledClockLoop();
        $this->previousLoop = Loop::get();
        Loop::set($loop);

        $settled = false;
        $value = null;
        $error = null;

        try {
            $promise = $backend->completeAsync([Message::user('wait for it')], static function (string $t): void {
            });
            $this->driveUntilSettled($promise, $loop, $settled, $value, $error);
        } finally {
            Loop::set($this->previousLoop);
            $this->previousLoop = null;
        }

        return [
            'settled' => $settled,
            'message' => $value instanceof Message ? $value : null,
            'error' => $error,
            'virtualSeconds' => $loop->highWaterVirtualSeconds(),
            'realCeiling' => $loop->hitRealCeiling(),
        ];
    }

    private function driveUntilSettled(PromiseInterface $promise, ScaledClockLoop $loop, bool &$settled, mixed &$value, ?\Throwable &$error): void
    {
        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$error, $loop): void {
                $settled = true;
                $error = $e;
                $loop->stop();
            },
        );

        // ScaledClockLoop's own REAL ceiling bounds this; an addTimer() here
        // would run on the scaled clock and fire in milliseconds.
        if (!$settled) {
            $loop->run();
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
