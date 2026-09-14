<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\OAuthPkce;

/**
 * E701: the PKCE primitives behind the authorization-code login.
 *
 * The challenge vectors are NOT memorized: each pair was computed with
 * Python's hashlib/base64 — an implementation independent of this one — via
 * the RFC 7636 §4.2 construction base64url(SHA-256(verifier)). Pinning
 * independently derived pairs is what catches a mutated digest step, a
 * swapped encoding, or a lost `-`/`_` translation: every one of those
 * changes the vector. (The suite deliberately does NOT pin the Appendix B
 * pair from memory — memorized halves of it disagreed with both PHP and
 * Python here, which is exactly the failure mode an unverified literal
 * carries.)
 *
 * @see OAuthPkce
 */
final class OAuthPkceTest extends TestCase
{
    public function testTheVerifierIsFortyThreeCharactersInTheUnreservedAlphabet(): void
    {
        $verifier = OAuthPkce::newVerifier();

        // RFC 7636 §4.1: [A-Za-z0-9\-._~], 43..128 characters. 32 random
        // bytes in base64url strip-to-padding land on exactly 43.
        self::assertSame(43, strlen($verifier));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $verifier);
        self::assertStringNotContainsString('=', $verifier, 'padding survived — a padded verifier breaks the query-safe alphabet');
        self::assertStringNotContainsString('+', $verifier);
        self::assertStringNotContainsString('/', $verifier);
    }

    public function testConsecutiveVerifiersAreDistinct(): void
    {
        $seen = [];
        for ($i = 0; $i < 32; $i++) {
            $seen[] = OAuthPkce::newVerifier();
        }

        self::assertCount(32, array_unique($seen), 'the verifier stream repeated — it is not random_bytes-backed');
    }

    public function testTheChallengeMatchesTheIndependentlyComputedVectors(): void
    {
        self::assertSame(
            'gMhFviSMvh4p6Dk0JJBqmff50a_bngH3n_i14zTH5Z4',
            OAuthPkce::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXK'),
        );
        self::assertSame(
            '7NcYcNGWMxapfjrDQIyYNa2M8PPBvHA1J8MCZVNPda4',
            OAuthPkce::challengeFor('test123'),
        );
        self::assertSame(
            '5LHhu_oLwljFqKhv1sYXCCOtwTXz_d7XD3FPeq6Xwm4',
            OAuthPkce::challengeFor('unreserved_test-chars-9_8_7'),
        );
    }

    public function testTheChallengeIsDeterministicAndAlsoUnpadded(): void
    {
        $first = OAuthPkce::challengeFor('a-fixed-verifier-for-determinism');
        $second = OAuthPkce::challengeFor('a-fixed-verifier-for-determinism');

        self::assertSame($first, $second, 'challengeFor() is a pure function — same verifier, same challenge');
        self::assertSame(43, strlen($first));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $first);
    }

    public function testTheOnlyOfferedMethodIsS256(): void
    {
        self::assertSame('S256', OAuthPkce::METHOD);
    }
}
