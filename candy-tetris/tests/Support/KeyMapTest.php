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
        self::assertSame(['q'], $bindings->tokens('quit'));
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

    public function testEmptyTokenIsRefusedNamingThePath(): void
    {
        $this->expectRefusal('{"quit": [""]}');
    }

    public function testTopLevelListIsRefusedNamingThePath(): void
    {
        $this->expectRefusal('[1, 2]');
    }

    private function expectRefusal(string $json): void
    {
        $path = $this->write($json);
        $table = Bindings::table();

        try {
            KeyMap::load($path, $table);
            self::fail('expected a RuntimeException naming ' . $path);
        } catch (RuntimeException $problem) {
            self::assertStringContainsString($path, $problem->getMessage());
        }
    }

    private function write(string $json): string
    {
        $path = $this->dir . 'keys.json';
        file_put_contents($path, $json);

        return $path;
    }
}
