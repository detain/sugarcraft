<?php

declare(strict_types=1);

namespace SugarCraft\Ignore\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ignore\Token;

final class TokenTest extends TestCase
{
    public function testTagsAreDistinct(): void
    {
        $tags = [Token::LITERAL, Token::ONE, Token::CLASS_, Token::STAR, Token::GLOBSTAR, Token::DIRS];

        $this->assertSame($tags, array_values(array_unique($tags)));
    }
}
