<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Host\SubmitOptions;

/**
 * Roadmap O-2g / Appendix O §6.6: a submission names its delivery, its
 * idempotency key and whether its mentions are read; the default is what
 * Enter does in the TUI.
 */
final class SubmitOptionsTest extends TestCase
{
    public function testTheDefaultIsTheKeyboards(): void
    {
        $options = SubmitOptions::new();

        self::assertNull($options->delivery);
        self::assertSame(QueueMode::onEnter(), $options->effectiveDelivery());
        self::assertNull($options->idempotencyKey);
        self::assertTrue($options->resolveMentions);
    }

    public function testWithersReturnCopies(): void
    {
        $base = SubmitOptions::new();
        $changed = $base->withDelivery(QueueMode::Followup)->withIdempotencyKey('k')->withResolveMentions(false);

        self::assertNotSame($base, $changed);
        self::assertNull($base->delivery, 'the original is untouched');
        self::assertSame(QueueMode::Followup, $changed->effectiveDelivery());
        self::assertSame('k', $changed->idempotencyKey);
        self::assertFalse($changed->resolveMentions);
        self::assertNull($changed->withIdempotencyKey(null)->idempotencyKey);
    }

    public function testABlankIdempotencyKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SubmitOptions::new()->withIdempotencyKey('  ');
    }
}
