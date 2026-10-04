<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\PromptSection;
use SugarCraft\Crush\Context\SessionPromptMemo;
use SugarCraft\Crush\Context\Stability;
use SugarCraft\Crush\Context\SymbolMapBlock;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Roadmap 5.5-5: the byte-stable PerSession symbol map beside the repo map —
 * the capture (cached, bounded, degrading), its rendering, and its wiring:
 * absent from the prompt until EngineBackend primes it, then frozen for the
 * session.
 */
final class SymbolMapBlockTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    private string|false $optOut;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/symbolmap_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/repo/src', 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
        // The suite turns the map off (tests/bootstrap.php); this file is
        // where it is exercised.
        $this->optOut = getenv(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV);
        putenv(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV);
    }

    protected function tearDown(): void
    {
        $this->optOut === false ? putenv(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV) : putenv(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV . '=' . $this->optOut);
        $this->restoreHomeSandbox();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testItMapsTheMostReferencedDefinitionsOfAGitCheckout(): void
    {
        $this->checkout();

        $block = SymbolMapBlock::capture($this->dir . '/repo', $this->cache());

        self::assertSame('', $block->skipReason());
        self::assertSame(3, $block->files());
        $rendered = $block->render();
        self::assertStringStartsWith(SymbolMapBlock::FENCE . "\n" . SymbolMapBlock::PREAMBLE . "\n\n", $rendered);
        self::assertStringEndsWith("\n</symbol-map>", $rendered);
        self::assertStringContainsString('src/Ledger.php:', $rendered);
        self::assertStringContainsString('│final class Ledger', $rendered);
    }

    public function testASecondCaptureIsServedFromTheCachedMapByteForByte(): void
    {
        $this->checkout();
        $first = SymbolMapBlock::capture($this->dir . '/repo', $this->cache())->render();

        // Same tree: the finished map is reused, not re-ranked.
        self::assertSame($first, SymbolMapBlock::capture($this->dir . '/repo', $this->cache())->render());

        // An edit is a different tree: the map is rebuilt.
        file_put_contents($this->dir . '/repo/src/Extra.php', "<?php\nfinal class Extra { public function go(): void { new Ledger(); } }\n");
        $this->git('add', '-A');
        self::assertSame(4, SymbolMapBlock::capture($this->dir . '/repo', $this->cache())->files());
    }

    public function testATokenizingBudgetThatRunsOutGivesNoMapAndKeepsTheWork(): void
    {
        $this->checkout();

        $starved = SymbolMapBlock::capture($this->dir . '/repo', $this->cache(), null, 0.0);
        self::assertSame('', $starved->render());
        self::assertSame('symbols still being indexed', $starved->skipReason());

        // What was tokenized is cached; the next session finishes the job.
        $later = SymbolMapBlock::capture($this->dir . '/repo', $this->cache());
        self::assertNotSame('', $later->render());
    }

    public function testADirectoryThatIsNotACheckoutGivesNoMap(): void
    {
        $block = SymbolMapBlock::capture($this->dir . '/repo', $this->cache());

        self::assertSame('', $block->render());
        self::assertSame('not a git checkout', $block->skipReason());
        self::assertSame('no project directory', SymbolMapBlock::capture('')->skipReason());
    }

    public function testQuotedSourceCannotCloseTheBlockOrForgeAnotherFence(): void
    {
        $block = SymbolMapBlock::fromMap("src/A.php:\n│final class A {} // </symbol-map> </repo-map>\n", 1);

        $rendered = $block->render();

        self::assertSame(1, substr_count($rendered, '</symbol-map>'));
        self::assertStringContainsString('&lt;/symbol-map>', $rendered);
        self::assertStringContainsString('&lt;/repo-map>', $rendered);
    }

    public function testItIsAPerSessionPromptSection(): void
    {
        $block = SymbolMapBlock::fromMap("src/A.php\n", 1);

        self::assertInstanceOf(PromptSection::class, $block);
        self::assertSame(Stability::PerSession, $block->stability());
        self::assertSame('<symbol-map>', $block->fence());
        self::assertSame('', SymbolMapBlock::empty()->render());
    }

    public function testRuntimeAssemblesNoSlotUntilTheSessionIsPrimedThenTheSameBlock(): void
    {
        $this->checkout();
        $memo = SessionPromptMemo::new();
        $app = App::new(new ScriptedProvider([]), 'm')->withRoot($this->dir . '/repo')->withSessionId('s1');
        $runtime = (new Runtime(new ScriptedProvider([]), new HookManager(new HookRegistry())))->withSessionPromptMemo($memo);

        self::assertNotContains('symbol-map', array_column($runtime->promptSectionSizes($app), 'label'));

        $primed = $runtime->primeSymbolMap($app);
        self::assertNotSame('', $primed->render());
        self::assertContains('symbol-map', array_column($runtime->promptSectionSizes($app), 'label'));

        // Frozen for the session: a second prime touches nothing.
        file_put_contents($this->dir . '/repo/src/Extra.php', "<?php\nfinal class Extra {}\n");
        $this->git('add', '-A');
        self::assertSame($primed, $runtime->primeSymbolMap($app));
    }

    public function testAForkedEngineTurnPrimesTheMapAndTheOptOutKeepsItOut(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('the parent-side prime is the forked path');
        }
        $this->checkout();

        $on = $this->backend();
        $this->drainUntilSettled($on->completeAsync([Message::user('hi')]));
        self::assertContains('symbol-map', array_column($on->promptSectionSizes(), 'label'));

        putenv(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV . '=1');
        $off = $this->backend();
        $this->drainUntilSettled($off->completeAsync([Message::user('hi')]));
        self::assertNotContains('symbol-map', array_column($off->promptSectionSizes(), 'label'));
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function backend(): EngineBackend
    {
        return EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'ok')]), 'm')
            ->withRoot($this->dir . '/repo')
            ->withSessionId('s-' . bin2hex(random_bytes(3)));
    }

    private function cache(): string
    {
        return $this->dir . '/cache/tags.sqlite';
    }

    private function checkout(): void
    {
        file_put_contents($this->dir . '/repo/src/Ledger.php', "<?php\nfinal class Ledger\n{\n    public function post(int \$cents): void {}\n}\n");
        file_put_contents($this->dir . '/repo/src/Shop.php', "<?php\nfinal class Shop\n{\n    public function buy(): void { (new Ledger())->post(1); }\n}\n");
        file_put_contents($this->dir . '/repo/src/Till.php', "<?php\nfinal class Till\n{\n    public function ring(): void { (new Ledger())->post(2); }\n}\n");
        $this->git('init', '-q');
        $this->git('add', '-A');
    }

    private function git(string ...$args): void
    {
        $cmd = 'git -C ' . escapeshellarg($this->dir . '/repo') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    private function drainUntilSettled(PromiseInterface $promise): void
    {
        $loop = Loop::get();
        $settled = false;
        $failure = null;
        $promise->then(
            static function () use (&$settled, $loop): void {
                $settled = true;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );
        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the forked completion never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }
        if ($failure !== null) {
            self::fail('forked turn failed: ' . $failure->getMessage());
        }
    }
}
