<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\Tests\Support\PtyRun;

/**
 * CF-13 (closes candy-files-019): the rename commit must work IN THE LIVE
 * PTY, and the loop/signal shutdown must answer. Four independent reviewer
 * PTY reproductions had shown `R`+type+Enter mutating nothing (clean AND
 * colliding names), then `q` not quitting and SIGINT not terminating ≤1.2 s
 * while the same grammar outside rename behaved. The model-level submode
 * legs were correct all along (P2 disk-verify — do NOT re-fix); the fault
 * lived in the live-loop layer and has since been cured (sugar-core
 * Program::run stop-before-run arm d2f0201f4, signal multiplexing sc-16
 * ae07f782c). This is the regression the step's acceptance demands: it
 * boots bin/candy-files on a real pty through the S-X06 harness port
 * (Support/PtyRun) and pins the whole live path with condition-based
 * barriers only — no sleeps, no script(1).
 *
 * Honest capability gate: {@see PtyRun::unavailable()} skips with a NAMED
 * reason (Windows, missing sugar-pty/FFI/pcntl/posix, un-openable ptmx) —
 * a host without PTY capability never contributes a silent green.
 */
final class RenamePtyTest extends TestCase
{
    private const FRAME_END = "\x1b[?2026l";

    private string $left = '';
    private string $right = '';

    protected function setUp(): void
    {
        $why = PtyRun::unavailable();
        if ($why !== null) {
            $this->markTestSkipped($why);
        }
        $base = sys_get_temp_dir() . '/cf-pty-' . bin2hex(random_bytes(4));
        $this->left = $base . '/L';
        $this->right = $base . '/R';
        mkdir($this->left, 0700, true);
        mkdir($this->right, 0700, true);
        file_put_contents($this->left . '/old.txt', "hi\n");
    }

    protected function tearDown(): void
    {
        if ($this->left !== '') {
            exec('rm -rf ' . escapeshellarg(dirname($this->left)));
        }
    }

    /** Boot the app and prove the loop is turning: it must ANSWER the first key. */
    private function boot(): PtyRun
    {
        $run = PtyRun::start([$this->left, $this->right]);
        $this->assertTrue(
            $run->readUntil("\x1b[?1049h"),
            'alt screen was entered; got: ' . substr(json_encode($run->output()) ?: '', 0, 300)
        );
        // candy-files is event-driven: the diff-cached renderer paints a new
        // frame only on a model change, so there is no clock tick to wait
        // for. The proof the loop itself is turning is the repaint ANSWER to
        // the first keystroke — a stop requested before run() engaged (the
        // lost-stop hang class this row pins, cured by sugar-core d2f0201f4)
        // never produces it.
        $run->write('j');
        $this->assertTrue(
            $run->readUntilCount(self::FRAME_END, 2, 15.0),
            'the loop is alive: a second synchronized frame answered the key'
        );
        $this->assertTrue(
            $run->readUntil("\u{25B8}  old.txt"),
            'the cursor row shows the move onto old.txt; got: ' . substr(json_encode($run->output()) ?: '', 0, 400)
        );

        return $run;
    }

    public function testLiveRenameCommitMutatesFilesystemAndShowsStatus(): void
    {
        $run = $this->boot();

        $run->write('R');
        $this->assertTrue(
            $run->readUntil("rename 'old.txt' to:"),
            'the rename submode prompt names the cursor entry'
        );

        $run->write('new.txt');
        $this->assertTrue($run->readUntil('new.txt'), 'the typed buffer echoes into the frame');
        // Negative control: a buffer is not a commit — nothing on disk moves
        // before Enter, which is exactly the half the finding saw frozen.
        $this->assertFileExists($this->left . '/old.txt', 'pre-Enter: the source is untouched');
        $this->assertFileDoesNotExist($this->left . '/new.txt', 'pre-Enter: no target exists yet');

        $run->write("\r");
        // The status line rides styled cells (SGR interposes between them),
        // so the assert targets the family word plus the filesystem truth.
        $this->assertTrue(
            $run->readUntil('renamed'),
            'the commit status rides the live stream'
        );
        clearstatcache();
        $this->assertFileDoesNotExist($this->left . '/old.txt', 'Enter in the live PTY mutated the filesystem');
        $this->assertFileExists($this->left . '/new.txt', 'the renamed entry exists');

        $run->write('q');
        $this->assertSame(0, $run->reap(10.0), 'after a live rename, q still exits cleanly');
    }

    public function testLiveRenameOntoExistingNameReplacesWithHonestStatus(): void
    {
        file_put_contents($this->left . '/taken.txt', "x\n");
        // Sort order is ../, old.txt, taken.txt — boot()'s single j already
        // parks the cursor on old.txt.
        $run = $this->boot();

        $run->write('R');
        $this->assertTrue($run->readUntil("rename 'old.txt' to:"), 'rename submode opened on old.txt');
        $run->write('taken.txt');
        $run->write("\r");
        $this->assertTrue(
            $run->readUntil('renamed') && $run->readUntil('overwritten'),
            'the colliding commit states the overwrite (status.renamed_overwrote; tokens, not the full line — the diff-cached status row splits across SGR cells)'
        );
        clearstatcache();
        $this->assertFileDoesNotExist($this->left . '/old.txt');
        $this->assertSame("hi\n", file_get_contents($this->left . '/taken.txt'), 'the source bytes won the replace');

        $run->write('q');
        $this->assertSame(0, $run->reap(10.0));
    }

    public function testLiveQuitExitsZero(): void
    {
        $run = $this->boot();

        $run->write('q');
        $code = $run->reap(10.0);
        $this->assertNotNull($code, 'q terminated under the deadline instead of hanging (the finding froze here)');
        $this->assertSame(0, $code, 'q is a clean exit');
    }

    public function testSigintTerminatesWithinTheOneSecondBudget(): void
    {
        $run = $this->boot();

        // 2, not SIGINT: pcntl constants are load-time landmines on hosts
        // without the extension (the S-X06 lesson — a bare SIGINT on an
        // unarmed constant kills the whole suite before setUp can skip).
        $started = microtime(true);
        $run->signal(2);
        $code = $run->reap(2.0);

        $this->assertNotNull($code, 'SIGINT terminated under the deadline instead of hanging (the finding ran ≥1.2 s)');
        $this->assertSame(0, $code, 'a signal stop is a clean exit');
        $this->assertLessThan(2.0, microtime(true) - $started, 'the stop lands inside the bounded budget');
    }
}
