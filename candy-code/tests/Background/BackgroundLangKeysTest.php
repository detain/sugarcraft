<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Background;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;

/**
 * S-T02: the user-visible background-task framing is keyed through Lang::t
 * and the en text equals the pre-keying literal.
 */
final class BackgroundLangKeysTest extends TestCase
{
    public function testEnglishTextMatchesTheOldLiterals(): void
    {
        $this->assertSame('Output: (none)', Lang::t('background.task.output.none'));
        $this->assertSame('Failed to spawn session process', Lang::t('background.task.spawn.failed'));
        $this->assertSame('Background task failed: boom', Lang::t('background.task.failed', ['reason' => 'boom']));
        $this->assertSame(
            'Background task blocked by a turn hook: nope',
            Lang::t('background.task.turn_blocked', ['reason' => 'nope']),
        );
    }

    public function testBothOutputNoneSeatsReadTheSharedKey(): void
    {
        $root = dirname(__DIR__, 2) . '/src/';
        foreach (['Background/BackgroundSession.php', 'Commands/BangShell.php'] as $file) {
            $src = (string) file_get_contents($root . $file);
            $this->assertStringContainsString("Lang::t('background.task.output.none')", $src, $file);
            $this->assertStringNotContainsString("'Output: (none)'", $src, $file);
        }
    }
}
