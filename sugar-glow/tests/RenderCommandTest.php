<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use SugarCraft\Glow\RenderCommand;
use SugarCraft\Shine\Theme;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RenderCommandTest extends TestCase
{
    public function testPickThemeAnsi(): void
    {
        $theme = RenderCommand::pickTheme('ansi');
        $this->assertInstanceOf(Theme::class, $theme);
    }

    public function testPickThemePlain(): void
    {
        $theme = RenderCommand::pickTheme('plain');
        $this->assertSame('plain', $theme->paragraph->render('plain'));
    }

    public function testPickThemeCaseInsensitive(): void
    {
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('ANSI'));
    }

    public function testPickThemeRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RenderCommand::pickTheme('mystery');
    }

    public function testLoadInputReadsFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Hello");
        try {
            $this->assertSame("# Hello", RenderCommand::loadInput($tmp));
        } finally {
            unlink($tmp);
        }
    }

    public function testLoadInputMissingFileReturnsNull(): void
    {
        $this->assertNull(RenderCommand::loadInput('/no/such/path/sugar-glow-test.md'));
    }

    public function testPickThemeDarkLightDraculaTokyoNightPink(): void
    {
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('dark'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('light'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('dracula'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('tokyo-night'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('tokyonight'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('pink'));
        // Underscores accepted as separators.
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('tokyo_night'));
    }

    public function testPickThemeNotty(): void
    {
        // Notty is a no-style fallback (matches plain visually).
        $theme = RenderCommand::pickTheme('notty');
        $this->assertSame('plain', $theme->paragraph->render('plain'));
    }

    public function testPickThemeEmptyDefaultsToAnsi(): void
    {
        // The CLI passes the --theme default of 'ansi', but a direct
        // empty-string call should still produce a usable Theme.
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme(''));
    }

    // --- execute() tests ---

    private function invokeExecute(RenderCommand $command, InputInterface $input, OutputInterface $output): int
    {
        $method = new ReflectionMethod($command, 'execute');
        $method->setAccessible(true);
        return $method->invoke($command, $input, $output);
    }

    public function testExecuteWithNoInputReturnsFailure(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('file')->willReturn(null);
        $input->method('getOption')->willReturnMap([
            ['theme-config', ''],
            ['style', null],
            ['theme', 'ansi'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);

        $output = $this->createMock(OutputInterface::class);
        $output->expects($this->once())
            ->method('writeln')
            ->with('<error>no input</error>');

        $command = new RenderCommand();
        $result = $this->invokeExecute($command, $input, $output);
        $this->assertSame(Command::FAILURE, $result);
    }

    public function testExecuteWithValidInputNoPagerReturnsSuccess(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Hello\n\nWorld");
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', ''],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $output = $this->createMock(OutputInterface::class);
            $output->expects($this->once())
                ->method('writeln')
                ->with($this->stringContains('Hello'));

            $command = new RenderCommand();
            $result = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            unlink($tmp);
        }
    }

    public function testExecuteWithStyleOptionUsesStyleAsTheme(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "plain text");
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', ''],
                ['style', 'plain'],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $output = $this->createMock(OutputInterface::class);
            $output->expects($this->once())
                ->method('writeln');

            $command = new RenderCommand();
            $result = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            unlink($tmp);
        }
    }

    public function testExecuteWithThemeConfigOverridesTheme(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "test");
        $configTmp = tempnam(sys_get_temp_dir(), 'glow-config-');
        $this->assertNotFalse($configTmp);
        file_put_contents($configTmp, '{"paragraph":{"fg":"white","bg":"black"}}');
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', $configTmp],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $output = $this->createMock(OutputInterface::class);
            $output->expects($this->once())
                ->method('writeln');

            $command = new RenderCommand();
            $result = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            unlink($tmp);
            unlink($configTmp);
        }
    }

    public function testExecuteWithWidthOptionEnablesWordWrap(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, str_repeat("word ", 50));
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', ''],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 40],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $output = $this->createMock(OutputInterface::class);
            $output->expects($this->once())
                ->method('writeln');

            $command = new RenderCommand();
            $result = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            unlink($tmp);
        }
    }

    public function testExecuteWithNoHyperlinksDisablesHyperlinks(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "[link](https://example.com)");
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', ''],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', true],
            ]);

            $output = $this->createMock(OutputInterface::class);
            $output->expects($this->once())
                ->method('writeln');

            $command = new RenderCommand();
            $result = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            unlink($tmp);
        }
    }

    public function testExecuteWithDarkTheme(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Dark Theme Test");
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', ''],
                ['style', null],
                ['theme', 'dark'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $output = $this->createMock(OutputInterface::class);
            $output->expects($this->once())
                ->method('writeln');

            $command = new RenderCommand();
            $result = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            unlink($tmp);
        }
    }

    public function testExecuteWithTokyoNightTheme(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glow-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Tokyo Night Theme Test");
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', ''],
                ['style', null],
                ['theme', 'tokyo-night'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $output = $this->createMock(OutputInterface::class);
            $output->expects($this->once())
                ->method('writeln');

            $command = new RenderCommand();
            $result = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::SUCCESS, $result);
        } finally {
            unlink($tmp);
        }
    }
}
