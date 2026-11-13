<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Background;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Lang;

/**
 * S-T02: the user-visible background-task framing is keyed (model-facing `Output: (none)` stays English per G-13) through Lang::t
 * and the en text equals the pre-keying literal.
 */
final class BackgroundLangKeysTest extends TestCase
{
    public function testEnglishTextMatchesTheOldLiterals(): void
    {
        $this->assertSame('Failed to spawn session process', Lang::t('background.task.spawn.failed'));
        $this->assertSame('Background task failed: boom', Lang::t('background.task.failed', ['reason' => 'boom']));
        $this->assertSame(
            'Background task blocked by a turn hook: nope',
            Lang::t('background.task.turn_blocked', ['reason' => 'nope']),
        );
    }

    public function testModelFacingOutputNoneIsNotKeyedAndStaysEnglish(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['src/Background/BackgroundSession.php', 'src/Commands/BangShell.php'] as $file) {
            $this->assertStringNotContainsString('background.task.output.none', (string) file_get_contents("$root/$file"), $file);
        }
        foreach (glob($root . '/lang/*.php') ?: [] as $lang) {
            $this->assertStringNotContainsString('background.task.output.none', (string) file_get_contents($lang), $lang);
        }
        $this->assertSame('Output: (none)', BackgroundSession::OUTPUT_NONE);
    }
}
