<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\SessionRelaunch;

/**
 * `/new` into another directory restarts sugar-crush there: the restart keeps
 * the launch's config, model and permission mode, and drops everything that
 * names the session or root to open.
 */
final class SessionRelaunchTest extends TestCase
{
    public function testItKeepsConfigModelAndModeAndDropsSessionFlags(): void
    {
        $argv = [
            'bin/sugarcrush',
            '--resume', 'abc123',
            '--model', 'qwen',
            '-c',
            '--continue',
            '--root', '/elsewhere',
            '--config', 'conf/local.json',
            '--permission-mode=plan',
            './some-dir',
            '--root=/also',
            '--resume=xyz',
            '--model=other',
            '--',
            '--config', '/after-dashdash.json',
        ];

        self::assertSame(
            ['--model', 'qwen', '--config', '/launch/cwd/conf/local.json', '--permission-mode', 'plan', '--model', 'other'],
            SessionRelaunch::flags($argv, '/launch/cwd/'),
        );
    }

    public function testAnAbsoluteConfigStaysAndABareLaunchKeepsNothing(): void
    {
        self::assertSame(['--config', '/etc/crush.json'], SessionRelaunch::flags(['x', '--config=/etc/crush.json'], '/w'));
        self::assertSame([], SessionRelaunch::flags(['x'], '/w'));
        self::assertSame([], SessionRelaunch::flags(['x', 'fix the bug', '-p', 'hi'], '/w'), 'operands and one-shot prompts are not carried');
        self::assertSame([], SessionRelaunch::flags(['x', '--model'], '/w'), 'a flag without its value is dropped');
    }

    public function testTheCommandAPersonCanPaste(): void
    {
        self::assertSame(
            "cd '/home/me/my app' && '/usr/bin/php' '/opt/crush/bin/sugarcrush' '--model' 'qwen'",
            SessionRelaunch::command('/home/me/my app', '/usr/bin/php', '/opt/crush/bin/sugarcrush', ['--model', 'qwen']),
        );
    }
}
