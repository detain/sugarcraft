<?php declare(strict_types=1);

/*
 * Independent M2 micro-probe for the C-PRV-2.b review (READ-ONLY; run via the
 * gated phpunit binary so it uses the same PHP 8.3.x interpreter as the suite).
 *
 * Claim under test (OpenAIProvider.php:344-346 docblock): direct `Trait::CONST`
 * access is a PHP fatal regardless of visibility. If testA passes, the claim is
 * FALSE for public consts; if it errors, the fixer's rejection stands.
 */

use PHPUnit\Framework\TestCase;

trait ProbePublic
{
    public const K = 'pub-ok';
}

trait ProbeOther
{
    public function readCrossTraitDirectly(): string
    {
        return ProbePublic::K;
    }
}

final class ProbeHost
{
    use ProbeOther;
}

final class M2AdjudicationProbeTest extends TestCase
{
    public function testRuntimeStamp(): void
    {
        $this->assertSame('forced-echo', '', 'PROBE PHP_VERSION=' . PHP_VERSION);
    }

    public function testADirectPublicTraitConstAccess(): void
    {
        $this->assertSame('pub-ok', \ProbePublic::K);
    }

    public function testBCrossTraitDirectAccessInsideMethod(): void
    {
        $this->assertSame('pub-ok', (new ProbeHost())->readCrossTraitDirectly());
    }
}
