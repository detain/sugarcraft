<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tui\DirectoryPicker\DirectoryPicker;
use SugarCraft\Crush\Tui\DirectoryPicker\DirectoryPickerAction;

/**
 * The `/new` folder picker: browsing (up/down, into, parent, hidden, typed
 * and pasted paths), the same-root vs other-root decision, and a frame that
 * never paints a line wider than the terminal.
 */
final class DirectoryPickerTest extends TestCase
{
    private string $dir = '';

    private string $root = '';

    protected function setUp(): void
    {
        $this->dir = (string) \realpath(\sys_get_temp_dir()) . '/crush-dirpicker-' . \bin2hex(\random_bytes(6));
        $this->root = $this->dir . '/project';
        foreach (['alpha/inner', 'beta/.git', '.hidden', 'locked', \str_repeat('very-long-directory-name-', 6)] as $sub) {
            \mkdir($this->root . '/' . $sub, 0o700, true);
        }
        \file_put_contents($this->root . '/file.txt', 'never listed');
    }

    protected function tearDown(): void
    {
        \chmod($this->root . '/locked', 0o700);
        ProtocolFixture::removeTree($this->dir);
    }

    /** @param list<KeyMsg|string> $keys a string is one Char rune */
    private static function press(DirectoryPicker $picker, array $keys): array
    {
        $action = null;
        foreach ($keys as $key) {
            [$picker, $action] = $picker->update(\is_string($key) ? new KeyMsg(KeyType::Char, $key) : $key);
        }

        return [$picker, $action];
    }

    /** @return list<string> */
    private static function names(DirectoryPicker $picker): array
    {
        return \array_map(static fn ($e): string => $e->name, $picker->listing()->entries);
    }

    public function testItOpensOnTheRootWithStartHereHighlighted(): void
    {
        $picker = DirectoryPicker::open($this->root);

        self::assertSame($this->root, $picker->path());
        self::assertSame(0, $picker->selectedIndex());
        self::assertSame(['alpha', 'beta', 'locked', \str_repeat('very-long-directory-name-', 6)], self::names($picker), 'directories only, no dot-dirs');
        self::assertStringStartsWith('▶ ', $picker->rowLabels()[0]);
        self::assertStringContainsString('[project]', $picker->rowLabels()[2], 'beta holds .git');
    }

    public function testUpDownIntoAndBackToTheParent(): void
    {
        $picker = DirectoryPicker::open($this->root);
        [$picker] = self::press($picker, [new KeyMsg(KeyType::Up)]);
        self::assertSame(0, $picker->selectedIndex(), 'clamped at the top');
        [$picker] = self::press($picker, [new KeyMsg(KeyType::Down), new KeyMsg(KeyType::Enter)]);
        self::assertSame($this->root . '/alpha', $picker->path(), 'Enter on a directory opens it');
        [$picker] = self::press($picker, ['j', new KeyMsg(KeyType::Right)]);
        self::assertSame($this->root . '/alpha/inner', $picker->path(), 'j and → work too');
        [$picker] = self::press($picker, [new KeyMsg(KeyType::Backspace)]);
        self::assertSame($this->root . '/alpha', $picker->path());
        self::assertSame(1, $picker->selectedIndex(), 'the directory left is highlighted');
        [$picker] = self::press($picker, [new KeyMsg(KeyType::Left), 'h']);
        self::assertSame($this->dir, $picker->path(), 'no browse root in the TUI: up past the project');
        [$picker] = self::press($picker, [new KeyMsg(KeyType::End)]);
        self::assertSame(\count($picker->listing()->entries), $picker->selectedIndex());
    }

    public function testHiddenToggle(): void
    {
        [$shown] = self::press(DirectoryPicker::open($this->root), ['.']);
        self::assertTrue($shown->showsHidden());
        self::assertContains('.hidden', self::names($shown));
        [$hidden] = self::press($shown, ['.']);
        self::assertNotContains('.hidden', self::names($hidden));
    }

