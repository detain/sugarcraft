<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\ServerConfig;

/**
 * S-V07 (G-13): serve startup diagnostics are deliberately untranslated, and
 * the ruling is stated at both seats so the next reader does not "fix" them
 * with a catalogue key.
 */
final class OperatorDiagnosticRulingTest extends TestCase
{
    public function testBothSeatsCarryTheRuling(): void
    {
        $webhooks = (string) (new \ReflectionMethod(ServerConfig::class, 'webhooksValue'))->getDocComment();
        $token = (string) (new \ReflectionClass(TokenStore::class))->getDocComment();

        self::assertStringContainsString('operator-only diagnostic', $webhooks);
        self::assertStringContainsString('G-13', $webhooks);
        self::assertStringContainsString('operator-only diagnostics', $token);
        self::assertStringContainsString('G-13', $token);
    }
}
