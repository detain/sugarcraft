<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Host\ContextMeter;
use SugarCraft\Code\Message;
use SugarCraft\Code\Util\TokenEstimate;
use SugarCraft\Core\Util\TokenEstimate as CoreTokenEstimate;

/**
 * S-X09: the app name forwards to the sugar-core implementation (one body),
 * and the two meters that used to spell the +10 role-overhead law twice score
 * an identical fixture identically.
 */
final class TokenEstimateAliasTest extends TestCase
{
    public function testAppNameForwardsToTheCoreClass(): void
    {
        self::assertSame(TokenEstimate::class, (new \ReflectionClass(TokenEstimate::class))->getName());
        self::assertSame(CoreTokenEstimate::MESSAGE_OVERHEAD, TokenEstimate::MESSAGE_OVERHEAD);
        self::assertSame(CoreTokenEstimate::ofToolSchema('t', 'd', ['type' => 'object']), TokenEstimate::ofToolSchema('t', 'd', ['type' => 'object']));
        self::assertSame(CoreTokenEstimate::ofMessages(['a', 'bb']), TokenEstimate::ofMessages(['a', 'bb']));
        self::assertSame(CoreTokenEstimate::ofText('Hello 世界 😀'), TokenEstimate::ofText('Hello 世界 😀'));
    }

    public function testBothMetersScoreTheSameFixtureIdentically(): void
    {
        $bodies = ['plain ascii prompt', 'Привет мир', '猫、すわっている 😀', ''];
        $expected = TokenEstimate::ofMessages($bodies);

        $history = array_map(static fn(string $b): Message => Message::user($b), $bodies);
        self::assertSame($expected, ContextMeter::new()->rawTokens($history));

        $compactor = ContextCompactor::new();
        $count = new \ReflectionMethod($compactor, 'countTokens');
        $count->setAccessible(true);
        self::assertSame($expected, $count->invoke($compactor, array_map(static fn(string $b): array => ['role' => 'user', 'content' => $b], $bodies)));
    }
}
