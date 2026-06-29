<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Completion\CompletionEscaper;

final class CompletionEscaperTest extends TestCase
{
    public function testSafeNameAcceptsValidIdentifiers(): void
    {
        $this->assertSame('style', CompletionEscaper::safeName('style'));
        $this->assertSame('choose', CompletionEscaper::safeName('choose'));
        $this->assertSame('my-command', CompletionEscaper::safeName('my-command'));
        $this->assertSame('my_command', CompletionEscaper::safeName('my_command'));
        $this->assertSame('format2', CompletionEscaper::safeName('format2'));
        $this->assertSame('a', CompletionEscaper::safeName('a'));
    }

    public function testSafeNameRejectsShellMetacharacters(): void
    {
        // Semicolon — command separator.
        $this->assertNull(CompletionEscaper::safeName('evil; rm -rf /'));
        // Dollar-substitution.
        $this->assertNull(CompletionEscaper::safeName('$(touch x)'));
        // Backtick substitution.
        $this->assertNull(CompletionEscaper::safeName('`ls`'));
        // Pipe.
        $this->assertNull(CompletionEscaper::safeName('a|b'));
        // Redirect.
        $this->assertNull(CompletionEscaper::safeName('a>/dev/null'));
        // Ampersand (background).
        $this->assertNull(CompletionEscaper::safeName('a&b'));
        // Newline.
        $this->assertNull(CompletionEscaper::safeName("a\nb"));
        // Single quote (shell injection).
        $this->assertNull(CompletionEscaper::safeName("a'b"));
        // Double quote.
        $this->assertNull(CompletionEscaper::safeName('a"b'));
        // Hash (comment).
        $this->assertNull(CompletionEscaper::safeName('a#b'));
        // Space.
        $this->assertNull(CompletionEscaper::safeName('a b'));
    }

    public function testFilterSafeListKeepsOnlyValidNames(): void
    {
        $input = ['style', 'evil; rm', 'choose', '$(echo)', 'confirm'];
        $expected = ['style', 'choose', 'confirm'];
        $this->assertSame($expected, CompletionEscaper::filterSafeList($input));
    }

    public function testFilterSafeListReturnsEmptyArrayWhenAllUnsafe(): void
    {
        $input = ['evil;', '$(x)', 'a>b'];
        $this->assertSame([], CompletionEscaper::filterSafeList($input));
    }
}
