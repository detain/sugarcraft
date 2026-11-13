<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tripwires on the bin's play-path tail (flappy-70 onward). The bin is the
 * one file whose regressions are invisible to every other suite: it is not
 * autoloaded by any Model test. flappy-29 (finding -032) established this
 * file as the deterministic source-pin seam — the repo precedent from the
 * renderer source-pin tests — instead of a full-pty play smoke, which the
 * finding itself lists as a follow-up candidate.
 */
final class BinPlayPathTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-flappy';

    public function testTheSettledNewBestGetsAPlainAck(): void
    {
        // flappy-70 (finding -070): a settling quit banks the record but
        // tears the alt screen down in the same tick — the exit site must
        // state the result in plain text after the restore.
        $src = (string) file_get_contents(self::BIN);

        // The settle leg captures the returned model: the banked run's
        // new-record flag is what the ack reads.
        self::assertStringContainsString('$final = $final->settleOnExit();', $src);
        // The ack itself, brand-prefixed like every other CLI line.
        self::assertStringContainsString("'candy-flappy: new best — ' . \$final->score", $src);
        // Gate law: a crashed board keeps its in-band banner silent-out; the
        // ack is for clean settling exits only.
        self::assertMatchesRegularExpression(
            '/\$final->newRecord && \$final->crashed === false/',
            $src,
            'the ack must stay gated on a non-crashed new record',
        );
        // Order law: the ack prints before the signal-death exit leg, so a
        // Ctrl+C that banked still says so before 128+signo closes the run.
        self::assertLessThan(
            (int) strpos($src, '$program->stopSignal()'),
            (int) strpos($src, 'new best — '),
            'the ack must precede the signal exit leg',
        );
    }

    public function testASignalDeathExitsConventionallyNotCleanly(): void
    {
        // flappy-29 (finding -032): pin the m5 leg — a run stopped by a
        // signal must leave the shell through 128+signo, the conventional
        // shape, never a bare exit 0 that would hide the death.
        $src = (string) file_get_contents(self::BIN);

        self::assertStringContainsString('$program->stopSignal()', $src);
        self::assertStringContainsString('exit(128 + $signal);', $src);
    }

    public function testTheLegacyMigrationRunsBeforeTheFirstGame(): void
    {
        // flappy-29: the rename migration (flappy-02's side leg) must
        // precede construction, or the first launch on a renamed machine
        // would write fresh scores where the old dir still stands.
        $src = (string) file_get_contents(self::BIN);

        self::assertStringContainsString('LegacyPaths::migrate(', $src);
        self::assertLessThan(
            (int) strpos($src, 'Game::start()'),
            (int) strpos($src, 'LegacyPaths::migrate('),
            'migration must run before the first Game exists',
        );
    }

    public function testThePipedStdoutPreflightIsStreamBased(): void
    {
        // flappy-29: post-01 the TTY question is answered by stream_isatty
        // on the actual STDOUT stream (posix_isatty can disagree with the
        // C runtime on redirected streams); the refusal seam depends on it.
        $src = (string) file_get_contents(self::BIN);

        self::assertStringContainsString('stream_isatty(STDOUT)', $src);
    }
}
