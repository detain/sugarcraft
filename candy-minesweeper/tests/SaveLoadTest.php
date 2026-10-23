<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;

/**
 * E3 (docs-fix-1023) MAJOR-1: the S/L savegame bindings — the wiring that
 * makes Board::serialize()/unserialize() reachable from production, plus the
 * 0600 AtomicJsonFile slot under $HOME/.config/candy-minesweeper/.
 *
 * HOME is sandboxed per test (snapshotted BEFORE any mutation, both restore
 * forms handled — a restore that only ever writes the set-form leaves the
 * variable unset and poisons later tests that read the developer's real HOME).
 */
final class SaveLoadTest extends TestCase
{
    private ?string $savedHome;
    private string $homeDir = '';

    protected function setUp(): void
    {
        $this->savedHome = getenv('HOME');
        $this->homeDir = sys_get_temp_dir() . '/candy-minesweeper-home-' . bin2hex(random_bytes(6));
        mkdir($this->homeDir, 0700, true);
        putenv('HOME=' . $this->homeDir);
    }

    protected function tearDown(): void
    {
        if ($this->savedHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->savedHome);
        }
        $slotDir = $this->homeDir . '/.config/candy-minesweeper';
        if (is_dir($slotDir)) {
            // FilesystemIterator, not glob: AtomicJsonFile's lock sidecar is a
            // dotfile, and glob('*') would miss it and leave the rmdir failing.
            foreach (new \FilesystemIterator($slotDir) as $residue) {
                @unlink($residue->getPathname());
            }
            @rmdir($slotDir);
        }
        @rmdir($this->homeDir . '/.config');
        @rmdir($this->homeDir);
    }

    private static function press(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    /** @param Closure(int):int $rand */
    private static function liveGame(): Game
    {
        $g = Game::start(6, 6, 4, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::press(KeyType::Space));       // arms clock, places mines
        [$g, ] = $g->update(self::press(KeyType::Right));
        [$g, ] = $g->update(self::press(KeyType::Char, 'f'));   // a flag to carry along
        return $g;
    }

    public function testSaveWritesTheSingleSlotUnderConfig(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));

        $path = Game::savePath();
        $this->assertNotNull($path, 'sanity: sandboxed HOME resolves');
        $this->assertSame(
            $this->homeDir . '/.config/candy-minesweeper/savegame.json',
            $path,
        );
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777, 'savegame must be owner-only');
    }

    public function testLoadRestoresTheIdenticalBoardInAFreshGame(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));
        $savedBoard   = $g->board->serialize();
        $savedCursorX = $g->cursorX;
        $savedCursorY = $g->cursorY;
        $savedElapsed = $g->elapsed();
        $this->assertNotNull($savedElapsed, 'sanity: live game carries a running clock');

        // A DIFFERENT game (other shape, virgin clock) receives the load.
        $h = Game::withDifficulty(Difficulty::EASY);
        [$h, ] = $h->update(self::press(KeyType::Char, 'L'));

        $this->assertSame($savedBoard, $h->board->serialize(), 'board restores byte-identical');
        $this->assertSame($savedCursorX, $h->cursorX);
        $this->assertSame($savedCursorY, $h->cursorY);
        $this->assertNotNull($h->startedAt);
        $this->assertEqualsWithDelta($savedElapsed, $h->elapsed(), 0.25, 'clock resumes from the saved reading');
    }

    public function testLoadWorksOnAFinishedBoard(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));
        $savedBoard = $g->board->serialize();

        // Hand-build an exploded board and load over it — 'L' sits above the
        // over-gate precisely so a lost game can step back to the save.
        $rows = [];
        for ($y = 0; $y < 4; $y++) {
            $row = [];
            for ($x = 0; $x < 4; $x++) {
                $row[] = new Cell($x === 0 && $y === 0, false, false, 0);
            }
            $rows[] = $row;
        }
        $dead = new Game(new Board(4, 4, 1, $rows, true, true, 0, 0), 0, 0, static fn(int $_max): int => 0);
        $this->assertTrue($dead->board->exploded, 'sanity: dead board fixture');

        [$loaded, ] = $dead->update(self::press(KeyType::Char, 'L'));
        $this->assertSame($savedBoard, $loaded->board->serialize());
    }

    public function testSaveIsBlockedAfterGameOver(): void
    {
        // Won fixture: every safe cell already revealed on a 3×3/1 board.
        $rows = [];
        $revealed = 0;
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $mine = $x === 2 && $y === 2;
                $row[] = new Cell($mine, !$mine, false, 0);
                if (!$mine) {
                    $revealed++;
                }
            }
            $rows[] = $row;
        }
        $g = new Game(new Board(3, 3, 1, $rows, true, false, $revealed, 0), 0, 0, static fn(int $_max): int => 0);
        $this->assertTrue($g->board->isWon(), 'sanity: won board fixture');

        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));

        $path = Game::savePath();
        $this->assertNotNull($path);
        $this->assertFileDoesNotExist($path, 'a finished game must never overwrite the slot');
    }

    public function testLoadWithoutAFileKeepsTheCurrentGame(): void
    {
        $g = self::liveGame();
        [$next, ] = $g->update(self::press(KeyType::Char, 'L'));
        $this->assertSame($g, $next, 'missing savegame is a quiet no-op');
    }

    public function testTamperedAndForeignVersionsAreRefused(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));
        $path = Game::savePath();
        $this->assertNotNull($path);

        foreach ([
            'garbage inner board' => '{"v":1,"board":"<<not json>>","cursorX":0,"cursorY":0,"elapsed":1.0}',
            'future envelope'     => '{"v":2,"board":' . json_encode($g->board->serialize()) . ',"cursorX":0,"cursorY":0}',
        ] as $label => $raw) {
            file_put_contents($path, $raw);
            [$refused, ] = $g->update(self::press(KeyType::Char, 'L'));
            $this->assertSame($g, $refused, "$label must not load");
        }
    }

    public function testSaveAndLoadDegradeQuietlyWithoutHome(): void
    {
        putenv('HOME');   // unset for this test only; tearDown restores the snapshot

        $g = self::liveGame();
        $this->assertNull(Game::savePath());
        [$saved, ] = $g->update(self::press(KeyType::Char, 'S'));
        $this->assertSame($g, $saved, 'save without HOME is a no-op, not a crash');
        [$loaded, ] = $g->update(self::press(KeyType::Char, 'L'));
        $this->assertSame($g, $loaded, 'load without HOME is a no-op, not a crash');
    }

    /**
     * The point of the feature: a game saved by one process restores inside a
     * FRESH PHP process. The child unserializes through the same library code
     * path 'L' uses and prints the board hash over a pipe — no shell, bounded
     * with a hard deadline, HOME passed through explicitly.
     */
    public function testCrossProcessSaveRoundTrip(): void
    {
        $g = self::liveGame();
        [$g, ] = $g->update(self::press(KeyType::Char, 'S'));
        $parentHash = hash('sha256', $g->board->serialize());

        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertIsString($autoload, 'sanity: linked vendor/autoload.php exists');
        $code = 'require $argv[1];'
            . '$p = json_decode(file_get_contents($argv[2]), true);'
            . 'echo hash("sha256", \SugarCraft\Minesweeper\Board::unserialize($p["board"])->serialize());';

        $proc = proc_open(
            [PHP_BINARY, '-r', $code, $autoload, Game::savePath() ?? ''],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['HOME' => $this->homeDir, 'PATH' => (string) getenv('PATH')],
        );
        $this->assertIsResource($proc);
        $this->assertIsResource($pipes[0]);
        $this->assertIsResource($pipes[1]);
        $this->assertIsResource($pipes[2]);
        fclose($pipes[0]);

        $deadline = microtime(true) + 10.0;
        $out = '';
        $err = '';
        while (microtime(true) < $deadline) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    // Pipe reached EOF — the child is done writing.
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $out .= $chunk;
                } else {
                    $err .= $chunk;
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running'] && $out !== '') {
                break;
            }
        }

        if (proc_get_status($proc)['running']) {
            proc_terminate($proc);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), "child load exited non-zero: $err");
        $this->assertSame($parentHash, $out, 'a fresh process restores the exact saved board');
    }
}
