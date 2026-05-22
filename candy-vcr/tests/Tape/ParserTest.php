<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vcr\Tape\Token;
use SugarCraft\Vcr\Tape\Ast\ArrowDirective;
use SugarCraft\Vcr\Tape\Ast\BackspaceDirective;
use SugarCraft\Vcr\Tape\Ast\CtrlDirective;
use SugarCraft\Vcr\Tape\Ast\EnterDirective;
use SugarCraft\Vcr\Tape\Ast\EnvDirective;
use SugarCraft\Vcr\Tape\Ast\EscapeDirective;
use SugarCraft\Vcr\Tape\Ast\HideDirective;
use SugarCraft\Vcr\Tape\Ast\OutputDirective;
use SugarCraft\Vcr\Tape\Ast\ParseError;
use SugarCraft\Vcr\Tape\Ast\SetDirective;
use SugarCraft\Vcr\Tape\Ast\ShowDirective;
use SugarCraft\Vcr\Tape\Ast\SleepDirective;
use SugarCraft\Vcr\Tape\Ast\SpaceDirective;
use SugarCraft\Vcr\Tape\Ast\TabDirective;
use SugarCraft\Vcr\Tape\Ast\TypeDirective;
use SugarCraft\Vcr\Tape\Ast\WaitDirective;

final class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function testTypeDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Type "hello"');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(TypeDirective::class, $ast[0]);
        $this->assertSame('hello', $ast[0]->text);
    }

    public function testEnterDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Enter');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(EnterDirective::class, $ast[0]);
    }

    public function testTabDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Tab');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(TabDirective::class, $ast[0]);
    }

    public function testBackspaceDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Backspace');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(BackspaceDirective::class, $ast[0]);
    }

    public function testSleepDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Sleep 500ms');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(SleepDirective::class, $ast[0]);
        $this->assertSame(0.5, $ast[0]->seconds);
    }

    public function testSleepSeconds(): void
    {
        $tokens = (new Lexer())->tokenize('Sleep 2s');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(SleepDirective::class, $ast[0]);
        $this->assertSame(2.0, $ast[0]->seconds);
    }

    public function testSetWidth(): void
    {
        $tokens = (new Lexer())->tokenize('Set Width 800');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(SetDirective::class, $ast[0]);
        $this->assertSame('Width', $ast[0]->key);
        $this->assertSame('800', $ast[0]->value);
    }

    public function testSetHeight(): void
    {
        $tokens = (new Lexer())->tokenize('Set Height 600');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(SetDirective::class, $ast[0]);
        $this->assertSame('Height', $ast[0]->key);
        $this->assertSame('600', $ast[0]->value);
    }

    public function testSetTheme(): void
    {
        $tokens = (new Lexer())->tokenize('Set Theme "TokyoNight"');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(SetDirective::class, $ast[0]);
        $this->assertSame('Theme', $ast[0]->key);
        $this->assertSame('"TokyoNight"', $ast[0]->value);
    }

    public function testSetTypingSpeed(): void
    {
        $tokens = (new Lexer())->tokenize('Set TypingSpeed 60ms');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(SetDirective::class, $ast[0]);
        $this->assertSame('TypingSpeed', $ast[0]->key);
        $this->assertSame('60ms', $ast[0]->value);
    }

    public function testSetInvalidKeyReturnsParseError(): void
    {
        $tokens = (new Lexer())->tokenize('Set UnknownKey value');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(ParseError::class, $ast[0]);
        $this->assertSame(1, $ast[0]->line);
        $this->assertStringContainsString('UnknownKey', $ast[0]->message);
    }

    public function testEnvDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Env TERM "xterm-256color"');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(EnvDirective::class, $ast[0]);
        $this->assertSame('TERM', $ast[0]->key);
        $this->assertSame('xterm-256color', $ast[0]->value);
    }

    public function testOutputDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Output .vhs/demo.gif');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(OutputDirective::class, $ast[0]);
        $this->assertSame('.vhs/demo.gif', $ast[0]->path);
    }

    public function testArrowDirectives(): void
    {
        foreach (['Up', 'Down', 'Left', 'Right'] as $dir) {
            $tokens = (new Lexer())->tokenize($dir);
            $ast = $this->parser->parse($tokens);

            $this->assertCount(1, $ast, "Failed for {$dir}");
            $this->assertInstanceOf(ArrowDirective::class, $ast[0], "Failed for {$dir}");
            $this->assertSame($dir, $ast[0]->direction, "Failed for {$dir}");
        }
    }

    public function testCtrlDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Ctrl+C');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(CtrlDirective::class, $ast[0]);
        $this->assertSame('C', $ast[0]->letter);
    }

    public function testSpaceDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Space');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(SpaceDirective::class, $ast[0]);
    }

    public function testEscapeDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Escape');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(EscapeDirective::class, $ast[0]);
    }

    public function testHideDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Hide');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(HideDirective::class, $ast[0]);
    }

    public function testShowDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Show');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(ShowDirective::class, $ast[0]);
    }

    public function testWaitDirective(): void
    {
        $tokens = (new Lexer())->tokenize('Wait 5s');
        $ast = $this->parser->parse($tokens);

        $this->assertCount(1, $ast);
        $this->assertInstanceOf(WaitDirective::class, $ast[0]);
        $this->assertSame(5.0, $ast[0]->seconds);
    }

    public function testFullTape(): void
    {
        $source = <<<'TAPE'
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
        $tokens = (new Lexer())->tokenize($source);
        $ast = $this->parser->parse($tokens);

        $this->assertCount(11, $ast);
        $this->assertInstanceOf(OutputDirective::class, $ast[0]);
        $this->assertInstanceOf(SetDirective::class, $ast[1]);
        $this->assertInstanceOf(SetDirective::class, $ast[2]);
        $this->assertInstanceOf(SetDirective::class, $ast[3]);
        $this->assertInstanceOf(SetDirective::class, $ast[4]);
        $this->assertInstanceOf(SetDirective::class, $ast[5]);
        $this->assertInstanceOf(TypeDirective::class, $ast[6]);
        $this->assertInstanceOf(EnterDirective::class, $ast[7]);
        $this->assertInstanceOf(SleepDirective::class, $ast[8]);
        $this->assertInstanceOf(ArrowDirective::class, $ast[9]);
        $this->assertInstanceOf(SleepDirective::class, $ast[10]);
    }

    public function testParseErrorIncludesLineNumber(): void
    {
        $source = "Type \"a\"\nSet BadKey value\nEnter";
        $tokens = (new Lexer())->tokenize($source);
        $ast = $this->parser->parse($tokens);

        $this->assertCount(3, $ast);
        $this->assertInstanceOf(TypeDirective::class, $ast[0]);
        $this->assertInstanceOf(ParseError::class, $ast[1]);
        $this->assertSame(2, $ast[1]->line);
        $this->assertInstanceOf(EnterDirective::class, $ast[2]);
    }
}
