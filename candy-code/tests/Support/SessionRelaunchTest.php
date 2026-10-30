<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\SessionRelaunch;

/**
 * `/new` into another directory restarts candy-code there: the restart keeps
 * the launch's config, model, permission mode, spend cap and launch
 * narrowing switches (C-SEC-7.b), and drops everything that names the
 * session or root to open.
 */
final class SessionRelaunchTest extends TestCase
{
    public function testItKeepsConfigModelAndModeAndDropsSessionFlags(): void
    {
        $argv = [
            'bin/candy-code',
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

    public function testTheSpendCapSurvivesTheRestart(): void
    {
        self::assertSame(
            ['--max-cost', '5'],
            SessionRelaunch::flags(['x', '--max-cost', '5'], '/w'),
        );
        self::assertSame(
            ['--max-cost', '5'],
            SessionRelaunch::flags(['x', '--max-cost=5'], '/w'),
        );
    }

    /**
     * C-SEC-7.b: the launch-narrowing switches survive `/new`, because the
     * whole point of a narrowing is that it outlives the session row it was
     * typed beside. Each is carried as the RAW token — bare form stays bare,
     * `=false` stays `=false` — so the restart re-judges exactly what the
     * operator wrote through the same parser verdict, never a rewritten
     * guess; and a switch never consumes the token after it, which is the
     * KEPT_FLAGS law their shapes rest on.
     */
    public function testTheLaunchNarrowingSwitchesSurviveTheRestart(): void
    {
        self::assertSame(
            ['--restricted', '--model', 'qwen'],
            SessionRelaunch::flags(['x', '--restricted', '--model', 'qwen'], '/w'),
            'a bare switch carries verbatim and swallows neither the flag nor the value after it',
        );
        self::assertSame(
            ['--safe-mode=false', '--restricted'],
            SessionRelaunch::flags(['x', '--safe-mode=false', '--restricted'], '/w'),
            'the = form rides as its own verdict, not as a re-typed bare arm',
        );
        self::assertSame(
            ['--config', '/w/conf.json', '--restricted', '--safe-mode'],
            SessionRelaunch::flags(
                ['x', '--config', 'conf.json', '--restricted', '--root', '/elsewhere', '--safe-mode', './dir'],
                '/w',
            ),
            'switches keep argv order beside the value flags and drop the session/root words with everything else',
        );
        self::assertSame(
            [],
            SessionRelaunch::flags(['x', '--', '--restricted', '--safe-mode'], '/w'),
            'the dashdash terminator binds the switches exactly as it binds the value flags',
        );
    }

    public function testTheCommandAPersonCanPaste(): void
    {
        self::assertSame(
            "cd '/home/me/my app' && '/usr/bin/php' '/opt/crush/bin/candy-code' '--model' 'qwen'",
            SessionRelaunch::command('/home/me/my app', '/usr/bin/php', '/opt/crush/bin/candy-code', ['--model', 'qwen']),
        );
    }
}
