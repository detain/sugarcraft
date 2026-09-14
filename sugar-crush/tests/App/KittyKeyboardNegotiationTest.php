<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * E705: the Kitty progressive-keyboard negotiation.
 *
 * Shift/Ctrl+Enter are physically indistinguishable from plain Enter on
 * legacy terminals — the same CR byte — so the ONLY fix is to ask capable
 * terminals to report them differently. This pins the two halves of the ask:
 * the single `CSI > 1 u` push on App::init()'s startup batch, and the
 * balanced `CSI < 1 u` pop after `Program::run()` in the binary (bin lines
 * are unreadable by execution — `run()` attaches to a TTY and blocks — so
 * the pop is pinned by source scan, the house pattern for the one link the
 * in-process tests cannot execute).
 *
 * @see \SugarCraft\Crush\App\App::init()
 * @see \SugarCraft\Crush\Tests\Chat\KittyEnterNewlineTest the decode-side pair
 */
final class KittyKeyboardNegotiationTest extends TestCase
{
    /** @return list<\Closure> */
    private function startupCmds(): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('stub');
        $app = App::new($provider, 'm')->withChat(new Chat());

        $init = $app->init();
        $this->assertNotNull($init, 'fixture: the shell has startup side effects');
        $batch = $init();
        $this->assertInstanceOf(BatchMsg::class, $batch);

        return $batch->cmds;
    }

    public function testTheStartupBatchPushesExactlyOneKittyLayer(): void
    {
        $pushes = [];
        foreach ($this->startupCmds() as $cmd) {
            $msg = $cmd();
            if ($msg instanceof RawMsg && str_contains($msg->bytes, "\x1b[>")) {
                $pushes[] = $msg->bytes;
            }
        }

        $this->assertCount(1, $pushes, 'exactly one push per program start — the pop in bin pops one layer');
        $this->assertSame(Ansi::pushKittyKeyboard(1), $pushes[0]);
        $this->assertSame("\x1b[>1u", $pushes[0], 'DISAMBIGUATE only');
    }

    public function testThePushCarriesNoEventTypesOrAlternateReportingBits(): void
    {
        // REPORT_EVENT_TYPES (bit 2) would flood update() with release/repeat
        // frames no arm consumes; bits 4/8/16 change encodings Chat does not
        // read. Read the flags off the bytes App ACTUALLY pushes on the
        // startup batch — asking the Ansi formatter whether it can spell
        // other values is a tautology that no App edit could ever redden —
        // so widening the negotiated mask reddens HERE naming the offending
        // bit, beside the exact-byte pin in the test above.
        $layers = [];
        foreach ($this->startupCmds() as $cmd) {
            $msg = $cmd();
            if ($msg instanceof RawMsg && \preg_match('/^\x1b\[>(\d+)u$/', $msg->bytes, $match) === 1) {
                $layers[] = (int) $match[1];
            }
        }

        $this->assertCount(1, $layers, 'fixture: App pushes exactly one Kitty layer on init');
        $flags = $layers[0];
        $this->assertSame(
            KeyboardEnhancementsMsg::DISAMBIGUATE,
            $flags,
            'DISAMBIGUATE is the whole ask — widen it only by re-judging every bit below',
        );
        foreach ([
            KeyboardEnhancementsMsg::REPORT_EVENT_TYPES => 'release/repeat frames no arm here consumes',
            KeyboardEnhancementsMsg::REPORT_ALTERNATES => 'alternate-key spelling Chat does not read',
            KeyboardEnhancementsMsg::REPORT_ALL_AS_ESC => 'all-as-escapes spelling Chat does not read',
            KeyboardEnhancementsMsg::REPORT_ASSOCIATED => 'associated-text payload Chat does not read',
        ] as $bit => $why) {
            $this->assertSame(0, $flags & $bit, "the Kitty push must leave bit {$bit} clear — {$why}");
        }
    }

    public function testModifyOtherKeysIsNeverEnabledAnywhereInTheTree(): void
    {
        // Hard rule (phase-3 probe): xterm's modifyOtherKeys spelling of
        // Enter+Shift is `CSI 27;2;13u`, which the Kitty-aware decode path
        // misparses as text. Enabling it ALONGSIDE Kitty is worse than either
        // protocol alone, so sugar-crush must never mention it at all.
        $roots = [
            \dirname(__DIR__, 2) . '/src',
            \dirname(__DIR__, 2) . '/bin',
        ];
        $scanned = 0;
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $scanned++;
                $text = (string) file_get_contents((string) $file->getPathname());
                $stripped = (string) preg_replace('~(/\*.*?\*/|//[^\n]*|#[^\n]*)~s', '', $text);
                $this->assertStringNotContainsString(
                    'modifyOtherKeys',
                    $stripped,
                    $file->getFilename() . ' names modifyOtherKeys in code — it must never be enabled beside Kitty (E705)',
                );
            }
        }
        $this->assertGreaterThan(100, $scanned, 'fixture: the sweep must actually walk the tree');
    }

    public function testTheBinaryPopsThePushedLayerAfterTheLoopEnds(): void
    {
        $bin = (string) file_get_contents(\dirname(__DIR__, 2) . '/bin/sugarcrush');

        $runAt = strpos($bin, '->run();');
        $popAt = strpos($bin, 'fwrite(STDOUT, Ansi::popKittyKeyboard());');
        $this->assertIsInt($runAt, 'fixture: the single Program::run() call site moved');
        $this->assertIsInt(
            $popAt,
            'E705: bin/sugarcrush must pop the Kitty layer after run() returns — QuitMsg, SIGINT '
            . 'and kill() all stop the loop without giving the model a turn to emit a Cmd, so no '
            . 'in-model teardown can cover every exit',
        );
        $this->assertGreaterThan($runAt, $popAt, 'the pop must FOLLOW run(), beside teardown, not precede it');
        $this->assertSame(
            1,
            substr_count($bin, 'popKittyKeyboard'),
            'exactly one pop balances exactly one push',
        );
    }
}
