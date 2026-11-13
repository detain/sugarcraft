<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Jwk;
use CandyCode\Bundles\MsTeams\MsTeams\Jwt;
use CandyCode\Bundles\MsTeams\MsTeams\JwtException;
use PHPUnit\Framework\TestCase;

/**
 * §8.1: the JWT accept/refuse matrix, fully offline — fixture RSA keys
 * generated per process, tokens signed by this file, the roster injected as
 * a closure. No network leg exists here by construction.
 */
final class JwtTest extends TestCase
{
    private const APP_ID = 'app-guid-1';

    /** @var array{0: \OpenSSLAsymmetricKey, 1: string}|null kid-material [privateKey, publicPem] */
    private static ?array $keyPair = null;

    private static string $kid = 'fixture-kid-1';

    public static function setUpBeforeClass(): void
    {
        if (self::$keyPair === null) {
            $key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($key);
            $details = \openssl_pkey_get_details($key);
            self::assertNotFalse($details);
            self::$keyPair = [$key, (string) $details['key']];
        }
    }

    /** @return \Closure(): array<string,string> */
    private static function roster(): \Closure
    {
        $jwk = Jwk::fromPublicKey(self::requirePrivateKey(self::$keyPair), self::$kid);
        $pem = Jwk::toPem($jwk);

        return static fn (): array => [self::$kid => $pem];
    }

    private static function requirePrivateKey(?array $pair): \OpenSSLAsymmetricKey
    {
        self::assertIsArray($pair);

        return $pair[0];
    }

    /** @param array<string,mixed> $header @param array<string,mixed> $claims */
    private static function sign(array $header, array $claims): string
    {
        $b64 = static fn (string $raw): string => \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
        $head = $b64((string) \json_encode($header));
        $body = $b64((string) \json_encode($claims));
        $signature = '';
        self::assertTrue(\openssl_sign($head . '.' . $body, $signature, self::requirePrivateKey(self::$keyPair), \OPENSSL_ALGO_SHA256));

        return $head . '.' . $body . '.' . $b64($signature);
    }

    /** @param array<string,mixed> $overrides */
    private static function token(array $overrides = [], ?string $kid = null): string
    {
        $now = time();
        $claims = \array_merge(['aud' => self::APP_ID, 'iss' => 'https://api.botframework.com', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600], $overrides);

        return self::sign(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid ?? self::$kid], $claims);
    }

    public function testAProperlySignedTokenForThisAudienceIsAccepted(): void
    {
        $claims = (new Jwt(self::APP_ID, self::roster()))->validate(self::token());
        self::assertSame(self::APP_ID, $claims['aud']);
    }

    public function testATamperedPayloadRefuses(): void
    {
        $compact = self::token();
        [$head, $body, $sig] = \explode('.', $compact);
        $evil = \json_decode(\base64_decode(\strtr($body, '-_', '+/') . '=='), true);
        $evil['aud'] = 'someone-elses-app';
        $b64 = static fn (string $raw): string => \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
        $forged = $head . '.' . $b64((string) \json_encode($evil)) . '.' . $sig;

        $this->expectRefusal($forged, 'sig', $compact);
    }

    public function testAlgNoneRefuses(): void
    {
        $b64 = static fn (string $raw): string => \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
        $head = $b64((string) \json_encode(['alg' => 'none', 'kid' => self::$kid]));
        $now = time();
        $body = $b64((string) \json_encode(['aud' => self::APP_ID, 'iss' => 'https://api.botframework.com', 'exp' => $now + 100]));
        $this->expectRefusal($head . '.' . $body . '.', 'alg');
    }

    public function testHs256ConfusionRefuses(): void
    {
        $compact = self::sign(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => self::$kid], ['aud' => self::APP_ID, 'iss' => 'https://api.botframework.com', 'exp' => time() + 100]);
        $this->expectRefusal($compact, 'alg');
    }

    public function testForeignIssuerRefuses(): void
    {
        $this->expectRefusal(self::token(['iss' => 'https://evil.example']), 'iss');
    }

    public function testForeignAudienceRefuses(): void
    {
        $this->expectRefusal(self::token(['aud' => 'other-app']), 'aud');
    }

    public function testAnEmptyConfiguredAppIdRefusesEverything(): void
    {
        $this->expectException(JwtException::class);
        (new Jwt('', self::roster()))->validate(self::token());
    }

    public function testExpiredTokenRefuses(): void
    {
        $this->expectRefusal(self::token(['exp' => time() - 120]), 'exp');
    }

    public function testNotYetValidTokenRefuses(): void
    {
        $this->expectRefusal(self::token(['nbf' => time() + 120]), 'nbf');
    }

    public function testUnknownKidRefuses(): void
    {
        $this->expectRefusal(self::token(kid: 'roster-does-not-know-me'), 'kid');
    }

    public function testGarbageCompactsRefuse(): void
    {
        foreach (['', 'a.b', 'a.b.c.d', 'not.base64!!!@#$'] as $garbage) {
            $this->expectException(JwtException::class);
            (new Jwt(self::APP_ID, self::roster()))->validate($garbage);
        }
    }

    public function testSignatureUnderADifferentKeyRefuses(): void
    {
        // Same kid header, different private key behind the signature: the
        // roster's key must reject the foreign math (wrong-material leg).
        $other = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($other);
        $b64 = static fn (string $raw): string => \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
        $head = $b64((string) \json_encode(['alg' => 'RS256', 'kid' => self::$kid]));
        $now = time();
        $body = $b64((string) \json_encode(['aud' => self::APP_ID, 'iss' => 'https://api.botframework.com', 'exp' => $now + 100]));
        $sig = '';
        self::assertTrue(\openssl_sign($head . '.' . $body, $sig, $other, \OPENSSL_ALGO_SHA256));

        $this->expectRefusal($head . '.' . $body . '.' . $b64($sig), 'sig');
    }

    public function testAFailedRosterFetchIsNotRepeatedInsideItsNegativeWindow(): void
    {
        $dials = 0;
        $jwt = new Jwt(self::APP_ID, static function () use (&$dials): array {
            $dials++;
            throw new JwtException('roster unreachable', 'roster');
        });
        for ($i = 0; $i < 5; $i++) {
            try {
                $jwt->validate(self::token());
                self::fail('an unavailable roster must refuse');
            } catch (JwtException $e) {
                self::assertSame('roster', $e->reason);
            }
        }

        self::assertSame(1, $dials, 'an outage costs one bounded fetch per window, not one per inbound request');
    }

    public function testAnEmptyRosterCountsAsAFailedFetch(): void
    {
        $dials = 0;
        $jwt = new Jwt(self::APP_ID, static function () use (&$dials): array {
            $dials++;

            return [];
        });
        for ($i = 0; $i < 3; $i++) {
            try {
                $jwt->validate(self::token());
            } catch (JwtException) {
            }
        }

        self::assertSame(1, $dials);
    }

    private function expectRefusal(string $compact, string $reason, string $context = ''): void
    {
        try {
            (new Jwt(self::APP_ID, self::roster()))->validate($compact);
            self::fail('expected refusal reason ' . $reason . ' (' . $context . ')');
        } catch (JwtException $e) {
            self::assertSame($reason, $e->reason, $context);
            $segments = \explode('.', $compact);
            foreach ([1, 2] as $idx) {
                if (($segments[$idx] ?? '') !== '') {
                    self::assertStringNotContainsString($segments[$idx], $e->getMessage(), 'refusal prose must not echo token segments');
                }
            }
        }
    }
}
