<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;

/**
 * The item-length cap on `strings()` (CWPD-19): the list was bounded in
 * count and element type only, so a single item inside an otherwise legal
 * frame could be arbitrarily large. Every other list and string read on
 * this class carries a length bound; this closes the last unbounded one.
 */
final class ParamsTest extends TestCase
{
    public function testAListItemAtTheDefaultCapIsAdmitted(): void
    {
        $params = Params::of(['tags' => [\str_repeat('t', 4096), 'ok']]);

        $this->assertSame([\str_repeat('t', 4096), 'ok'], $params->strings('tags'));
    }

    public function testAListItemOneBytePastTheDefaultCapIsRefused(): void
    {
        $params = Params::of(['tags' => [\str_repeat('t', 4097)]]);

        try {
            $params->strings('tags');
            $this->fail('an item one byte past the cap was admitted');
        } catch (RpcError $error) {
            // The refusal names the parameter, never the offending content.
            $this->assertStringContainsString('tags has an item longer than 4096 bytes', $error->getMessage());
            $this->assertStringNotContainsString(\str_repeat('t', 16), $error->getMessage());
        }
    }

    public function testTheCapCanBeNarrowedPerCall(): void
    {
        $atCap = Params::of(['caps' => ['abcdefgh']]);
        $this->assertSame(['abcdefgh'], $atCap->strings('caps', 1000, 8));

        $overCap = Params::of(['caps' => ['abcdefghi']]);
        $this->expectException(RpcError::class);
        $this->expectExceptionMessage('caps has an item longer than 8 bytes');
        $overCap->strings('caps', 1000, 8);
    }

    /**
     * CWPD-18 was REFUTED at review: the claim that `has()` reports an
     * explicit null as present does not hold. This pins the refuted state
     * so the contract stays visible.
     */
    public function testAnExplicitNullReadsAsAbsentOnEveryLeg(): void
    {
        $params = Params::of(['label' => null, 'count' => null, 'tags' => null]);

        $this->assertFalse($params->has('label'));
        $this->assertNull($params->optionalString('label'));
        $this->assertSame(7, $params->int('count', 7));
        $this->assertTrue($params->bool('count', true));
        $this->assertSame([], $params->strings('tags'));
    }
}
