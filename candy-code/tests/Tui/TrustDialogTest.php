<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\Tui\TrustDialog;
use SugarCraft\Code\Theme;

/**
 * The C-SEC-5.c trust dialog component: key arms, the grant-all list, the
 * TUI width invariant, the byte snapshot, and the immutability of answers
 * carried as data.
 *
 * The component is dormant-wire (5.d mounts it); nothing here needs a Chat,
 * a Renderer or a filesystem — the dialog is a pure model over the
 * {@see TrustTarget} census output.
 */
final class TrustDialogTest extends TestCase
{
    /** @return list<TrustTarget> */
    private static function threeTargets(): array
    {
        return [TrustTarget::Hooks, TrustTarget::Mcp, TrustTarget::Settings];
    }

    private static function key(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune);
    }

    private static function esc(): KeyMsg
    {
        return new KeyMsg(KeyType::Escape);
    }

    // ── key arms ────────────────────────────────────────────────────────

    public function testGrantAnswersTheHighlightedRowAndMovesToTheNext(): void
    {
        $dialog = TrustDialog::new('/work/app', self::threeTargets(), 4);
        $this->assertSame(TrustTarget::Hooks, $dialog->current());
        $this->assertSame([], $dialog->granted());

        $after = $dialog->update(self::key('y'));
        $this->assertSame(TrustTarget::Mcp, $after->current());
        $this->assertSame([TrustTarget::Hooks], $after->granted());
        $this->assertSame([], $after->skipped());
        $this->assertSame(1, $after->cursor());
        $this->assertFalse($after->isComplete());
        $this->assertFalse($after->isDismissed());
    }

    public function testSkipRecordsTheRowAsSkippedAndMovesToTheNext(): void
    {
        $after = TrustDialog::new('/work/app', self::threeTargets())->update(self::key('n'));
        $this->assertSame([TrustTarget::Hooks], $after->skipped());
        $this->assertSame([], $after->granted());
        $this->assertSame(TrustTarget::Mcp, $after->current());
    }

    public function testGrantAllAnswersEveryRemainingRowInCensusOrder(): void
    {
        // Answer the first row `n`, then `a`: the skip keeps its answer
        // (the ledger law), the rest arrive as grants.
        $dialog = TrustDialog::new('/work/app', self::threeTargets(), 2)
            ->update(self::key('n'))
            ->update(self::key('a'));

        $this->assertTrue($dialog->isComplete());
        $this->assertSame([TrustTarget::Hooks], $dialog->skipped());
        $this->assertSame([TrustTarget::Mcp, TrustTarget::Settings], $dialog->granted());
        $this->assertNull($dialog->current());
        $this->assertSame([
            ['target' => TrustTarget::Hooks, 'granted' => false],
            ['target' => TrustTarget::Mcp, 'granted' => true],
            ['target' => TrustTarget::Settings, 'granted' => true],
        ], $dialog->choices());
    }

    public function testDismissalVoidsEveryAnswerAlreadyTaken(): void
    {
        $dialog = TrustDialog::new('/work/app', self::threeTargets())
            ->update(self::key('y'))
            ->update(self::key('y'))
            ->update(self::esc());

        $this->assertTrue($dialog->isDismissed());
        $this->assertFalse($dialog->isComplete());
        $this->assertTrue($dialog->isResolved());
        $this->assertSame([], $dialog->granted());
        $this->assertSame([], $dialog->skipped());
        $this->assertSame([], $dialog->choices());
    }

    public function testUnboundAndModifiedKeysLeaveTheModelUnmoved(): void
    {
        $dialog = TrustDialog::new('/work/app', self::threeTargets());

        foreach ([self::key('q'), self::key('Y'), new KeyMsg(KeyType::Enter)] as $msg) {
            $this->assertSame($dialog, $dialog->update($msg), 'unbound key changed the model');
        }

        // ctrl/alt-carried runes are not the modal's letters.
        $this->assertSame($dialog, $dialog->update(new KeyMsg(KeyType::Char, 'y', ctrl: true)));
        $this->assertSame($dialog, $dialog->update(new KeyMsg(KeyType::Char, 'a', alt: true)));
    }

    public function testKeysAfterResolutionAreIgnored(): void
    {
        $dismissed = TrustDialog::new('/work/app', self::threeTargets())->update(self::esc());
        $this->assertSame($dismissed, $dismissed->update(self::key('a')));

        $complete = TrustDialog::new('/work/app', [TrustTarget::Hooks])->update(self::key('y'));
        $this->assertTrue($complete->isComplete());
        $this->assertSame($complete, $complete->update(self::key('n')));
    }

    public function testAnEmptyCensusCompletesImmediatelyWithNoChoices(): void
    {
        $dialog = TrustDialog::new('/work/app', []);
        $this->assertTrue($dialog->isComplete());
        $this->assertTrue($dialog->isResolved());
        $this->assertSame([], $dialog->choices());
    }

    // ── immutability ────────────────────────────────────────────────────

    public function testEveryAnswerIsANewInstanceAndTheOriginalIsPristine(): void
    {
        $original = TrustDialog::new('/work/app', self::threeTargets(), 7);
        $answered = $original->update(self::key('y'));

        $this->assertNotSame($original, $answered);
        $this->assertSame(0, $original->cursor());
        $this->assertSame([], $original->granted());
        $this->assertSame(TrustTarget::Hooks, $original->current());
        $this->assertSame('/work/app', $original->root());
        $this->assertSame(self::threeTargets(), $original->targets());
    }

    // ── rendering ───────────────────────────────────────────────────────

    public function testNoFrameLineEverExceedsTheTerminalWidth(): void
    {
        $themes = [Theme::default()];
        $dialogs = [
            TrustDialog::new('/work/acme/some/fairly/long/path/to/a/project/root', self::threeTargets(), 1234),
            TrustDialog::new('/r', TrustTarget::cases(), 9)->update(self::key('n'))->update(self::key('y')),
            TrustDialog::new('/r', [TrustTarget::McpPin, TrustTarget::Commands], 1)->update(self::esc()),
            TrustDialog::new('', [TrustTarget::Settings], 0),
        ];

        foreach ($themes as $theme) {
            foreach ($dialogs as $dialog) {
                foreach (range(3, 200, 7) as $cols) {
                    foreach ([4, 8, 11, 12, 30] as $rows) {
                        $lines = explode("\n", $dialog->view($cols, $rows, $theme));
                        $this->assertCount($rows, $lines, "rows {$rows} not honoured at cols {$cols}");

                        foreach ($lines as $line) {
                            $this->assertLessThanOrEqual(
                                $cols,
                                Width::of(Ansi::strip($line)),
                                "over-wide line at cols {$cols}: {$line}",
                            );
                        }
                    }
                }
            }
        }
    }

    public function testTheViewCarriesTheEnabledProseAndTheFrozenRosterLine(): void
    {
        $plain = Ansi::strip(
            TrustDialog::new('/work/app', self::threeTargets(), 7)->view(64, 12, Theme::default()),
        );

        $this->assertStringContainsString('hooks run programs', $plain);
        $this->assertStringContainsString('MCP starts servers', $plain);
        $this->assertStringContainsString('settings may change 7 keys', $plain);
        $this->assertStringContainsString('Takes effect next launch', $plain);
        $this->assertStringContainsString('/work/app', $plain);
    }

    public function testTheGrantAllKeyNamesEveryLetterTheModalAnswers(): void
    {
        $plain = Ansi::strip(TrustDialog::new('/work/app', self::threeTargets())->view(72, 10, Theme::default()));

        $this->assertMatchesRegularExpression('/y grant.*a grant all.*n skip.*Esc dismiss/s', $plain);
    }

    /**
     * One canonical case pinned byte for byte: the open dialog, `y` on the
     * Hooks row, painted into a 64x12 band by the default theme. The ESC
     * bytes are spelled `~` inside the nowdoc so the fixture survives any
     * editor without invisible control codes.
     */
    public function testTheCanonicalFrameIsPinnedByteForByte(): void
    {
        $dialog = TrustDialog::new('/work/acme/app', self::threeTargets(), 7)
            ->update(self::key('y'));

        $expected = str_replace('~', "\x1b", <<<'TXT'
~[38;2;112;145;245m╭─ Trust this project? ────────────────────────╮~[0m
~[38;2;112;145;245m│~[0m ~[38;2;139;143;168m/work/acme/app~[0m                               ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m│~[0m ~[38;2;139;143;168m────────────────────────────────────────────~[0m ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m│~[0m ~[38;2;160;216;160m✓ Hooks — hooks run programs~[0m                 ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m│~[0m ~[1m~[38;2;230;201;138m› MCP servers — MCP starts servers~[0m           ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m│~[0m   Settings — settings may change 7 keys      ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m│~[0m ~[38;2;139;143;168m────────────────────────────────────────────~[0m ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m│~[0m ~[38;2;139;143;168mTakes effect next launch~[0m                     ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m│~[0m ~[38;2;139;143;168my grant · a grant all · n skip · Esc dismiss~[0m ~[38;2;112;145;245m│~[0m
~[38;2;112;145;245m╰──────────────────────────────────────────────╯~[0m


TXT);

        $this->assertSame($expected, $dialog->view(64, 12, Theme::default()));
    }
}
