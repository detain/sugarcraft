<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Memory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Memory\SecretRedactor;

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

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function names(): iterable
    {
        // Whole names from the shared vocabulary — the same words the
        // assignment arm reads before its colon.
        yield 'token' => ['token', true];
        yield 'secret' => ['secret', true];
        yield 'password' => ['password', true];
        yield 'api_key' => ['api_key', true];
        yield 'api-key' => ['api-key', true];
        yield 'API_KEY' => ['API_KEY', true];
        yield 'private_key' => ['private_key', true];
        yield 'client_secret' => ['client_secret', true];
        yield 'auth_token' => ['auth_token', true];
        yield 'access_key' => ['access_key', true];
        yield 'credentials' => ['credentials', true];
        yield 'credential' => ['credential', true];
        yield 'camel case whole name' => ['apiKey', true];
        // Names that only RESEMBLE the vocabulary stay the writer's own.
        yield 'qualified' => ['my_api_key', false];
        yield 'pluralised' => ['tokens', false];
        yield 'embedded' => ['tokenizer', false];
        yield 'secretary' => ['secretary', false];
        yield 'bare key' => ['key', false];
        yield 'label' => ['label', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('names')]
    public function testTheKeyArmMatchesWholeSecretNamesOnly(string $name, bool $expected): void
    {
        self::assertSame($expected, SecretRedactor::isSecretName($name));
    }

    public function testTheKeyArmSharesTheAssignmentArmVocabulary(): void
    {
        // One vocabulary, two arms: every word the assignment arm reads
        // before its colon is also a whole key name on its own.
        $assignment = (new \ReflectionClass(SecretRedactor::class))->getConstant('ASSIGNMENT_PATTERN');
        $keyArm = (new \ReflectionClass(SecretRedactor::class))->getConstant('KEY_NAME_PATTERN');

        self::assertIsString($assignment);
        self::assertIsString($keyArm);
        self::assertStringContainsString(SecretRedactor::SECRET_NAME_WORDS, $assignment);
        self::assertStringContainsString(SecretRedactor::SECRET_NAME_WORDS, $keyArm);
    }
}
