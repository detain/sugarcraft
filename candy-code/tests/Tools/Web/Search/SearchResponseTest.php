<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Web\Search;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Web\Search\SearchHit;
use SugarCraft\Code\Tools\Web\Search\SearchResponse;

final class SearchResponseTest extends TestCase
{
    public function testFailedCarriesOnlyTheError(): void
    {
        $r = SearchResponse::failed('boom');

        self::assertSame('boom', $r->error);
        self::assertSame([], $r->leading);
        self::assertSame([], $r->hits);
        self::assertSame(0, $r->total);
        self::assertSame([], $r->trailing);
    }

    public function testOkHasNoErrorAndKeepsPartsInOrder(): void
    {
        $hit = SearchHit::new('t', 'u', 'c');
        $r = SearchResponse::ok(['lead'], [$hit], 42, ['trail']);

        self::assertNull($r->error);
        self::assertSame(['lead'], $r->leading);
        self::assertSame([$hit], $r->hits);
        self::assertSame(42, $r->total);
        self::assertSame(['trail'], $r->trailing);
    }

    public function testOkWithNothingIsStillNotAnError(): void
    {
        $r = SearchResponse::ok([], [], 0, []);

        self::assertNull($r->error);
        self::assertSame(0, $r->total);
    }

    public function testConstructorIsPrivate(): void
    {
        self::assertTrue((new \ReflectionClass(SearchResponse::class))->getConstructor()?->isPrivate());
    }
}
