<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Token;

final class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    public function testTypeDirectiveWithDoubleQuotes(): void
    {
        $tokens = $this->lexer->tokenize('Type "hello world"');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        $this->assertSame('hello world', $tokens[0]->value);
        $this->assertSame(1, $tokens[0]->line);
    }

    public function testTypeDirectiveWithSingleQuotes(): void
    {
        $tokens = $this->lexer->tokenize("Type 'single quotes'");
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        $this->assertSame('single quotes', $tokens[0]->value);
    }

    public function testEnter(): void
    {
        $tokens = $this->lexer->tokenize('Enter');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ENTER, $tokens[0]->type);
    }

    public function testTab(): void
    {
        $tokens = $this->lexer->tokenize('Tab');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_TAB, $tokens[0]->type);
    }

    public function testBackspace(): void
    {
        $tokens = $this->lexer->tokenize('Backspace');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_BACKSPACE, $tokens[0]->type);
    }

    public function testSpace(): void
    {
        $tokens = $this->lexer->tokenize('Space');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SPACE, $tokens[0]->type);
    }

    public function testEscape(): void
    {
        $tokens = $this->lexer->tokenize('Escape');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ESCAPE, $tokens[0]->type);
    }

    public function testArrowUp(): void
    {
        $tokens = $this->lexer->tokenize('Up');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ARROW, $tokens[0]->type);
        $this->assertSame('Up', $tokens[0]->value);
    }

    public function testArrowDown(): void
    {
        $tokens = $this->lexer->tokenize('Down');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ARROW, $tokens[0]->type);
        $this->assertSame('Down', $tokens[0]->value);
    }

    public function testArrowLeft(): void
    {
        $tokens = $this->lexer->tokenize('Left');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ARROW, $tokens[0]->type);
        $this->assertSame('Left', $tokens[0]->value);
    }

    public function testArrowRight(): void
    {
        $tokens = $this->lexer->tokenize('Right');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ARROW, $tokens[0]->type);
        $this->assertSame('Right', $tokens[0]->value);
    }

    public function testSleepSeconds(): void
    {
        $tokens = $this->lexer->tokenize('Sleep 5s');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type);
        $this->assertSame('5', $tokens[0]->value);
    }

    public function testSleepMilliseconds(): void
    {
        $tokens = $this->lexer->tokenize('Sleep 500ms');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type);
        $this->assertSame('0.5', $tokens[0]->value);
    }

    public function testSleepMinutes(): void
    {
        $tokens = $this->lexer->tokenize('Sleep 2m');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type);
        $this->assertSame('120', $tokens[0]->value);
    }

    public function testSleepDecimal(): void
    {
        $tokens = $this->lexer->tokenize('Sleep 1.5s');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[0]->type);
        $this->assertSame('1.5', $tokens[0]->value);
    }

    public function testSetWidth(): void
    {
        $tokens = $this->lexer->tokenize('Set Width 800');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        $this->assertSame("Width\x00" . '800', $tokens[0]->value);
    }

    public function testSetHeight(): void
    {
        $tokens = $this->lexer->tokenize('Set Height 600');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        $this->assertSame("Height\x00" . '600', $tokens[0]->value);
    }

    public function testSetTheme(): void
    {
        $tokens = $this->lexer->tokenize('Set Theme "TokyoNight"');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        $this->assertSame("Theme\x00" . '"TokyoNight"', $tokens[0]->value);
    }

    public function testSetFontSize(): void
    {
        $tokens = $this->lexer->tokenize('Set FontSize 14');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        $this->assertSame("FontSize\x00" . '14', $tokens[0]->value);
    }

    public function testSetTypingSpeed(): void
    {
        $tokens = $this->lexer->tokenize('Set TypingSpeed 60ms');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SET, $tokens[0]->type);
        $this->assertSame("TypingSpeed\x00" . '60ms', $tokens[0]->value);
    }

    public function testEnv(): void
    {
        $tokens = $this->lexer->tokenize('Env TERM "xterm-256color"');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_ENV, $tokens[0]->type);
        $this->assertSame("TERM\x00" . 'xterm-256color', $tokens[0]->value);
    }

    public function testOutput(): void
    {
        $tokens = $this->lexer->tokenize('Output .vhs/demo.gif');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_OUTPUT, $tokens[0]->type);
        $this->assertSame('.vhs/demo.gif', $tokens[0]->value);
    }

    public function testCtrlA(): void
    {
        $tokens = $this->lexer->tokenize('Ctrl+A');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_CTRL, $tokens[0]->type);
        $this->assertSame('A', $tokens[0]->value);
    }

    public function testCtrlC(): void
    {
        $tokens = $this->lexer->tokenize('Ctrl+C');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_CTRL, $tokens[0]->type);
        $this->assertSame('C', $tokens[0]->value);
    }

    public function testCtrlZ(): void
    {
        $tokens = $this->lexer->tokenize('Ctrl+Z');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_CTRL, $tokens[0]->type);
        $this->assertSame('Z', $tokens[0]->value);
    }

    public function testHide(): void
    {
        $tokens = $this->lexer->tokenize('Hide');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_HIDE, $tokens[0]->type);
    }

    public function testShow(): void
    {
        $tokens = $this->lexer->tokenize('Show');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SHOW, $tokens[0]->type);
    }

    public function testWait(): void
    {
        $tokens = $this->lexer->tokenize('Wait 5s');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_WAIT, $tokens[0]->type);
        $this->assertSame('5', $tokens[0]->value);
    }

    public function testScreenshot(): void
    {
        $tokens = $this->lexer->tokenize('Screenshot output.png');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_SCREENSHOT, $tokens[0]->type);
        $this->assertSame('output.png', $tokens[0]->value);
    }

    public function testCommentLinesPreserved(): void
    {
        $tokens = $this->lexer->tokenize("# This is a comment\nType \"hello\"\n# Another comment");
        $this->assertCount(3, $tokens);
        $this->assertSame(Lexer::TOKEN_COMMENT, $tokens[0]->type);
        $this->assertSame('# This is a comment', $tokens[0]->value);
        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[1]->type);
        $this->assertSame(2, $tokens[1]->line);
        $this->assertSame(Lexer::TOKEN_COMMENT, $tokens[2]->type);
        $this->assertSame('# Another comment', $tokens[2]->value);
        $this->assertSame(3, $tokens[2]->line);
    }

    public function testEmptyLinesSkipped(): void
    {
        $tokens = $this->lexer->tokenize("Type \"a\"\n\nEnter");
        $this->assertCount(3, $tokens);
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        $this->assertSame('a', $tokens[0]->value);
        $this->assertSame(Lexer::TOKEN_COMMENT, $tokens[1]->type);
        $this->assertSame('', $tokens[1]->value);
        $this->assertSame(Lexer::TOKEN_ENTER, $tokens[2]->type);
    }

    public function testUnknownLine(): void
    {
        $tokens = $this->lexer->tokenize('UnknownDirective');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_UNKNOWN, $tokens[0]->type);
    }

    public function testLineNumbers(): void
    {
        $source = "Type \"a\"\nEnter\nTab";
        $tokens = $this->lexer->tokenize($source);
        $this->assertCount(3, $tokens);
        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(2, $tokens[1]->line);
        $this->assertSame(3, $tokens[2]->line);
    }

    public function testFullTape(): void
    {
        $source = <<<'TAPE'
# VHS tape
Output .vhs/counter.gif
Set FontSize 16
Set Width 700
Set Height 220
Set TypingSpeed 60ms
Set Theme "TokyoNight"
Type "php examples/counter.php"
Enter
Sleep 500ms
Up
Sleep 200ms
TAPE;
        $tokens = $this->lexer->tokenize($source);
        $this->assertGreaterThan(8, count($tokens));
        $this->assertSame(Lexer::TOKEN_COMMENT, $tokens[0]->type);
        $this->assertSame(Lexer::TOKEN_OUTPUT, $tokens[1]->type);
        $this->assertSame('.vhs/counter.gif', $tokens[1]->value);
    }

    /**
     * Regression: upstream VHS lets several directives share one line
     * (e.g. `Down  Sleep 200ms`). The old line-based lexer classified the
     * whole line as UNKNOWN and silently dropped the keystroke, which
     * gutted every interactive demo tape under candy-vcr.
     */
    public function testArrowFollowedByInlineSleepOnSameLine(): void
    {
        $tokens = $this->lexer->tokenize('Down  Sleep 200ms');
        $this->assertCount(2, $tokens);
        $this->assertSame(Lexer::TOKEN_ARROW, $tokens[0]->type);
        $this->assertSame('Down', $tokens[0]->value);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[1]->type);
        $this->assertSame('0.2', $tokens[1]->value);
    }

    public function testSpaceFollowedByInlineSleep(): void
    {
        $tokens = $this->lexer->tokenize('Space Sleep 1s');
        $this->assertCount(2, $tokens);
        $this->assertSame(Lexer::TOKEN_SPACE, $tokens[0]->type);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[1]->type);
        $this->assertSame('1', $tokens[1]->value);
    }

    public function testTypeThenSleepThenTrailingComment(): void
    {
        $tokens = $this->lexer->tokenize('Type "f"  Sleep 300ms  # place flag');
        $this->assertCount(3, $tokens);
        $this->assertSame(Lexer::TOKEN_TYPE, $tokens[0]->type);
        $this->assertSame('f', $tokens[0]->value);
        $this->assertSame(Lexer::TOKEN_SLEEP, $tokens[1]->type);
        $this->assertSame('0.3', $tokens[1]->value);
        $this->assertSame(Lexer::TOKEN_COMMENT, $tokens[2]->type);
    }

    public function testMultipleKeysOnOneLine(): void
    {
        $tokens = $this->lexer->tokenize('Up Down Left Right');
        $this->assertCount(4, $tokens);
        foreach ($tokens as $t) {
            $this->assertSame(Lexer::TOKEN_ARROW, $t->type);
        }
        $this->assertSame(['Up', 'Down', 'Left', 'Right'], array_map(fn ($t) => $t->value, $tokens));
    }

    public function testBareKeywordIsNotMatchedAsPrefixOfLongerToken(): void
    {
        // "Spacebar" is not the Space directive — must not split into Space + "bar".
        $tokens = $this->lexer->tokenize('Spacebar');
        $this->assertCount(1, $tokens);
        $this->assertSame(Lexer::TOKEN_UNKNOWN, $tokens[0]->type);
    }
}
