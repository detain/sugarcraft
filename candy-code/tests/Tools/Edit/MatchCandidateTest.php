<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\MatchCandidate;

final class MatchCandidateTest extends TestCase
{
    public function testMatchedInSlicesTheSpan(): void
    {
        $c = new MatchCandidate(4, 5, 'new');

        self::assertSame('quick', $c->matchedIn('the quick brown fox'));
    }

    public function testMatchedInIsByteBased(): void
    {
        $c = new MatchCandidate(0, 2, '');

        self::assertSame("\xC3\xA9", $c->matchedIn("\xC3\xA9abc"));
    }

    public function testMatchedInPastEndIsTruncatedOrEmpty(): void
    {
        self::assertSame('bc', (new MatchCandidate(1, 99, ''))->matchedIn('abc'));
        self::assertSame('', (new MatchCandidate(10, 2, ''))->matchedIn('abc'));
    }

    public function testCarriedFields(): void
    {
        $c = new MatchCandidate(1, 2, 'repl', 'why');
        self::assertSame([1, 2, 'repl', 'why'], [$c->offset, $c->length, $c->newString, $c->detail]);
        self::assertNull((new MatchCandidate(0, 0, ''))->detail);
    }
}
