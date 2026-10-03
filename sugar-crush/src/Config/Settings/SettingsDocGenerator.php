<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * Projects {@see SettingsSchema} onto the pages that document it, so the key
 * tables are generated rather than proof-read.
 *
 * TWO KINDS OF TARGET:
 *  - MARKED BLOCKS — a region between `<!-- settings[:<name>]:begin -->` and the
 *    matching `:end -->` comment is replaced whole. The unnamed block is the
 *    "Every key" table in `docs/SETTINGS.md`.
 *  - THE "SETTINGS KEY" COLUMN of `docs/ENVIRONMENT.md`'s app-variable table —
 *    the last cell of each row is rewritten from
 *    {@see SettingDefinition::$envVar}. A column rather than a block because the
 *    rows themselves are hand-written prose; only that cell is derived.
 *
 * Everything outside those regions is the author's and is never touched. Driven
 * by `tools/gen-settings-doc.php` (`--write` / `--check`), and its `--check`
 * is enforced by {@see \SugarCraft\Crush\Tests\Config\Settings\SettingsSchemaDocDriftTest}.
 * Pure text in, text out: the file I/O lives in those two callers, so this
 * class adds no read or write sink to `src/`.
 */
final class SettingsDocGenerator
{
    public const SETTINGS_DOC = 'docs/SETTINGS.md';
    public const ENVIRONMENT_DOC = 'docs/ENVIRONMENT.md';

