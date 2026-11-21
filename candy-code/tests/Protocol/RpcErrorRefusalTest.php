<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Plugins\Host\RefusedCall;

/**
 * The RefusedCall face of a wire refusal: the in-process door and the bundles read a refusal
 * through refusalKind() and refusalData(), which must match the wire's own `error.data`.
 */
final class RpcErrorRefusalTest extends TestCase
{
    public function testARefusalIsARefusedCallCarryingTheGivenKind(): void
    {
        $error = RpcError::notFound('no such session', 'session_not_found');

        self::assertInstanceOf(RefusedCall::class, $error);
        self::assertSame('session_not_found', $error->refusalKind());
    }

    public function testAnUnnamedRefusalKindIsTheCodesOwnDefault(): void
    {
        $error = RpcError::invalidParams('bad params');

        self::assertSame('invalid_params', $error->refusalKind());
        self::assertSame(['kind' => 'invalid_params'], $error->refusalData());
    }

    public function testRefusalDataIsTheWireDataObjectWithTheKindFirst(): void
    {
        $error = RpcError::of(ErrorCode::InvalidParams, 'bad', 'image_mime_mismatch', ['field' => 'images']);

        self::assertSame(['kind' => 'image_mime_mismatch', 'field' => 'images'], $error->refusalData());
        self::assertSame($error->toArray()['data'], $error->refusalData(), 'the in-process data is the wire data');
    }

    public function testARetryableRefusalCarriesRetryableInItsData(): void
    {
        $error = RpcError::of(ErrorCode::Busy, 'the session is busy');

        self::assertSame(ErrorCode::Busy->kind(), $error->refusalKind());
        self::assertSame(
            ['kind' => ErrorCode::Busy->kind(), 'retryable' => true],
            $error->refusalData(),
        );
    }
}
