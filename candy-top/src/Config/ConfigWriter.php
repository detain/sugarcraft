<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * Renders a {@see Config} as a btop-style config.conf.
 *
 * Mirrors aristocratos/btop Config::current_config: a `#?` header line,
 * then per persisted option a blank line, its description as comment lines,
 * and `name = value` — strings double-quoted, ints bare, bools lower-case
 * `true`/`false`. Runtime-only keys are never written.
 */
final class ConfigWriter
{
    private function __construct()
    {
    }

    /**
     * The `#?` header line (without the marker). Deliberately not localized:
     * it is a machine marker — {@see ConfigReader::isCurrentHeader()} keys the
     * rewrite decision off its `btop v.<version>` part, which must read the
     * same whatever locale wrote the file.
     */
    public static function header(): string
    {
        return 'Config file for candy-top (btop v.' . Schema::BTOP_VERSION . ' compatible)';
    }

    /** Full file contents for $config. */
    public static function render(Config $config): string
    {
        $out = '#? ' . self::header() . "\n";

        foreach (Schema::persistedNames() as $name) {
            $out .= "\n";
            $description = Schema::option($name)?->description() ?? '';
            if ($description !== '') {
                foreach (explode("\n", $description) as $line) {
                    $out .= '#* ' . $line . "\n";
                }
            }
            $out .= $name . ' = ' . self::format($config->value($name)) . "\n";
        }

        return $out;
    }

    /** One value in its file spelling. */
    public static function format(bool|int|string $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            default => '"' . $value . '"',
        };
    }
}
