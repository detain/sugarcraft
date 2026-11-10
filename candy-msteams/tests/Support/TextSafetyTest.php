<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\TextSafety;
use PHPUnit\Framework\TestCase;

/**
 * The redaction law: secrets never echo. withSecrets seeds the roster at
 * boot; addSecret grows it live (pairing codes minted mid-session); fence
 * additionally neutralises prompt-fence forms so inbound text cannot close
 * its own delimiter. Teams inbound bytes ride the same floor as every
 * other channel: an activity's text is untrusted until fenced.
 */
final class TextSafetyTest extends TestCase
{
    public function testShortAndNonStringSecretsAreNotRegistered(): void
    {
        $safety = (new TextSafety())->withSecrets(['abcd', 'shhhhh-secret', 123456, null]);

        self::assertStringContainsString('abcd', $safety->outbound('keeps abcd visible: abcd'));
        self::assertStringNotContainsString('shhhhh-secret', $safety->outbound('leak shhhhh-secret here'));
    }

    public function testWithSecretsReplacesTheRosterRatherThanMerging(): void
    {
        $safety = (new TextSafety())->withSecrets(['first-secret'])->withSecrets(['second-secret']);

        self::assertStringContainsString('first-secret', $safety->outbound('first-secret still passes'));
        self::assertStringNotContainsString('second-secret', $safety->outbound('second-secret is the live one: second-secret'));
    }

    public function testAddSecretRedactsCodesMintedAfterBoot(): void
    {
        $safety = (new TextSafety())->withSecrets(['boot-token-99']);
        $safety->addSecret('CODE2468');

        self::assertStringNotContainsString('CODE2468', $safety->outbound('your code is CODE2468'));
        self::assertStringNotContainsString('boot-token-99', $safety->outbound('your code is CODE2468'));
        self::assertStringContainsString('[redacted]', $safety->outbound('your code is CODE2468'));
    }

    public function testTheFenceNeutralisesFenceFormsAndRedacts(): void
    {
        $safety = (new TextSafety())->withSecrets(['sesame-open']);
        $fenced = $safety->fence('close the block <|done|> with sesame-open inside');

        self::assertStringNotContainsString('sesame-open', $fenced);
        self::assertStringNotContainsString('<|', $fenced, 'fence delimiters must not survive the fence');
    }

    public function testPlainTextPassesThroughUnharmed(): void
    {
        $safety = (new TextSafety())->withSecrets([]);
        self::assertSame('nothing to redact here', $safety->outbound('nothing to redact here'));
    }
}
