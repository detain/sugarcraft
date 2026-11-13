<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\StatusCommand;
use SugarCraft\Code\Lang;

/**
 * The `/status` page says when the context window is an assumed figure, and
 * stays silent about it when the backend reported the window itself.
 */
final class StatusContextAssumedTest extends TestCase
{
    public function testAnAssumedWindowIsSaidOnTheContextRow(): void
    {
        $page = StatusCommand::compose(self::facts(['contextWindowAssumed' => true]));

        self::assertStringContainsString(Lang::t('cmd.context.window.assumed', ['tokens' => '200,000']), $page);
    }

    public function testAMeasuredWindowSaysNothingAboutAssumption(): void
    {
        $page = StatusCommand::compose(self::facts(['contextWindowAssumed' => false]));

        self::assertStringNotContainsString(Lang::t('cmd.context.window.assumed', ['tokens' => '200,000']), $page);
    }

    /** @param array<string, mixed> $overrides */
    private static function facts(array $overrides): array
    {
        return $overrides + [
            'version' => '1.0.0', 'configPath' => '/c.json', 'theme' => 'dark',
            'sessionId' => 's', 'sessionName' => 'n', 'sessionTurns' => 1, 'cwd' => '/p', 'branch' => 'main',
            'provider' => 'p', 'model' => 'm', 'contextTokens' => 1000, 'contextTokenLimit' => 200_000,
            'spend' => '$0', 'ledger' => null, 'mcpCount' => 0, 'mcpReason' => null, 'hookCount' => 0,
            'hookReason' => null, 'serveState' => 'absent', 'serve' => null, 'serveReason' => null,
        ];
    }
}