    public function testATypedOrPastedPath(): void
    {
        [$typing] = self::press(DirectoryPicker::open($this->root), ['/']);
        self::assertSame('', $typing->typedPath());
        [$went] = self::press($typing, ['a', 'l', 'p', 'h', 'a', new KeyMsg(KeyType::Backspace), 'a', new KeyMsg(KeyType::Enter)]);
        self::assertSame($this->root . '/alpha', $went->path(), 'relative to the directory on screen');
        self::assertNull($went->typedPath());

        $pasted = DirectoryPicker::open($this->root)->withPaste($this->root . "/beta\n");
        [$went] = self::press($pasted, [new KeyMsg(KeyType::Enter)]);
        self::assertSame($this->root . '/beta', $went->path(), 'a paste starts the path box');

        [$bad] = self::press(DirectoryPicker::open($this->root), ['/', 'n', 'o', new KeyMsg(KeyType::Enter)]);
        self::assertSame($this->root, $bad->path());
        self::assertStringContainsString('no directory at', (string) $bad->notice());

        [$stopped] = self::press(DirectoryPicker::open($this->root), ['/', 'x', new KeyMsg(KeyType::Escape)]);
        self::assertNull($stopped->typedPath(), 'Esc stops typing');
        self::assertSame($this->root, $stopped->path());
    }

    public function testAnUnreadableDirectoryIsNotEntered(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root reads every directory');
        }
        \chmod($this->root . '/locked', 0o000);
        $picker = DirectoryPicker::open($this->root);
        $at = \array_search('locked', self::names($picker), true);
        self::assertIsInt($at);

        [$picker] = self::press($picker, \array_fill(0, $at + 1, new KeyMsg(KeyType::Down)));
        [$refused] = self::press($picker, [new KeyMsg(KeyType::Enter)]);
        self::assertSame($this->root, $refused->path());
        self::assertStringContainsString('permission denied', (string) $refused->notice());
    }

    public function testTheCurrentRootStartsHereAndAnotherAsksFirst(): void
    {
        [, $here] = self::press(DirectoryPicker::open($this->root), [new KeyMsg(KeyType::Enter)]);
        self::assertSame(DirectoryPickerAction::HERE, $here?->kind);
        self::assertSame($this->root, $here?->path);

        [$inAlpha] = self::press(DirectoryPicker::open($this->root), [new KeyMsg(KeyType::Down), new KeyMsg(KeyType::Enter)]);
        [$asking, $none] = self::press($inAlpha, ['s']);
        self::assertNull($none);
        self::assertSame($this->root . '/alpha', $asking->confirming());
        [$back] = self::press($asking, [new KeyMsg(KeyType::Escape)]);
        self::assertNull($back->confirming(), 'Esc in the question stays in the picker');
        [, $relaunch] = self::press($asking, ['y']);
        self::assertSame(DirectoryPickerAction::RELAUNCH, $relaunch?->kind);
        self::assertSame($this->root . '/alpha', $relaunch?->path);

        [, $cancel] = self::press(DirectoryPicker::open($this->root), [new KeyMsg(KeyType::Escape)]);
        self::assertSame(DirectoryPickerAction::CANCEL, $cancel?->kind);

        [$typed, $direct] = DirectoryPicker::open($this->root)->chooseTyped('.');
        self::assertSame(DirectoryPickerAction::HERE, $direct?->kind, '`/new .` is this root');
        [$typedOther] = DirectoryPicker::open($this->root)->chooseTyped('beta');
        self::assertSame($this->root . '/beta', $typedOther->confirming());
        self::assertNull($typed->confirming());
    }

    /** @return array<string, array{int}> */
    public static function widths(): array
    {
        return ['40 cols' => [40], '80 cols' => [80], '120 cols' => [120]];
    }

    /** @dataProvider widths */
    public function testNoPaintedLineIsWiderThanTheTerminal(int $cols): void
    {
        $picker = DirectoryPicker::open($this->root);
        foreach ([$picker, self::press($picker, ['/', ...\mb_str_split(\str_repeat('x', 200))])[0], self::press($picker, [new KeyMsg(KeyType::Down), new KeyMsg(KeyType::Enter), 's'])[0]] as $state) {
            [$width, $height] = DirectoryPicker::overlayGeometry($cols, 24, Renderer::SHELL_CHROME_COLS);
            foreach (\explode("\n", $state->render($width, $height, Theme::byName('dark'))) as $line) {
                self::assertLessThanOrEqual($cols - Renderer::SHELL_CHROME_COLS + 2, Width::string((string) \preg_replace('/\e\[[0-9;]*m/', '', $line)), $line);
            }
        }

        $chat = (new Chat(history: [], inputBuf: '/new', backend: new EchoBackend(), projectRoot: $this->root))->withSize($cols, 24);
        [$open] = $chat->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($open->dirPicker());
        $frame = Renderer::render($open);
        self::assertStringContainsString('Start session here', (string) \preg_replace('/\e\[[0-9;]*m/', '', $frame));
        foreach (\explode("\n", $frame) as $line) {
            self::assertLessThanOrEqual($cols, Width::string((string) \preg_replace('/\e\[[0-9;]*m/', '', $line)), 'frame line wider than the terminal');
        }
    }
}
