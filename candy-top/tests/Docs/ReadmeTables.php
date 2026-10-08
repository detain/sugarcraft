<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Docs;

use SugarCraft\Top\Config\Option;
use SugarCraft\Top\Config\OptionType;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Overlay\OptionsCatalog;
use SugarCraft\Top\Theme\ThemeRegistry;

/**
 * The README's generated blocks, re-derived from the source rosters, and
 * the plumbing to find and (on request) rewrite them.
 *
 * Each block sits between `<!-- BEGIN generated:<name> -->` and
 * `<!-- END generated:<name> -->` in candy-top/README.md. The drift tests
 * compare the text between the markers byte for byte with what these
 * generators produce; `CANDY_TOP_UPDATE_DOCS=1 vendor/bin/phpunit tests/Docs`
 * rewrites the blocks (and marks the tests incomplete) after a deliberate
 * roster change — review the README diff like a golden.
 *
 * Every string is rendered in English (callers reset the locale first),
 * so a developer's LANG cannot make the suite red.
 */
final class ReadmeTables
{
    public const UPDATE_ENV = 'CANDY_TOP_UPDATE_DOCS';

    private function __construct()
    {
    }

    public static function readmePath(): string
    {
        return dirname(__DIR__, 2) . '/README.md';
    }

    /**
     * The key table: one row per {@see KeyTable::keyRows()} row — the key
     * column as the help overlay shows it, the description, and the btop
     * key names the row documents.
     */
    public static function keyTable(): string
    {
        $out = "| Key | Action | btop key names |\n|---|---|---|\n";
        foreach (KeyTable::keyRows() as $row) {
            $out .= '| ' . self::code($row->keyLabel())
                . ' | ' . self::cell($row->description())
                . ' | ' . implode(', ', array_map(self::code(...), $row->bindings))
                . " |\n";
        }

        return $out;
    }

    /**
     * The config table: every persisted {@see Schema} option in file
     * order — type, default, the value law, where the options menu lists
     * it ({@see OptionsCatalog}), and the config.conf description.
     */
    public static function configTable(): string
    {
        $places = self::menuPlaces();
        $out = "| Key | Type | Default | Allowed values | Options menu | Description |\n|---|---|---|---|---|---|\n";
        foreach (Schema::persistedNames() as $name) {
            $option = Schema::option($name);
            \assert($option instanceof Option);
            $description = self::cell($option->description());
            if (\in_array($name, OptionsCatalog::RESTART, true)) {
                $description = '**Restart needed.** ' . $description;
            }
            if (\in_array($name, OptionsCatalog::UNUSED, true)) {
                $description = '**Accepted for btop.conf compatibility, not used yet.** ' . $description;
            }
            $out .= '| ' . self::code($name)
                . ' | ' . $option->type->value
                . ' | ' . self::code(self::scalar($option->default))
                . ' | ' . self::allowed($option)
                . ' | ' . ($places[$name] ?? '—')
                . ' | ' . ($description === '' ? '—' : $description)
                . " |\n";
        }

        return $out;
    }

    /**
     * The bundled theme list, read straight from the themes directory
     * (not through the registry, so a file the registry would skip still
     * shows up as drift), after the two builtins.
     */
    public static function themeList(): string
    {
        $files = self::themeFiles();
        $names = array_map(static fn (string $f): string => self::code(basename($f, '.theme')), $files);

        return \count($files) . " bundled theme files (`candy-top/themes/`) plus the builtin `Default` and `TTY`:\n\n"
            . '`Default` · `TTY` · ' . implode(' · ', $names) . "\n";
    }

    /** @return list<string> every `*.theme` file in the bundled directory, filename order */
    public static function themeFiles(): array
    {
        $files = glob(ThemeRegistry::bundledDir() . '/*.theme') ?: [];
        usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        return $files;
    }

    /** The text between a block's markers, or null when the markers are missing. */
    public static function block(string $readme, string $name): ?string
    {
        [$begin, $end] = self::markers($name);
        $start = strpos($readme, $begin);
        $stop = strpos($readme, $end);
        if ($start === false || $stop === false || $stop < $start) {
            return null;
        }
        $start += \strlen($begin);

        return ltrim(substr($readme, $start, $stop - $start), "\n");
    }

    /** $readme with the block's body replaced by $body (markers kept). */
    public static function replace(string $readme, string $name, string $body): string
    {
        [$begin, $end] = self::markers($name);
        $start = strpos($readme, $begin);
        $stop = strpos($readme, $end);
        if ($start === false || $stop === false || $stop < $start) {
            throw new \RuntimeException("README has no {$begin} … {$end} markers");
        }
        $start += \strlen($begin);

        return substr($readme, 0, $start) . "\n" . $body . substr($readme, $stop);
    }

    /** @return array{string, string} */
    private static function markers(string $name): array
    {
        return ["<!-- BEGIN generated:{$name} -->", "<!-- END generated:{$name} -->"];
    }

    /** @return array<string, string> option name → "tab › heading" */
    private static function menuPlaces(): array
    {
        $places = [];
        foreach (OptionsCatalog::CATEGORIES as $i => $category) {
            $heading = '';
            foreach (OptionsCatalog::entries($category, true) as $entry) {
                if (OptionsCatalog::isHeading($entry)) {
                    $heading = trim(OptionsCatalog::heading($entry));
                    continue;
                }
                $places[$entry] = "{$i} {$category} › " . self::cell($heading);
            }
        }

        return $places;
    }

    private static function allowed(Option $option): string
    {
        if ($option->type === OptionType::Bool) {
            return '`true`, `false`';
        }
        if ($option->type === OptionType::Int) {
            return self::code((string) ($option->min ?? 0)) . '–' . self::code((string) ($option->max ?? Option::INT_MAX));
        }
        if ($option->allowed !== null) {
            return implode(', ', array_map(self::code(...), $option->allowed));
        }
        return $option->hasValidator() ? 'validated text (see description)' : 'any text';
    }

    private static function scalar(bool|int|string $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value) => (string) $value,
            default => '"' . $value . '"',
        };
    }

    /** Inline code that survives a table cell (pipes escaped; backticks never occur in the rosters). */
    private static function code(string $text): string
    {
        return '`' . str_replace('|', '\|', $text) . '`';
    }

    /** Prose for a table cell: HTML-escaped, pipes escaped, line breaks as `<br>`. */
    private static function cell(string $text): string
    {
        $text = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(["\r\n", "\n", '|'], ['<br>', '<br>', '\|'], $text);
    }
}
