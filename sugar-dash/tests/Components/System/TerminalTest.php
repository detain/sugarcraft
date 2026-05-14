<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\System;

use SugarCraft\Dash\Components\System\Terminal;
use SugarCraft\Dash\Components\System\PromptStyle;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class TerminalTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTerminalImplementsSizer(): void
    {
        $terminal = Terminal::new();
        $this->assertInstanceOf(Sizer::class, $terminal);
    }

    public function testTerminalImplementsItem(): void
    {
        $terminal = Terminal::new();
        $this->assertInstanceOf(Item::class, $terminal);
    }

    // ═══════════════════════════════════════════════════════════════
    // PromptStyle enum
    // ═══════════════════════════════════════════════════════════════

    public function testPromptStyleBash(): void
    {
        $prompt = PromptStyle::Bash->prompt('/home/user');

        $this->assertStringContainsString('user', $prompt);
        $this->assertStringContainsString('home/user', $prompt);
    }

    public function testPromptStylePwsh(): void
    {
        $prompt = PromptStyle::Pwsh->prompt('C:\\Users\\Test');

        $this->assertStringContainsString('PS', $prompt);
        $this->assertStringContainsString('Users\\Test', $prompt);
    }

    public function testPromptStylePS(): void
    {
        $prompt = PromptStyle::PS->prompt('~');

        $this->assertStringContainsString('~', $prompt);
    }

    public function testPromptStyleSimple(): void
    {
        $prompt = PromptStyle::Simple->prompt();

        $this->assertSame('$ ', $prompt);
    }

    // ═══════════════════════════════════════════════════════════════
    // Creation
    // ═══════════════════════════════════════════════════════════════

    public function testTerminalNewFactory(): void
    {
        $terminal = Terminal::new();

        $this->assertSame('', $terminal->getInput());
        $this->assertSame([], $terminal->getHistory());
        $this->assertSame([], $terminal->getOutput());
    }

    // ═══════════════════════════════════════════════════════════════
    // Input handling
    // ═══════════════════════════════════════════════════════════════

    public function testType(): void
    {
        $terminal = Terminal::new()->type('ls -la');

        $this->assertSame('ls -la', $terminal->getInput());
    }

    public function testTypeReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $typed = $terminal->type('ls');

        $this->assertNotSame($terminal, $typed);
    }

    public function testClearInput(): void
    {
        $terminal = Terminal::new()->type('ls')->clearInput();

        $this->assertSame('', $terminal->getInput());
    }

    public function testClearInputReturnsNewInstance(): void
    {
        $terminal = Terminal::new()->type('ls');
        $cleared = $terminal->clearInput();

        $this->assertNotSame($terminal, $cleared);
    }

    // ═══════════════════════════════════════════════════════════════
    // Command submission
    // ═══════════════════════════════════════════════════════════════

    public function testSubmitAddsToHistory(): void
    {
        $terminal = Terminal::new()->type('ls')->submit();

        $this->assertSame(['ls'], $terminal->getHistory());
    }

    public function testSubmitAddsToOutput(): void
    {
        $terminal = Terminal::new()->type('ls')->submit();

        $output = $terminal->getOutput();
        $this->assertNotEmpty($output);
    }

    public function testSubmitClearsInput(): void
    {
        $terminal = Terminal::new()->type('ls')->submit();

        $this->assertSame('', $terminal->getInput());
    }

    public function testSubmitEmptyInputNoOp(): void
    {
        $terminal = Terminal::new()->submit();

        $this->assertSame([], $terminal->getHistory());
        $this->assertSame([], $terminal->getOutput());
    }

    public function testSubmitMultipleCommands(): void
    {
        $terminal = Terminal::new()
            ->type('ls')
            ->submit()
            ->type('cd /home')
            ->submit()
            ->type('pwd')
            ->submit();

        $this->assertSame(['ls', 'cd /home', 'pwd'], $terminal->getHistory());
    }

    // ═══════════════════════════════════════════════════════════════
    // Output handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithOutput(): void
    {
        $terminal = Terminal::new()->withOutput('Command output');

        $this->assertSame(['Command output'], $terminal->getOutput());
    }

    public function testWithOutputMultipleLines(): void
    {
        $terminal = Terminal::new()->withOutput("Line 1\nLine 2\nLine 3");

        $output = $terminal->getOutput();
        $this->assertCount(3, $output);
        $this->assertSame('Line 1', $output[0]);
        $this->assertSame('Line 2', $output[1]);
        $this->assertSame('Line 3', $output[2]);
    }

    public function testWithOutputError(): void
    {
        $terminal = Terminal::new()->withOutput('Error message', true);

        $output = $terminal->getOutput();
        $this->assertStringContainsString('[ERROR]', $output[0]);
        $this->assertStringContainsString('Error message', $output[0]);
    }

    public function testWithBlankLine(): void
    {
        $terminal = Terminal::new()
            ->withOutput('First')
            ->withBlankLine()
            ->withOutput('After blank');

        $output = $terminal->getOutput();
        $this->assertCount(3, $output);
        $this->assertSame('', $output[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // History navigation
    // ═══════════════════════════════════════════════════════════════

    public function testHistoryUp(): void
    {
        $terminal = Terminal::new()
            ->type('first')
            ->submit()
            ->type('second')
            ->submit()
            ->historyUp();

        $this->assertSame('second', $terminal->getInput());
    }

    public function testHistoryUpMultiple(): void
    {
        $terminal = Terminal::new()
            ->type('first')
            ->submit()
            ->type('second')
            ->submit()
            ->type('third')
            ->submit()
            ->historyUp()
            ->historyUp();

        $this->assertSame('second', $terminal->getInput());
    }

    public function testHistoryDown(): void
    {
        $terminal = Terminal::new()
            ->type('first')
            ->submit()
            ->type('second')
            ->submit()
            ->historyUp()
            ->historyUp()
            ->historyDown();

        $this->assertSame('second', $terminal->getInput());
    }

    public function testHistoryDownEmptyAtEnd(): void
    {
        $terminal = Terminal::new()
            ->type('first')
            ->submit()
            ->type('second')
            ->submit()
            ->historyUp()
            ->historyUp()
            ->historyDown()
            ->historyDown();

        $this->assertSame('', $terminal->getInput());
    }

    public function testHistoryUpEmptyHistory(): void
    {
        $terminal = Terminal::new()->historyUp();

        $this->assertSame('', $terminal->getInput());
    }

    // ═══════════════════════════════════════════════════════════════
    // Screen operations
    // ═══════════════════════════════════════════════════════════════

    public function testClearScreen(): void
    {
        $terminal = Terminal::new()
            ->type('command')
            ->submit()
            ->withOutput('some output')
            ->clearScreen();

        $this->assertSame([], $terminal->getOutput());
    }

    public function testClearScreenReturnsNewInstance(): void
    {
        $terminal = Terminal::new()->withOutput('output');
        $cleared = $terminal->clearScreen();

        $this->assertNotSame($terminal, $cleared);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $terminal = Terminal::new();
        $result = $terminal->setSize(80, 24);

        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $resized = $terminal->setSize(80, 24);

        $this->assertNotSame($terminal, $resized);
    }

    public function testGetInnerSize(): void
    {
        $terminal = Terminal::new();

        [$w, $h] = $terminal->getInnerSize();

        $this->assertSame(80, $w);
        $this->assertSame(24, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyTerminal(): void
    {
        $terminal = Terminal::new()->setSize(60, 20);
        $rendered = $terminal->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithOutput(): void
    {
        $terminal = Terminal::new()
            ->withOutput('Hello World')
            ->setSize(60, 20);
        $rendered = $terminal->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderWithInput(): void
    {
        $terminal = Terminal::new()
            ->type('ls -la')
            ->setSize(60, 20);
        $rendered = $terminal->render();

        $this->assertStringContainsString('ls -la', $rendered);
    }

    public function testRenderWithPrompt(): void
    {
        $terminal = Terminal::new()
            ->withShowPrompt(true)
            ->setSize(60, 20);
        $rendered = $terminal->render();

        // Should contain prompt characters (user@machine or $ or PS)
        $this->assertMatchesRegularExpression('/[@\$>PS]|user/', $rendered);
    }

    public function testRenderWithBorder(): void
    {
        $terminal = Terminal::new()->setSize(60, 20);
        $rendered = $terminal->render();

        // Should contain border characters
        $this->assertMatchesRegularExpression('/[┌┐└┘╔╗╚╝╭╮╰╯─│]/', $rendered);
    }

    public function testRenderWithCustomPrompt(): void
    {
        $terminal = Terminal::new()
            ->withCustomPrompt('>>> ')
            ->setSize(60, 20);
        $rendered = $terminal->render();

        $this->assertStringContainsString('>>>', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // GetPrompt
    // ═══════════════════════════════════════════════════════════════

    public function testGetPromptDefault(): void
    {
        $terminal = Terminal::new();

        $prompt = $terminal->getPrompt();

        $this->assertNotSame('', $prompt);
    }

    public function testGetPromptCustom(): void
    {
        $terminal = Terminal::new()->withCustomPrompt('CUSTOM> ');

        $this->assertSame('CUSTOM> ', $terminal->getPrompt());
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithPromptStyleReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withPromptStyle(PromptStyle::Pwsh);

        $this->assertNotSame($terminal, $updated);
    }

    public function testWithCustomPromptReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withCustomPrompt('>>> ');

        $this->assertNotSame($terminal, $updated);
    }

    public function testWithShowPromptReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withShowPrompt(false);

        $this->assertNotSame($terminal, $updated);
    }

    public function testWithMaxHistoryReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withMaxHistory(50);

        $this->assertNotSame($terminal, $updated);
    }

    public function testWithPromptColorReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withPromptColor(Color::hex('#FF0000'));

        $this->assertNotSame($terminal, $updated);
    }

    public function testWithInputColorReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withInputColor(Color::hex('#00FF00'));

        $this->assertNotSame($terminal, $updated);
    }

    public function testWithOutputColorReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withOutputColor(Color::hex('#0000FF'));

        $this->assertNotSame($terminal, $updated);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $terminal = Terminal::new();
        $updated = $terminal->withStyle('double');

        $this->assertNotSame($terminal, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSubmitWithSpecialCharacters(): void
    {
        $terminal = Terminal::new()
            ->type('echo "Hello World" && ls -la')
            ->submit();

        $this->assertSame(['echo "Hello World" && ls -la'], $terminal->getHistory());
    }

    public function testHistoryLimit(): void
    {
        $terminal = Terminal::new()->withMaxHistory(3);

        for ($i = 1; $i <= 5; $i++) {
            $terminal = $terminal->type("cmd$i")->submit();
        }

        $history = $terminal->getHistory();
        $this->assertCount(3, $history);
        $this->assertSame('cmd3', $history[0]);
        $this->assertSame('cmd4', $history[1]);
        $this->assertSame('cmd5', $history[2]);
    }

    public function testOutputLimit(): void
    {
        $terminal = Terminal::new()->withMaxOutput(3);

        for ($i = 1; $i <= 5; $i++) {
            $terminal = $terminal->withOutput("output$i");
        }

        $output = $terminal->getOutput();
        $this->assertCount(3, $output);
        $this->assertSame('output3', $output[0]);
        $this->assertSame('output4', $output[1]);
        $this->assertSame('output5', $output[2]);
    }

    public function testRenderWithVerySmallSize(): void
    {
        $terminal = Terminal::new()->setSize(10, 5);
        $rendered = $terminal->render();

        // Should handle small sizes gracefully
        $this->assertNotSame('', $rendered);
    }

    public function testMultipleSubmitsAndType(): void
    {
        $terminal = Terminal::new()
            ->type('first')
            ->submit()
            ->type('second')
            ->submit()
            ->type('editing');

        $this->assertSame('editing', $terminal->getInput());
        $this->assertCount(2, $terminal->getHistory());
    }

    public function testSubmitAfterHistoryNavigation(): void
    {
        $terminal = Terminal::new()
            ->type('first')
            ->submit()
            ->type('second')
            ->submit()
            ->historyUp()
            ->submit();

        // Should add another 'first' to history
        $this->assertCount(3, $terminal->getHistory());
    }
}
