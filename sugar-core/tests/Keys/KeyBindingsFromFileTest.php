<?php

/**
 * sugar-core/tests/Keys/KeyBindingsFromFileTest.php
 *
 * Pins KeyBindings::fromFile(), the user-override door: a missing, empty or
 * whitespace-only file is the base unchanged, a good file replaces exactly
 * the actions it names (help text kept, chords canonicalized, an empty list
 * unbinds), and every malformed file — wrong inode type, oversized, bad
 * JSON, bad shape, bad chord — throws naming the path while applying
 * nothing.
 */

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Keys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Msg\KeyMsg;

final class KeyBindingsFromFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sc-keys-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (basename($path) === '.' || basename($path) === '..') {
                continue;
            }
            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
    }

    public function testMissingFileReturnsTheBaseUnchanged(): void
    {
        $base = self::base();

        self::assertSame($base, KeyBindings::fromFile($this->dir . '/absent.json', $base));
        self::assertSame([], KeyBindings::fromFile($this->dir . '/absent.json')->map());
    }

    public function testOverridesReplaceNamedActionsAndKeepTheRest(): void
    {
        $path = $this->write('keys.json', json_encode([
            'quit' => ['Ctrl+Q', 'esc'],
            'save' => 'F2',
            'help' => [],
        ], JSON_THROW_ON_ERROR));

        $b = KeyBindings::fromFile($path, self::base());

        self::assertSame(['ctrl+q', 'escape'], $b->tokens('quit'));
        self::assertSame(['f2'], $b->tokens('save'));
        self::assertSame([], $b->tokens('help'), 'an empty list unbinds');
        self::assertSame(['shift+tab'], $b->tokens('prev'), 'unnamed actions keep their chords');
        self::assertSame('quit the app', $b->help('quit'), 'help text survives an override');
        self::assertTrue($b->matches(new KeyMsg(KeyType::Char, 'q', ctrl: true), 'quit'));
        self::assertFalse($b->matches(new KeyMsg(KeyType::Char, 'q'), 'quit'));
        self::assertSame(['quit', 'save', 'help', 'prev'], $b->actions(), 'declaration order is kept');
    }

    public function testANumericActionIdIsReplacedNotDuplicated(): void
    {
        $path = $this->write('keys.json', '{"1": ["x"]}');

        $b = KeyBindings::fromFile($path, KeyBindings::fromMap(['1' => ['q']]));

        self::assertSame(['1'], $b->actions(), 'no phantom action is created');
        self::assertSame(['x'], $b->tokens('1'));
        self::assertSame('1', $b->actionFor(new KeyMsg(KeyType::Char, 'x')));
        self::assertNull($b->actionFor(new KeyMsg(KeyType::Char, 'q')), 'the replaced chord is gone');
    }

    public function testAnObjectWithSequentialNumericKeysIsStillAnObject(): void
    {
        $path = $this->write('keys.json', " \n{\"0\": [\"x\"], \"1\": \"y\"}");

        $b = KeyBindings::fromFile($path, KeyBindings::fromMap(['0' => ['q'], '1' => ['w']]));

        self::assertSame(['0', '1'], $b->actions(), 'no phantom action is created');
        self::assertSame(['x'], $b->tokens('0'));
        self::assertSame(['y'], $b->tokens('1'));
    }

    public function testAFileMayDeclareNewActionsOnAnEmptyBase(): void
    {
        $path = $this->write('keys.json', '{"jump": ["g"]}');

        self::assertSame(['jump' => ['g']], KeyBindings::fromFile($path)->map());
    }

    public function testAnEmptyOrWhitespaceOnlyFileIsNoOverrides(): void
    {
        $base = self::base();

        self::assertSame($base, KeyBindings::fromFile($this->write('empty.json', ''), $base));
        self::assertSame($base, KeyBindings::fromFile($this->write('blank.json', " \n\t\r\n"), $base));
        self::assertSame([], KeyBindings::fromFile($this->write('empty2.json', ''))->map());
    }

    public function testAnEmptyJsonArrayIsNoOverrides(): void
    {
        $path = $this->write('keys.json', '[]');

        self::assertSame(self::base()->map(), KeyBindings::fromFile($path, self::base())->map());
    }

    /** @return iterable<string, array{0: string}> */
    public static function malformedFiles(): iterable
    {
        yield 'bad json'           => ['{"quit": ['];
        yield 'scalar top level'   => ['"quit"'];
        yield 'list top level'     => ['[["q"]]'];
        yield 'empty action name'  => ['{"": ["q"]}'];
        yield 'number token'       => ['{"quit": [5]}'];
        yield 'object tokens'      => ['{"quit": {"a": "q"}}'];
        yield 'number tokens'      => ['{"quit": 5}'];
        yield 'typo key name'      => ['{"quit": ["spce"]}'];
        yield 'shift on a rune'    => ['{"quit": ["shift+q"]}'];
        yield 'escape byte'        => ['{"quit": ["\u001b[2J"]}'];
        yield 'bad one of many'    => ['{"save": ["ctrl+s"], "quit": ["ctrl+"]}'];
    }

    #[DataProvider('malformedFiles')]
    public function testMalformedFilesThrowNamingThePath(string $json): void
    {
        $path = $this->write('keys.json', $json);

        try {
            KeyBindings::fromFile($path, self::base());
            self::fail('a malformed key file loaded');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testSymlinkIsRefusedNotFollowed(): void
    {
        $target = $this->write('real.json', '{"quit": ["x"]}');
        $link = $this->dir . '/keys.json';
        symlink($target, $link);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($link);
        KeyBindings::fromFile($link, self::base());
    }

    public function testDirectoryIsRefused(): void
    {
        $slot = $this->dir . '/keys.json';
        mkdir($slot);

        $this->expectException(\RuntimeException::class);
        KeyBindings::fromFile($slot, self::base());
    }

    public function testOversizedFileIsRefusedBeforeParsing(): void
    {
        $path = $this->write('keys.json', '{"quit": ["q"]}' . str_repeat(' ', 1048576));

        $this->expectException(\RuntimeException::class);
        KeyBindings::fromFile($path, self::base());
    }

    private static function base(): KeyBindings
    {
        return KeyBindings::new()
            ->bind('quit', 'q', 'quit the app')
            ->bind('save', 'ctrl+s', 'save')
            ->bind('help', '?', 'help')
            ->bind('prev', 'shift+tab', 'previous');
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
