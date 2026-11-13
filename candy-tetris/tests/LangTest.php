<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Lang;

/**
 * The i18n catalogue must hand back exactly the strings the renderers
 * and the launch screen were built on: every value in lang/en.php is
 * byte-identical to the literal it replaced, which is what lets the
 * render goldens survive activation untouched. These pins are the
 * standing guard on that promise — a drifted translation of even one
 * space reddens them before a golden ever could.
 */
final class LangTest extends TestCase
{
    public function testUiStringsResolveByteIdentical(): void
    {
        // candy-tetris-005: the template is placeholder-owned; the shipped
        // bytes still read exactly as before when fed the default binding.
        self::assertSame('[ paused — press p to resume ]', Lang::t('paused', ['keys' => 'p']));
        self::assertSame('GAME OVER', Lang::t('over.title'));
        self::assertSame(
            'final score: 1234',
            Lang::t('over.final_score', ['points' => 1234]),
        );
        self::assertSame(
            "score:  1\nlines:  2\nlevel:  3",
            Lang::t('score_card', ['score' => 1, 'lines' => 2, 'level' => 3]),
        );
        self::assertSame('next:', Lang::t('next_label'));
        self::assertSame('hold:', Lang::t('hold_label'));
        self::assertSame(
            'candy-tetris: unknown mode "--puzzle"',
            Lang::t('mode.unknown', ['flag' => '--puzzle']),
        );
        self::assertSame('PLAYER', Lang::t('label.player'));
        self::assertSame('COMPUTER', Lang::t('label.computer'));
        self::assertSame('YOU WIN!', Lang::t('win.player'));
        self::assertSame('COMPUTER WINS!', Lang::t('win.computer'));
        // The launch/CLI diagnostic frame (candy-tetris-028/-033): named
        // interpolation, no English 'argument%s' append left alive.
        self::assertSame(
            'candy-tetris: only one flag is accepted, but 2 extra: "--puzzle", "-h"',
            Lang::t('launch.extras', ['count' => 2, 'list' => '"--puzzle", "-h"']),
        );
        self::assertSame(
            'candy-tetris: could not save scores (disk full)',
            Lang::t('score.save.failed', ['problem' => 'disk full']),
        );
        self::assertStringEndsWith(
            "  --version    Print the version\n",
            Lang::t('help_text'),
        );
        // m27: help_card is the VERB column now — the key column is
        // derived at render time from the live binding table (the
        // glyph-pairing pins live in BindingsDerivationTest).
        $help = Lang::t('help_card');
        self::assertStringContainsString("hard drop\nhold", $help);
        self::assertStringContainsString('restart', $help);
    }

    public function testMissingKeyFallsBackToTheNamespacedRawKey(): void
    {
        self::assertSame(
            'tetris.no.such.key',
            Lang::t('no.such.key'),
        );
    }
}
