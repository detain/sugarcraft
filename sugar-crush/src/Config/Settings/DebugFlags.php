<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * The `debug.*` settings (roadmap N-P4g): the persistent spelling of the
 * `SUGARCRUSH_DEBUG_{SKILLS,COMMANDS,RULES,STREAM}` switches, which put a
 * loader's refusals (or `Chat`'s observer-failure line) back on stderr.
 *
 * ONE RULE FOR ALL FOUR, so the readers cannot drift on it: a variable that is
 * set to anything non-empty DECIDES — `0` off, anything else on, as every
 * `SUGARCRUSH_*` switch reads — and an unset or empty one leaves the answer to
 * the setting. Each reader keeps its own `getenv()` call and hands the value
 * here, so the variable stays a direct, scannable read where it always was.
 * User tier only and read at launch (the loaders run once while the session
 * is built); the values go through {@see UiSettings}, so a settings file that
 * does not parse costs the flag, never the launch.
 */
final class DebugFlags
{
    public const SKILLS = 'debug.skills';
    public const COMMANDS = 'debug.commands';
    public const RULES = 'debug.rules';
    public const STREAM = 'debug.stream';

    private function __construct()
    {
    }

    /**
     * Whether a debug report is asked for: `$environment` (the reader's own
     * `getenv()` result) when it says anything, else the `$key` setting.
     */
    public static function requested(string|false $environment, string $key): bool
    {
        if ($environment !== false && $environment !== '') {
            return $environment !== '0';
        }

        return UiSettings::bool($key);
    }
}