    /** The app-variable table's header, as written today (three columns). */
    private const ENV_TABLE_HEADER = '| Variable | Default when unset | Description |';
    private const ENV_COLUMN = 'Settings key';

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The pages this generator rewrites, relative to the sugar-crush directory.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return [self::SETTINGS_DOC, self::ENVIRONMENT_DOC];
    }

    /**
     * The generated content of every marked block, by file then block name
     * (`''` is the unnamed block).
     *
     * @return array<string, array<string, string>>
     */
    public function blocks(): array
    {
        return [
            self::SETTINGS_DOC => [
                '' => $this->everyKeyTable(),
            ],
        ];
    }

    /**
     * Each target page as it should read, given the pages as they read now —
     * both keyed by {@see targets()}. Pure: the caller (the CLI script, the
     * drift test) owns the file I/O, so nothing here reads or writes the disk.
     *
     * @param array<string, string> $pages
     * @return array<string, string>
     */
    public function rendered(array $pages): array
    {
        foreach ($this->targets() as $file) {
            if (!\array_key_exists($file, $pages)) {
                throw new \InvalidArgumentException("missing page {$file}");
            }
        }

        foreach ($this->blocks() as $file => $blocks) {
            foreach ($blocks as $name => $content) {
                $pages[$file] = self::replaceBlock($pages[$file], $name, $content, $file);
            }
        }

        $pages[self::ENVIRONMENT_DOC] = self::withEnvColumn($pages[self::ENVIRONMENT_DOC]);

        return $pages;
    }

    /**
     * The pages whose current text differs from {@see rendered()}.
     *
     * @param array<string, string> $pages
     * @return list<string>
     */
    public function drift(array $pages): array
    {
        $stale = [];
        foreach ($this->rendered($pages) as $file => $text) {
            if ($pages[$file] !== $text) {
                $stale[] = $file;
            }
        }

        return $stale;
    }

    /** The "Every key" table: one row per schema key, in category order. */
    public function everyKeyTable(): string
    {
        $lines = [
            '| Key | Category | Type | Default | Tiers | Env / flag | Applies | Risk |',
            '|---|---|---|---|---|---|---|---|',
        ];
        foreach (SettingsSchema::all() as $d) {
            $override = array_values(array_filter([$d->envVar, $d->cliFlag]));
            $lines[] = '| ' . implode(' | ', [
                '`' . $d->key . '`',
                $d->category->label(),
                $d->type->label(),
                self::defaultCell($d),
                self::tiers($d),
                $override === [] ? '—' : implode(', ', array_map(static fn (string $o): string => '`' . $o . '`', $override)),
                $d->applyMode->badge(),
                $d->riskClass->value,
            ]) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * `P` project files (trusted projects only), `U` `~/.sugar-crush/settings.json`,
     * `C` `config.json` — from {@see SettingDefinition::sources()}.
     */
    public static function tiers(SettingDefinition $d): string
    {
        $sources = $d->sources();
        $tiers = [];
        if (\in_array(SettingSource::ProjectShared, $sources, true)) {
            $tiers[] = 'P';
        }

        if (\in_array(SettingSource::UserSettings, $sources, true)) {
            $tiers[] = 'U';
        }

        $tiers[] = 'C';

        return implode(' ', $tiers);
    }

    /**
     * `$text` with block `$name` replaced by `$content`. A missing or doubled
     * marker pair is an error, never a silent append: a generator that guessed
     * where a lost block belonged would put a table in the wrong section.
     */
    public static function replaceBlock(string $text, string $name, string $content, string $file = 'page'): string
    {
        $tag = $name === '' ? 'settings' : 'settings:' . $name;
        $begin = '<!-- ' . $tag . ':begin -->';
        $end = '<!-- ' . $tag . ':end -->';

        if (substr_count($text, $begin) !== 1 || substr_count($text, $end) !== 1) {
            throw new \RuntimeException("{$file} must carry exactly one {$begin} … {$end} pair");
        }

        $start = strpos($text, $begin) + \strlen($begin);
        $stop = strpos($text, $end);
        if ($stop < $start) {
            throw new \RuntimeException("{$file}: {$end} comes before {$begin}");
        }

        return substr($text, 0, $start) . "\n" . $content . "\n" . substr($text, $stop);
    }

    /**
     * ENVIRONMENT.md with the app table's last column ("Settings key") derived
     * from {@see SettingsSchema::envMap()}: the key a variable outranks, or `—`.
     */
    public static function withEnvColumn(string $text): string
    {
        $lines = explode("\n", $text);
        $header = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, self::ENV_TABLE_HEADER)) {
                $header = $i;
                break;
            }
        }

        if ($header === null) {
            throw new \RuntimeException(self::ENVIRONMENT_DOC . ' has lost its app-variable table header');
        }

        $map = SettingsSchema::envMap();
        $lines[$header] = self::ENV_TABLE_HEADER . ' ' . self::ENV_COLUMN . ' |';
        $lines[$header + 1] = '|----------|--------------------|-------------|--------------|';
        for ($i = $header + 2; $i < \count($lines) && str_starts_with($lines[$i], '|'); ++$i) {
            $cells = preg_split('/(?<!\\\\)\|/', $lines[$i]);
            if ($cells === false || \count($cells) < 5 || preg_match('/^\s*`([A-Z0-9_]+)`\s*$/', $cells[1], $m) !== 1) {
                throw new \RuntimeException(self::ENVIRONMENT_DOC . ' app-table row ' . ($i + 1) . ' is not a `VARIABLE` row');
            }

            $key = $map[$m[1]] ?? null;
            $cells = \array_slice($cells, 0, 4);
            $cells[] = ' ' . ($key === null ? '—' : '`' . $key . '`') . ' ';
            $cells[] = '';
            $lines[$i] = implode('|', $cells);
        }

        return implode("\n", $lines);
    }

    private static function defaultCell(SettingDefinition $d): string
    {
        $default = $d->default;

        return match (true) {
            $default === [] && $d->type === SettingType::Map => '`{}`',
            $default === null => 'unset',
            \is_bool($default) => $default ? '`true`' : '`false`',
            \is_int($default), \is_float($default) => '`' . $default . '`',
            \is_string($default) => '`' . $default . '`',
            default => '`' . json_encode($default, \JSON_UNESCAPED_SLASHES) . '`',
        };
    }
}
