<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Web\Search;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Web\Search\SearchHit;

final class SearchHitTest extends TestCase
{
    public function testNewCarriesFields(): void
    {
        $hit = SearchHit::new('Title', 'https://example.test/a', 'snippet');

        self::assertSame('Title', $hit->title);
        self::assertSame('https://example.test/a', $hit->url);
        self::assertSame('snippet', $hit->content);
    }

    public function testDefaultsFillMissingParts(): void
    {
        $hit = SearchHit::new();

        self::assertSame('Untitled', $hit->title);
        self::assertSame('No URL', $hit->url);
        self::assertSame('', $hit->content);
    }

    public function testPartialArgumentsKeepOtherDefaults(): void
    {
        $hit = SearchHit::new('Only title');

        self::assertSame('Only title', $hit->title);
        self::assertSame('No URL', $hit->url);
    }

    public function testFieldsAreReadonly(): void
    {
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        SearchHit::new()->title = 'x';
    }
}
