<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * Projects {@see SettingsSchema} onto the pages that document it, so the key
 * tables are generated rather than proof-read.
 *
 * THREE KINDS OF TARGET:
 *  - MARKED BLOCKS — a region between `<!-- settings[:<name>]:begin -->` and the
 *    matching `:end -->` comment is replaced whole. The unnamed block is the
 *    "Every key" table in `docs/SETTINGS.md`.
 *    The named blocks are the SETTINGS.md layered-key table (`layered`) and its
 *    See-also env split (`env-split`), and README.md's layered roster
 *    (`layered`).
 *  - COUNT ANCHORS ({@see countAnchors()}) — a spelled number inside a
 *    hand-written sentence, rewritten in place.
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
    public const README = 'README.md';

    /** The app-variable table's header, as written today (three columns). */
    private const ENV_TABLE_HEADER = '| Variable | Default when unset | Description |';
    private const ENV_COLUMN = 'Settings key';

    /** An unbreakable space for {@see wrap()}; never reaches a page. */
    private const NBSP = "\x01";

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
        return [self::SETTINGS_DOC, self::ENVIRONMENT_DOC, self::README];
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
                'layered' => $this->layeredTable(),
                'env-split' => $this->envSplitSentence(),
            ],
            self::README => [
                'layered' => $this->readmeLayeredRoster(),
            ],
        ];
    }

    /**
     * Spelled counts inside hand-written prose, which a block cannot carry
     * without cutting the sentence in two: each entry is a page, a pattern
     * whose ONE capture group is the number word, and the number it must
     * spell. The prose around the word stays the author's.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public function countAnchors(): array
    {
        return [
            [self::SETTINGS_DOC, '/`LayeredSettings::LAYERED_KEYS` is exactly these ([a-z]+(?:-[a-z]+)?)\b/', \count(SettingsSchema::layeredKeys())],
            [self::README, '/Even for a trusted project, ([a-z]+(?:-[a-z]+)?) keys are/', \count(self::userTierOnlyKeys())],
        ];
    }

    /**
     * The SETTINGS.md "Which keys are layered" table: every layered key, the
     * reader beside it, and whether a project may set it.
     */
    public function layeredTable(): string
    {
        $lines = ['| Key | Read by | Project may set |', '|---|---|---|'];
        foreach (SettingsSchema::all() as $d) {
            if ($d->layered) {
                $lines[] = '| `' . $d->key . '` | ' . $d->readByText() . ' | ' . ($d->projectSettable ? 'yes' : '**no**') . ' |';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The SETTINGS.md See-also sentence splitting the layered keys into those
     * an environment variable overrides and those it does not.
     */
    public function envSplitSentence(): string
    {
        $with = [];
        $without = [];
        foreach (SettingsSchema::all() as $d) {
            if ($d->layered) {
                $d->envVar === null ? $without[] = $d->key : $with[] = $d->key;
            }
        }

        $total = \count($with) + \count($without);
        $text = 'They do not cover it: only ' . self::spell(\count($with)) . ' of the ' . self::spell($total)
            . ' layered keys have an env' . self::NBSP . 'override (' . self::codeList($with, ', ') . '). '
            . self::codeList($without, ', ', ' and ') . ' have none.';

        return self::wrap($text);
    }

    /** README.md's "Only these … keys are layered" roster sentence. */
    public function readmeLayeredRoster(): string
    {
        $keys = SettingsSchema::layeredKeys();

        return self::wrap('Only these ' . self::spell(\count($keys)) . ' keys are layered — ' . self::codeList($keys, ', ') . '.');
    }

    /**
     * The layered keys no project file may contribute — the schema's own
     * {@see \SugarCraft\Crush\Config\LayeredSettings::userTierOnlyKeys()}.
     *
     * @return list<string>
     */
    public static function userTierOnlyKeys(): array
    {
        return array_values(array_diff(SettingsSchema::layeredKeys(), SettingsSchema::projectTierKeys()));
    }

    /** A count as README/SETTINGS prose spells it: `twenty-four`. */
    public static function spell(int $n): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = [2 => 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        if ($n < 0 || $n > 99) {
            throw new \InvalidArgumentException("cannot spell {$n}");
        }

        if ($n < 20) {
            return $ones[$n];
        }

        return $tens[intdiv($n, 10)] . ($n % 10 === 0 ? '' : '-' . $ones[$n % 10]);
    }

    /** @param list<string> $keys */
    private static function codeList(array $keys, string $glue, ?string $lastGlue = null): string
    {
        $quoted = array_map(static fn (string $k): string => '`' . $k . '`', $keys);
        if ($lastGlue === null || \count($quoted) < 2) {
            return implode($glue, $quoted);
        }

        $last = array_pop($quoted);

        return implode($glue, $quoted) . $lastGlue . $last;
    }

    /**
     * Prose wrapped at 80 columns. {@see NBSP} marks a space the wrap must not
     * break — "env override" is a phrase other doc guards match on one line.
     */
    private static function wrap(string $text): string
    {
        return str_replace(self::NBSP, ' ', wordwrap($text, 80, "\n", false));
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

        foreach ($this->countAnchors() as [$file, $pattern, $count]) {
            $pages[$file] = self::withCount($pages[$file], $pattern, $count, $file);
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

        $beginAt = (int) strpos($text, $begin);
        $start = $beginAt + \strlen($begin);
        $stop = (int) strpos($text, $end);
        if ($stop < $start) {
            throw new \RuntimeException("{$file}: {$end} comes before {$begin}");
        }

        // A block inside a list item is indented with it: the begin marker's
        // indentation is applied to every generated line and to the end marker,
        // so the item does not end where the block starts.
        $lineStart = strrpos(substr($text, 0, $beginAt), "\n");
        $indent = substr($text, $lineStart === false ? 0 : $lineStart + 1, $beginAt - ($lineStart === false ? 0 : $lineStart + 1));
        if (trim($indent) !== '') {
            $indent = '';
        }

        $endLineStart = strrpos(substr($text, 0, $stop), "\n");
        $cut = $endLineStart !== false && $endLineStart >= $start && trim(substr($text, $endLineStart + 1, $stop - $endLineStart - 1)) === ''
            ? $endLineStart + 1
            : $stop;

        $body = implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent . $line,
            explode("\n", $content),
        ));

        return substr($text, 0, $start) . "\n" . $body . "\n" . ($cut === $stop ? '' : $indent) . substr($text, $stop);
    }

    /**
     * `$text` with the number word captured by `$pattern` set to `$count`
     * spelled. Exactly one match, or the anchor's sentence was reworded out from
     * under it and that is an error.
     */
    public static function withCount(string $text, string $pattern, int $count, string $file = 'page'): string
    {
        if (preg_match_all($pattern, $text, $m, \PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException("{$file}: the count anchor {$pattern} must match exactly once");
        }

        [$word, $offset] = $m[1][0];

        return substr($text, 0, $offset) . self::spell($count) . substr($text, $offset + \strlen($word));
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
        if ($d->defaultText !== null) {
            return $d->defaultText;
        }

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
