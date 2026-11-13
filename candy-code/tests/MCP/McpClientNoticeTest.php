<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;

/**
 * S-P03 (providers-004): the MCP notice bodies are keyed (`mcp.*`) rather than
 * hand-built English, with flat count phrasing so no locale needs plural logic.
 */
final class McpClientNoticeTest extends TestCase
{
    public function testTheMcpNoticeKeysRenderTranslatedAndKeepTechnicalTokens(): void
    {
        $de = Lang::inLocale('de', static fn (): array => [
            Lang::t('mcp.disabled_report', ['count' => 2, 'servers' => 'a, b']),
            Lang::t('mcp.auth_required_row', ['server' => 's', 'status' => 401, 'target' => 'http://x/mcp']),
            Lang::t('mcp.secret_env_stripped', ['servers' => 's (GH_TOKEN)']),
        ]);

        self::assertStringContainsString('deaktiviert', $de[0]);
        self::assertStringContainsString('(2): a, b', $de[0]);
        self::assertStringContainsString('`candy-code mcp auth login http://x/mcp`', $de[1]);
        self::assertStringContainsString('"GITHUB_TOKEN": "${GITHUB_TOKEN}"', $de[2], 'the literal example survives placeholder substitution');
        self::assertStringNotContainsString('MCP stdio servers start', $de[2]);
    }
}
