<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * scriptui-51 (finding 095): one loop, one source.
 *
 * The deadline and the Program used to resolve `Loop::get()` independently;
 * both happened to hit the same singleton today, but any future swap of the
 * shared singleton between the reads would arm the deadline on a loop nobody
 * runs — a fail-open timeout with zero symptom. The command files keep
 * exactly ONE capture site each, the loop travels into programOptions() from
 * there, and nobody re-reads the singleton behind the caller's back.
 */
final class SingleLoopSourceTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function commandProvider(): array
    {
        $files = glob(__DIR__ . '/../../src/Command/*Command.php');
        self::assertIsArray($files);
        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [basename($file)];
        }

        self::assertNotEmpty($cases);

        return $cases;
    }

    #[DataProvider('commandProvider')]
    public function testEachCommandCapturesTheLoopExactlyOnce(string $file): void
    {
        // scriptui-53 KEEP (purity): the count IS the contract — the defect
        // is a FUTURE second read, which no present-day behaviour run can
        // witness. -51's documented grep-pin, kept by the scriptui-53 ruling.
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $file);

        if (!str_contains($src, 'TimeoutGuard::arm')) {
            // Non-interactive commands (render-only, version) run no loop
            // at all — nothing to capture.
            self::assertSame(0, substr_count($src, 'Loop::get()'), $file . ' is non-interactive and must not resolve the loop');

            return;
        }

        self::assertSame(
            1,
            substr_count($src, 'Loop::get()'),
            $file . ' must resolve the event loop exactly once and pass that instance everywhere',
        );
    }

    public function testRuntimeTakesTheLoopFromItsCaller(): void
    {
        // scriptui-53 KEEP (purity): the options factory must be
        // parameter-driven; the singleton count pin is -51's cure text,
        // unobservable while caller and callee happen to agree.
        $src = (string) file_get_contents(__DIR__ . '/../../src/Runtime/InteractiveRuntime.php');

        // The headless nullness probe may resolve the singleton for itself
        // (it runs no Program and arms no deadline) — exactly one read — but
        // the options factory is parameter-driven: the same loop the command
        // armed its timer on is the loop the Program runs on.
        self::assertSame(1, substr_count($src, 'Loop::get()'));
        self::assertStringContainsString('LoopInterface $loop', $src);
        self::assertStringContainsString('loop:            $loop,', $src);
    }

    public function testNobodySwapsTheSingletonMidFlight(): void
    {
        // scriptui-53 KEEP (absence-purity): Loop::set() would only bite on
        // a host that swaps the singleton mid-flight — no in-suite run
        // reaches that state, so the absence is the observable.
        foreach (['src/Command', 'src/Runtime', 'src/Model'] as $dir) {
            foreach ((array) glob(__DIR__ . '/../../' . $dir . '/*.php') as $file) {
                $src = (string) file_get_contents((string) $file);
                self::assertStringNotContainsString(
                    'Loop::set(',
                    $src,
                    $file . ' must not re-point the shared loop — options and timers would diverge',
                );
            }
        }
    }
}
