<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media\Sd;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\UrlGuard;

/**
 * W1.3 origin-pin semantics. The scenario the plan names as definition-of-
 * done: an operator pointing `sd.baseUrl` at `http://skynet2.lan:30001` must
 * get service — WebFetch's BLOCKED_HOSTNAMES dialing would have refused that
 * private host outright (mystage fact (c), W0 finding #3). UrlGuard therefore
 * screen NOTHING but origin equality: no address class is special here, and a
 * test below proves a private/LAN-looking origin sails through.
 */
final class UrlGuardTest extends TestCase
{
    public function testLanOriginIsLegitimateNotBlocked(): void
    {
        // The asymmetry pin: this exact URL shape is what WebFetch refuses.
        $guard = UrlGuard::forBase('http://skynet2.lan:30001');

        self::assertSame(
            'http://skynet2.lan:30001/sdapi/v1/txt2img',
            $guard->absolute('/sdapi/v1/txt2img'),
        );
    }

    public function testPrivateAddressOriginPassesByDesign(): void
    {
        $guard = UrlGuard::forBase('http://192.168.4.20:7860');

        self::assertStringStartsWith('http://192.168.4.20:7860/', $guard->absolute('/sdapi/v1/progress'));
    }

    public function testPathPrefixOnBaseIsPreserved(): void
    {
        $guard = UrlGuard::forBase('https://example.test/sd-api/');

        self::assertSame('https://example.test/sd-api/sdapi/v1/samplers', $guard->absolute('/sdapi/v1/samplers'));
    }

    #[DataProvider('rejectedBases')]
    public function testBaseShapesAreRefusedAtParseTime(string $base, string $reasonFragment): void
    {
        try {
            UrlGuard::forBase($base);
            self::fail('expected refusal naming ' . $reasonFragment);
        } catch (SdException $e) {
            self::assertStringContainsString($reasonFragment, $e->getMessage());
            self::assertSame(0, $e->status());
        }
    }

    /** @return list<array{string, string}> */
    public static function rejectedBases(): array
    {
        return [
            'empty' => ['', 'empty'],
            'relative' => ['sd.test:7860', 'absolute'],
            'ftp scheme' => ['ftp://sd.test/x', 'scheme must be http or https'],
            'embedded credentials' => ['http://user:pw@sd.test:7860', 'credentials'],
        ];
    }

    public function testEmbeddedCredentialsNeverLeakIntoTheRefusalMessage(): void
    {
        try {
            UrlGuard::forBase('http://s3cr3t:pw@sd.test');
            self::fail('expected refusal');
        } catch (SdException $e) {
            self::assertStringNotContainsString('s3cr3t', $e->getMessage());
        }
    }

    #[DataProvider('smuggledPaths')]
    public function testPathSlotsCannotCarryAnAbsoluteUrl(string $path): void
    {
        $guard = UrlGuard::forBase('http://sd.test:7860');

        $this->expectException(SdException::class);
        $guard->absolute($path);
    }

    /** @return list<array{string}> */
    public static function smuggledPaths(): array
    {
        return [
            'scheme full' => ['http://evil.test/x'],
            'schemeless authority' => ['//evil.test/x'],
            'userinfo bait' => ['/x@evil.test'],
            'backslash origin trick' => ['/\\evil.test'],
            'relative' => ['sdapi/v1/txt2img'],
        ];
    }

    public function testCrossPortRedirectRefused(): void
    {
        $guard = UrlGuard::forBase('http://sd.test:7860');

        try {
            $guard->redirectTarget('http://sd.test:7861/elsewhere');
            self::fail('expected off-origin refusal');
        } catch (SdException $e) {
            self::assertStringContainsString('outside the configured origin', $e->getMessage());
        }
    }

    public function testCrossSchemeRedirectRefused(): void
    {
        $guard = UrlGuard::forBase('https://sd.test');

        $this->expectException(SdException::class);
        $guard->redirectTarget('http://sd.test/x');
    }

    public function testSameOriginRedirectsAreAcceptedInBothShapes(): void
    {
        $guard = UrlGuard::forBase('http://sd.test:7860');

        self::assertSame('http://sd.test:7860/sdapi/v1/options', $guard->redirectTarget('/sdapi/v1/options'));
        self::assertSame('http://sd.test:7860/x', $guard->redirectTarget('http://sd.test:7860/x'));
        // Explicit default port is the same origin as its absence.
        $default = UrlGuard::forBase('http://sd.test');
        self::assertSame('http://sd.test:80/y', $default->redirectTarget('http://sd.test:80/y'));
    }

    public function testRedirectCarryingCredentialsRefused(): void
    {
        $guard = UrlGuard::forBase('http://sd.test:7860');

        $this->expectException(SdException::class);
        $guard->redirectTarget('http://sd.test:7860@evil.test/x');
    }
}
