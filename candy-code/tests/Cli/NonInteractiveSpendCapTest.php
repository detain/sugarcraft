<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Backend\EngineBackend;

/**
 * ISS-006: `$CANDY_CODE_MAX_COST` / the user-tier `maxCostUsd` setting must
 * be READ, VALIDATED (exit 2, `error.type: usage`), INSTALLED (the same
 * `EngineBackend::withSpendCap()` the host turn path uses) and REPORTED (a
 * refusal row under the distinct `budget` kind) on the `-p` path. Before this
 * lane the mechanism was TUI-only and the unattended CI path — the one with
 * `maxToolSteps` 1000 and bypass as the shipped default — ran a looping paid
 * agent forever.
 *
 * Every case injects its own backend or pins the pure helper, so no test here
 * can bill a real provider, and the HOME sandbox keeps a developer's own
 * `maxCostUsd` setting from leaking into the null-cap expectations.
 */
final class NonInteractiveSpendCapTest extends TestCase
{
    private string $home = '';
    private string $originalHome = '';
    private string|false $originalCap = false;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/iss006_home_' . uniqid('', true);
        mkdir($this->home . '/.candy-code', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        $this->originalCap = getenv('CANDY_CODE_MAX_COST');
        putenv('HOME=' . $this->home);
        putenv('CANDY_CODE_MAX_COST');
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->originalHome);
        putenv($this->originalCap === false ? 'CANDY_CODE_MAX_COST' : 'CANDY_CODE_MAX_COST=' . $this->originalCap);
        self::rrmdir($this->home);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * A present-but-unusable ceiling refuses the run at exit 2 with the
     * usage document — the repro's `5USD`, which previously ran uncapped at
     * exit 0 while the TUI refused the same launch.
     */
    public function testAnUnusableCeilingRefusesTheRunAsAUsageError(): void
    {
        putenv('CANDY_CODE_MAX_COST=5USD');

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'go']),
            $this->answeringBackend(),
            NonInteractive::FORMAT_JSON,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_CONFIG, $code);
        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('usage', $decoded['error']['type']);
        self::assertStringContainsString('5USD', $decoded['error']['message']);
    }

    /**
     * Zero is a refused figure, not "no cap" — same contract the variable's
     * TUI validation applies, and the same exit-2 channel.
     */
    public function testZeroIsRefusedRatherThanReadAsNoCap(): void
    {
        putenv('CANDY_CODE_MAX_COST=0');

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'go']),
            $this->answeringBackend(),
            NonInteractive::FORMAT_TEXT,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_CONFIG, $code);
        self::assertSame('', trim($stdout), 'a usage refusal puts no answer on stdout');
    }

    /**
     * The install half on a real engine: the cap lands as the engine's
     * `spendCapUsd` with a zero starting ledger, exactly the argument pair
     * `TurnRunner` passes to the same method.
     */
    public function testAUsableCeilingIsInstalledOnAnEngineBackend(): void
    {
        $backend = NonInteractive::withHeadlessSpendCap(
            EngineBackend::new(new EchoProvider(), 'echo'),
            2.5,
        );

        self::assertInstanceOf(EngineBackend::class, $backend);
        $property = new \ReflectionProperty(EngineBackend::class, 'spendCapUsd');
        $property->setAccessible(true);
        self::assertSame(2.5, $property->getValue($backend));
    }

    /**
     * Immutability polarity: no cap, and the backend object is returned
     * identically — `run()` must not re-mint engines for runs the operator
     * never capped, and a non-engine backend has no cap to carry.
     */
    public function testNoCapAndNonEnginesPassTheBackendThroughUntouched(): void
    {
        $engine = EngineBackend::new(new EchoProvider(), 'echo');
        self::assertSame($engine, NonInteractive::withHeadlessSpendCap($engine, null));

        $plain = $this->answeringBackend();
        self::assertSame($plain, NonInteractive::withHeadlessSpendCap($plain, 5.0));
    }

    /**
     * The reporting half, end to end: an engine loop that emits the settled
     * breach frame yields exit 0, the answer, a `budget` refusal row on the
     * JSON document, and the same row as a stderr line — the machine channel
     * can branch on `kind` without reading prose.
     */
    public function testABreachIsReportedAsABudgetRefusalRowOnBothChannels(): void
    {
        $backend = new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                if ($onEvent !== null) {
                    $onEvent(new SpendCapBreached(3, 2.75, 2.5));
                }

                return Message::assistant('partial answer before the cap');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'go']),
            $backend,
            NonInteractive::FORMAT_JSON,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code);
        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('partial answer before the cap', $decoded['result']);

        $rows = $decoded['refusals'] ?? [];
        self::assertCount(1, $rows);
        self::assertSame(NonInteractive::SPEND_CAP_ROW_KIND, $rows[0]['kind']);
        self::assertSame(NonInteractive::SPEND_CAP_ROW_TOOL, $rows[0]['tool']);
        // The reason is the host ledger's mid-turn sentence, numbers included:
        // "aborted after provider call 3" and both money figures.
        self::assertStringContainsString('provider call 3', $rows[0]['reason']);
        self::assertStringContainsString('$2.7500', $rows[0]['reason']);
        self::assertStringContainsString('$2.5000', $rows[0]['reason']);
    }

    /**
     * The unset variable is absence, not a refusal: with nothing configured
     * the run proceeds uncapped (the documented zero-config smoke shape).
     */
    public function testAnUnsetVariableMeansNoCapAndARun(): void
    {
        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'go']),
            $this->answeringBackend(),
            NonInteractive::FORMAT_JSON,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code);
        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('refusals', $decoded);
        self::assertSame('the answer', $decoded['result']);
    }

    private function answeringBackend(): Backend
    {
        return new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('the answer');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };
    }
}
