<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\SecretRedactor;

/**
 * Roadmap 5.2: the redactor auto-memory runs over the transcript before the
 * consolidation request and over every proposed note before it is written.
 */
final class SecretRedactorTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function tokens(): iterable
    {
        // Assembled from pieces so no scanner reads this file as leaking.
        yield 'openai' => ['key ' . 'sk-' . str_repeat('aB3', 8) . ' here', 'key [REDACTED] here'];
        yield 'anthropic' => ['sk-' . 'ant-' . str_repeat('Xy9_', 6), '[REDACTED]'];
        yield 'github' => ['gh' . 'p_' . str_repeat('A1b2', 9), '[REDACTED]'];
        yield 'github pat' => ['github' . '_pat_' . str_repeat('Q7', 15), '[REDACTED]'];
        yield 'google' => ['AI' . 'za' . str_repeat('Sy1-', 9), '[REDACTED]'];
        yield 'slack' => ['xo' . 'xb-' . '1234-5678-abcdef', '[REDACTED]'];
        yield 'aws' => ['AK' . 'IA' . 'ABCDEFGHIJKLMNOP', '[REDACTED]'];
        yield 'jwt' => ['ey' . 'J' . 'hbGciOiJI.' . 'ey' . 'J' . 'zdWIiOiIx.' . 'SflKxwRJSMeKKF2Q', '[REDACTED]'];
        yield 'bearer' => ['Authorization: Bearer ' . str_repeat('t0K3n', 5), 'Authorization: Bearer [REDACTED]'];
        yield 'url password' => ['postgres://app:' . 'hunter2Secret' . '@db.local/app', 'postgres://app:[REDACTED]@db.local/app'];
        yield 'assignment' => ['DB_PASSWORD=' . 'x7Kq9mPz2Lw', 'DB_PASSWORD=[REDACTED]'];
        yield 'json assignment' => ['"api_key": "' . 'Zq81mN0pLk3r' . '"', '"api_key": "[REDACTED]"'];
    }

    #[DataProvider('tokens')]
    public function testKnownSecretShapesAreRedacted(string $text, string $expected): void
    {
        $redactor = SecretRedactor::new();

        self::assertSame($expected, $redactor->redact($text));
        self::assertTrue($redactor->containsSecret($text));
    }

    public function testAPrivateKeyBlockIsRedactedWhole(): void
    {
        $pem = "before\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\nabc\n-----END RSA PRIVATE KEY-----\nafter";

        self::assertSame("before\n[REDACTED]\nafter", SecretRedactor::new()->redact($pem));
    }

    /** @return iterable<string, array{0: string}> */
    public static function ordinaryText(): iterable
    {
        yield 'scp-style git remote' => ['git@github.com:detain/sugarcraft.git'];
        yield 'url without password' => ['https://user@example.com/path'];
        yield 'variable reference' => ['API_KEY=$OPENAI_API_KEY'];
        yield 'braced reference' => ['token: ${GITHUB_TOKEN}'];
        yield 'placeholder' => ['password=<your-password>'];
        yield 'low entropy' => ['password=aaaaaaaaaaaa'];
        yield 'prose about tokens' => ['The tokenizer counts tokens before each request.'];
        yield 'short value' => ['secret=abc'];
    }

    #[DataProvider('ordinaryText')]
    public function testOrdinaryTextIsLeftAlone(string $text): void
    {
        self::assertSame($text, SecretRedactor::new()->redact($text));
        self::assertFalse(SecretRedactor::new()->containsSecret($text));
    }
}
