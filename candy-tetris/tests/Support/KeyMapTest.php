<?php

/**
 * candy-tetris/tests/Support/KeyMapTest.php
 *
 * Pins the key-map loader (finding m27): a missing file resolves to the
 * shipped table byte-for-byte, a valid override lands on the registry,
 * malformed files refuse LOUDLY naming the path (the bin turns the refusal
 * into the same visible-notice-then-defaults discipline the scores store
 * uses — an ignored key file must never silently change how the game plays
 * without the notice, and never brick the launch).
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Support\Bindings;
use SugarCraft\Tetris\Support\KeyMap;
use SugarCraft\Tetris\Support\Paths;
use RuntimeException;

final class KeyMapTest extends TestCase
{
    /** @var string */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-tetris-keys-' . uniqid('', true) . '/';
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        // scandir, not glob(): a glob-shaped string literal in a test joins
        // the PathGlob corpus and drifts its pinned figure (GlobDialect law).
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->dir . $entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testMissingFileKeepsTheShippedTableByteForByte(): void
    {
        $table = Bindings::table();

        // The m27 compatibility promise: no file, no behavior change —
        // not one entry of the resolved map differs from the defaults.
        self::assertSame(
            $table->map(),
            KeyMap::load($this->dir . 'absent.json', $table)->map(),
        );
        self::assertSame($table->map(), KeyMap::load(null, $table)->map());
    }

    public function testValidOverrideAppliesAndLeavesTheRestAlone(): void
    {
        $path = $this->write('{"hold": ["b"]}');
        $bindings = KeyMap::load($path, Bindings::table());

        self::assertSame(['b'], $bindings->tokens('hold'));
        self::assertTrue($bindings->matches(new KeyMsg(KeyType::Char, 'b'), 'hold'));
        self::assertFalse($bindings->matches(new KeyMsg(KeyType::Char, 'c'), 'hold'));
        self::assertSame(['q', 'ctrl+c'], $bindings->tokens('quit'));
    }

    public function testOverrideTokensAreCaseFolded(): void
    {
        $path = $this->write('{"quit": ["X"]}');
        $bindings = KeyMap::load($path, Bindings::table());

        self::assertSame(['x'], $bindings->tokens('quit'));
    }

    public function testUnknownActionIdIsAcceptedAndInert(): void
    {
        // The registry is context-free: an action no screen dispatches is
        // kept (it costs nothing) and changes nothing that is dispatched.
        $path = $this->write('{"warp-drive": ["w"]}');
        $bindings = KeyMap::load($path, Bindings::table());

        self::assertSame(['w'], $bindings->tokens('warp-drive'));
        self::assertTrue($bindings->matches(new KeyMsg(KeyType::Char, 'q'), 'quit'));
    }

    public function testScalarValueIsRefusedNamingThePath(): void
    {
        $this->expectRefusal('{"quit": "escape"}');
    }

    public function testEmptyTokenListIsRefusedNamingThePath(): void
    {
        $this->expectRefusal('{"quit": []}');
    }

    public function testAssocTokenListIsRefusedNamingThePath(): void
    {
        $this->expectRefusal('{"quit": {"a": "b"}}');
    }

    public function testTokenBearingSpaceIsRefusedNamingThePath(): void
    {
        // Chords are a declared v1 non-goal; the loader refuses the shape
        // rather than accepting a token no decoder can ever produce.
        $this->expectRefusal('{"quit": ["ctrl q"]}');
    }

    public function testTokenBearingEscapeBytesIsRefusedAtTheDoor(): void
    {
        // m23: the help card, the versus strip and the on-ramp label print
        // the LIVE token verbatim. The stderr notice channel clamps control
        // bytes through Support\CliVisible::clamp(); the frame had no matching
        // door, so a crafted keys.json carrying ESC bytes would paint raw
        // escape sequences into the terminal on every render. The refusal
        // closes the asymmetry at the validation gate instead.
        $this->expectRefusal('{"quit": ["\u001b[31m"]}', 'printable key name');
    }

    public function testTokenBearingBareControlByteIsRefusedAtTheDoor(): void
    {
        $this->expectRefusal('{"hold": ["a\u0007b"]}', 'printable key name');
    }

    public function testTokenPastTheLengthCeilingIsRefusedAtTheDoor(): void
    {
        // 33 bytes is one past the widest shape the door admits; no named
        // key the decoder emits is anywhere near this long.
        $this->expectRefusal('{"quit": ["' . str_repeat('x', 33) . '"]}', 'printable key name');
    }

    public function testTokenAtTheLengthCeilingStillFailsTheVocabGate(): void
    {
        // The shape ceiling and the vocabulary gate are two doors. A 32-byte
        // run is printable and AT the length limit (so it clears the shape
        // door that rejects 33), but it names no real key — the vocab gate
        // added by candy-tetris-032 refuses it by name. This is the whole
        // point: 'xxt' used to load "valid" and strand the binding deaf.
        $this->expectRefusal('{"quit": ["' . str_repeat('x', 32) . '"]}', 'unknown key name');
    }

    public function testInVocabTokensBelowTheCeilingAreAccepted(): void
    {
        // The accepted-shape pair: single runes and named keys pass BOTH
        // doors (printable, bounded, and real), so the vocab gate costs
        // nothing the decoder can actually emit.
        $path = $this->write('{"quit": ["q", "enter", "backspace"]}');
        $bindings = KeyMap::load($path, Bindings::table());

        self::assertSame(['q', 'enter', 'backspace'], $bindings->tokens('quit'));
    }

    public function testTheCtrlChordVocabularyIsAcceptedAndFolded(): void
    {
        // candy-tetris-034: 'ctrl+c' is a live token in the shipped table,
        // so the vocab gate must admit it (upper/lower folded the same way
        // as plain names) — and reject a chord whose base names no key.
        $path = $this->write('{"quit": ["CTRL+C"]}');
        self::assertSame(['ctrl+c'], KeyMap::load($path, Bindings::table())->tokens('quit'));

        $this->expectRefusal('{"quit": ["ctrl+spce"]}', 'unknown key name');
    }

    public function testWideNamedKeyAndMultibyteRunePassThePrintableGate(): void
    {
        // 'backspace' is the widest named token the shipped decoder answers
        // with; a multibyte rune is legal at one character. Neither may be
        // collateral of the m23 door.
        $path = $this->write('{"quit": ["backspace", "é"]}');
        $bindings = KeyMap::load($path, Bindings::table());

        self::assertSame(['backspace', 'é'], $bindings->tokens('quit'));
    }

    public function testEmptyTokenIsRefusedNamingThePath(): void
    {
        $this->expectRefusal('{"quit": [""]}');
    }

    public function testTopLevelListIsRefusedNamingThePath(): void
    {
        $this->expectRefusal('[1, 2]');
    }

    public function testSymlinkedSlotIsRefusedInsteadOfReadThrough(): void
    {
        // is_file() follows links, so a planted keys.json -> <valid map>
        // would have been folded silently. lstat never follows: the slot
        // itself must be a plain file.
        $target = $this->dir . 'target.json';
        file_put_contents($target, '{"hold": ["b"]}');
        $link = $this->dir . 'keys.json';
        symlink($target, $link);

        try {
            KeyMap::load($link, Bindings::table());
            self::fail('a symlinked slot must be refused, not opened through the link');
        } catch (RuntimeException $problem) {
            self::assertStringContainsString($link, $problem->getMessage());
            self::assertStringContainsString('not a regular file', $problem->getMessage());
        }
    }

    public function testSpecialFileAtTheSlotIsRefusedLoudlyNeverOpened(): void
    {
        // A socket (same family as a FIFO) would hand the reader an open()
        // that blocks startup waiting for a writer that never comes. The
        // gate costs the session its overrides with a named notice — the
        // binary's loud-ignore channel — instead of hanging the launch.
        $path = $this->dir . 'slot.sock';
        $server = @stream_socket_server('unix://' . $path, $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped('unix sockets unavailable here: ' . $errstr);
        }

        try {
            KeyMap::load($path, Bindings::table());
            self::fail('a socket at the slot must be refused, not opened');
        } catch (RuntimeException $problem) {
            self::assertStringContainsString('not a regular file', $problem->getMessage());
        } finally {
            fclose($server);
            @unlink($path);
        }
    }

    public function testRelativeConfigEnvironmentResolvesToNoUserTable(): void
    {
        // XDG defines only absolute paths as meaningful, and the Paths
        // docblock promises absolute results — a relative value must be
        // ignored, not passed through to depend on the working directory.
        $home = getenv('HOME');
        $xdg = getenv('XDG_CONFIG_HOME');
        try {
            putenv('XDG_CONFIG_HOME=' . $this->dir . 'xdg');
            putenv('HOME=/candy-tetris-pin-home-for-pins-only');
            self::assertSame(
                $this->dir . 'xdg/candy-tetris/keys.json',
                Paths::keys(),
                'an absolute XDG wins over HOME',
            );

            putenv('XDG_CONFIG_HOME=relative/.config');
            self::assertSame(
                '/candy-tetris-pin-home-for-pins-only/.config/candy-tetris/keys.json',
                Paths::keys(),
                'a relative XDG is ignored and the absolute HOME answers',
            );

            putenv('HOME=relative-home');
            self::assertNull(Paths::keys(), 'a relative HOME names no home');
            self::assertNull(Paths::scores());
        } finally {
            if ($home === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $home);
            }
            if ($xdg === false) {
                putenv('XDG_CONFIG_HOME');
            } else {
                putenv('XDG_CONFIG_HOME=' . $xdg);
            }
        }
    }

    private function expectRefusal(string $json, string $detail = ''): void
    {
        $path = $this->write($json);
        $table = Bindings::table();

        try {
            KeyMap::load($path, $table);
            self::fail('expected a RuntimeException naming ' . $path);
        } catch (RuntimeException $problem) {
            self::assertStringContainsString($path, $problem->getMessage());
            if ($detail !== '') {
                self::assertStringContainsString($detail, $problem->getMessage());
            }
        }
    }

    private function write(string $json): string
    {
        $path = $this->dir . 'keys.json';
        file_put_contents($path, $json);

        return $path;
    }
}
