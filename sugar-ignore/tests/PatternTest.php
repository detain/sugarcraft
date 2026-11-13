<?php

declare(strict_types=1);

namespace SugarCraft\Ignore\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ignore\Pattern;
use SugarCraft\Ignore\Token;

final class PatternTest extends TestCase
{
    public function testBlankAndCommentLinesParseToNull(): void
    {
        foreach (['', '   ', '#c', '!', '/', '!/', '//'] as $line) {
            $this->assertNull(Pattern::parse($line), var_export($line, true));
        }
    }

    public function testFlagsAreRead(): void
    {
        $p = Pattern::parse('!build/');

        $this->assertTrue($p->negate);
        $this->assertTrue($p->dirOnly);
        $this->assertSame('!build/', $p->source);
    }

    public function testAnUnanchoredPatternGetsTheImplicitDirsPrefixAndAnAnchoredDoesNot(): void
    {
        $this->assertSame(Token::DIRS, Pattern::parse('foo')->tokens[0][0]);
        $this->assertSame(Token::LITERAL, Pattern::parse('/foo')->tokens[0][0]);
        $this->assertSame(Token::LITERAL, Pattern::parse('a/foo')->tokens[0][0]);
    }

    public function testTokenizerKinds(): void
    {
        $types = array_column(Pattern::parse('/a?[0-9]*b**c**/d')->tokens, 0);

        $this->assertSame(
            [Token::LITERAL, Token::ONE, Token::CLASS_, Token::STAR, Token::LITERAL, Token::GLOBSTAR, Token::LITERAL, Token::DIRS, Token::LITERAL],
            $types,
        );
    }

    public function testEscapedMetacharactersAreLiterals(): void
    {
        $p = Pattern::parse('/a\\*b');

        $this->assertSame(1, preg_match($p->regex, 'a*b'));
        $this->assertSame(0, preg_match($p->regex, 'axb'));
    }

    public function testASimpleShapeUsesPcreAndAHostileOneDoesNot(): void
    {
        $this->assertTrue(Pattern::parse('*.log')->pcre);
        $this->assertTrue(Pattern::parse('**/foo')->pcre);
        $this->assertFalse(Pattern::parse('**a**a**a**c')->pcre);
        $this->assertFalse(Pattern::parse('*a*a*a*c')->pcre);
    }

    public function testPcreMatchAnswersWholeSubjectOrNullOnLimitError(): void
    {
        $p = Pattern::parse('/*.log');

        $this->assertTrue($p->pcreMatch('x.log'));
        $this->assertFalse($p->pcreMatch('x.logs'));

        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $this->assertNull(Pattern::parse('foo')->pcreMatch('a/b/c/d/foo'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function testTheBracketBodyIsEscapedAgainstTheRegexDelimiter(): void
    {
        $p = Pattern::parse('/[#a]x');

        $this->assertSame(1, preg_match($p->regex, '#x'));
        $this->assertSame(1, preg_match($p->regex, 'ax'));
    }

    public function testALeadingBracketCloseIsAMember(): void
    {
        $p = Pattern::parse('/[]]x');

        $this->assertNotNull($p);
        $this->assertSame(1, preg_match($p->regex, ']x'));
    }
}
