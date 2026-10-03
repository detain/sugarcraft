<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsDocGenerator;

/**
 * Renders the documentation derived from {@see ToolCatalog}: the README tool
 * roster, the permission-class lists, the launch-report samples and every
 * spelled tool count in README, ARCHITECTURE, AGENTS_AUTHORING, PERMISSIONS and
 * SETTINGS.
 *
 * Three kinds of region, all rewritten by `tools/gen-tool-docs.php --write` and
 * checked by `--check` (`ToolDocDriftTest`):
 *
 * - marked blocks, `<!-- tools:NAME:begin -->` … `<!-- tools:NAME:end -->`,
 *   whose whole content is generated;
 * - count anchors, a pattern that matches exactly once with the spelled count
 *   as group 1 (a capitalised word stays capitalised);
 * - the fenced `(disabledTools)` launch-report sample, re-rendered from the
 *   launcher's own format constants.
 */
final class ToolDocGenerator
{
    public const README = 'README.md';
    public const ARCHITECTURE = 'docs/ARCHITECTURE.md';
    public const AGENTS_AUTHORING = 'docs/AGENTS_AUTHORING.md';
    public const PERMISSIONS = 'docs/PERMISSIONS.md';
    public const SETTINGS = 'docs/SETTINGS.md';

    /** The tool the README's deny-glob counterexample leaves standing. */
    private const SURVIVOR = 'Bash';

    /** The sample project root the launch-report examples print. */
    private const SAMPLE_ROOT = '/repo/';

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /** @return list<string> every page this generator rewrites, package-relative */
    public function targets(): array
    {
        return [self::README, self::ARCHITECTURE, self::AGENTS_AUTHORING, self::PERMISSIONS, self::SETTINGS];
    }

    /**
     * @return array<string, array<string, string>> file => block name => content
     */
    public function blocks(): array
    {
        return [
            self::README => [
                'roster' => $this->readmeRoster(),
                'others' => $this->othersList(),
            ],
            self::ARCHITECTURE => [
                'class-list' => self::codeList(array_map(
                    static fn (CatalogEntry $e): string => basename($e->fileName(), '.php'),
                    self::byClassName(ToolCatalog::entries()),
                ), ', ', ', '),
            ],
            self::PERMISSIONS => [
                'classes' => $this->permissionClasses(),
            ],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}> file, pattern (group 1 is the word), count
     */
    public function countAnchors(): array
    {
        $built = \count(ToolCatalog::built());
        $wired = \count(ToolCatalog::entries());

        return [
            [self::README, '/out of the (\w+) built-in tools the project tier can filter/', $built],
            [self::README, '/names none of the (\w+) it removes/', $built - 1],
            [self::README, '/all (\w+) filterable tools survive/', $built],
            [self::SETTINGS, '/reaches the merge at all, and all (\w+) tools survive/', $built],
            [self::SETTINGS, '/names none of the (\w+) tools it\s+removes/', $built - 1],
            [self::SETTINGS, '/never reaches the merge — all (\w+) tools survive/', $built],
            [self::ARCHITECTURE, '/holds \*\*(\w+)\*\* concrete `Tool` classes/', $wired],
            [self::ARCHITECTURE, '/`Bootstrap::tools\(\)` ships all (\w+) —/', $wired],
            [self::ARCHITECTURE, '/\*\*(\w+) is the count of \*wired\* tools/', $wired],
            [self::ARCHITECTURE, '/saying "(\w+) working tools"/', $wired],
            [self::ARCHITECTURE, '/"(\w+) tools" means wired built-ins/', $wired],
            [self::AGENTS_AUTHORING, '/ships (\w+)\s+built-in tools and one of them/', $wired],
        ];
    }

    /**
     * The README Tools bullet from the roster through the count sentence.
     */
    public function readmeRoster(): string
    {
        $items = [];
        foreach (ToolCatalog::built() as $entry) {
            $items[] = '`' . $entry->name . '`' . ($entry->gloss === '' ? '' : ' (' . $entry->gloss . ')');
        }

        $renamed = [];
        foreach (ToolCatalog::built() as $entry) {
            if ($entry->name . '.php' !== $entry->fileName()) {
                $renamed[] = '`' . $entry->name . '` is `' . $entry->fileName() . '`';
            }
        }

        $built = \count(ToolCatalog::built());
        $external = array_map(
            static fn (CatalogEntry $e): string => '`' . $e->fileName() . '`, whose runtime name `' . $e->name . '`',
            ToolCatalog::externallyWired(),
        );

        $text = self::joined($items, ', ', ', and ') . '. These are **runtime tool names**, the same spelling the '
            . 'launch report and every `allowedTools`/`disabledTools`/`permissionRules` pattern uses; '
            . SettingsDocGenerator::spell(\count($renamed)) . ' of them differ from their class file, which is why '
            . 'the list is not a directory listing — ' . implode(', ', $renamed) . '. '
            . ucfirst(SettingsDocGenerator::spell($built)) . ' classes ship on every launch, and `Bootstrap::tools()` '
            . 'ships all ' . SettingsDocGenerator::spell($built);

        return $external === [] ? $text : $text . '; `src/Tools/BuiltIn/` also holds ' . implode(' and ', $external);
    }

    /** The README `allowedTools: ["Bash"]` sentence's list of what it deletes. */
    public function othersList(): string
    {
        $others = array_values(array_filter(
            array_map(static fn (CatalogEntry $e): string => $e->name, ToolCatalog::built()),
            static fn (string $name): bool => $name !== self::SURVIVOR,
        ));

        return 'all ' . SettingsDocGenerator::spell(\count($others)) . ' of the others — '
            . self::codeList($others, ', ', ' and ') . ' —';
    }

    /** The PERMISSIONS "name classes" lead-in, lists and the "neither" note. */
    public function permissionClasses(): string
    {
        $read = ToolCatalog::namesOf(ToolPermissionClass::Read);
        $write = ToolCatalog::namesOf(ToolPermissionClass::Write);
        $noAsk = ToolCatalog::namesOf(ToolPermissionClass::NoAsk);
        $ask = ToolCatalog::namesOf(ToolPermissionClass::Ask);

        $lists = [
            '- **read-only**: ' . self::codeList($read, ', '),
            '- **write-capable**: ' . self::codeList($write, ', ') . ', and anything starting `mcp__`',
        ];
        if ($noAsk !== []) {
            $lists[] = '- **no-ask** (allowed in every mode; they write only harness-owned state): '
                . self::codeList($noAsk, ', ');
        }

        $lines = [
            ucfirst(SettingsDocGenerator::spell(\count($lists))) . ' name classes drive the evaluators. Each built-in '
                . 'tool declares its class in its `#[BuiltInTool]` attribute, and `Tools\Catalog\ToolCatalog` reads '
                . 'them:',
            '',
            ...$lists,
        ];
        if ($ask !== []) {
            $lines[] = '';
            $lines[] = 'Note what is in ' . (\count($lists) === 2 ? '*neither* list' : '*none* of these lists') . ': '
                . self::codeList($ask, ', ', ' and ') . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * The launch-report line a trusted project's `disabledTools: ["[!B]*"]`
     * produces, wrapped as the pages print it.
     */
    public function launchReportSample(): string
    {
        $ceiling = array_map(static fn (CatalogEntry $e): string => $e->name, ToolCatalog::built());
        $removed = array_values(array_filter($ceiling, static fn (string $n): bool => $n !== self::SURVIVOR));

        $line = rtrim(sprintf(
            Bootstrap::STDERR_LINE_FORMAT,
            sprintf(
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT,
                self::SAMPLE_ROOT . LayeredSettings::SHARED_PATH,
                \count($removed),
                \count($ceiling),
                implode(', ', $removed),
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING . self::SURVIVOR,
            ),
        ), "\n");

        return wordwrap($line, 80, "\n", false);
    }

    /**
     * @param array<string, string> $pages package-relative path => text
     *
     * @return array<string, string> the same pages, regenerated
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

        foreach ([self::README, self::SETTINGS] as $file) {
            $pages[$file] = self::withLaunchReport($pages[$file], $this->launchReportSample(), $file);
        }

        return $pages;
    }

    /**
     * @param array<string, string> $pages
     *
     * @return list<string> the pages whose text the generator would change
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

    /**
     * Replace one marked block's content. A begin marker that ends its line
     * makes a line block (content on its own lines); otherwise the block is
     * inline and its content is spliced in verbatim.
     */
    public static function replaceBlock(string $text, string $name, string $content, string $file = 'page'): string
    {
        $begin = '<!-- tools:' . $name . ':begin -->';
        $end = '<!-- tools:' . $name . ':end -->';
        if (substr_count($text, $begin) !== 1 || substr_count($text, $end) !== 1) {
            throw new \RuntimeException("{$file} must carry exactly one {$begin} … {$end} pair");
        }

        $start = (int) strpos($text, $begin) + \strlen($begin);
        $stop = (int) strpos($text, $end);
        if ($stop < $start) {
            throw new \RuntimeException("{$file}: {$end} comes before {$begin}");
        }

        $lineBlock = ($text[$start] ?? '') === "\n";

        return substr($text, 0, $start) . ($lineBlock ? "\n" . $content . "\n" : $content) . substr($text, $stop);
    }

    public static function withCount(string $text, string $pattern, int $count, string $file = 'page'): string
    {
        if (preg_match_all($pattern, $text, $m, \PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException("{$file}: the count anchor {$pattern} must match exactly once");
        }

        [$word, $offset] = $m[1][0];
        $spelled = SettingsDocGenerator::spell($count);
        if (ctype_upper($word[0])) {
            $spelled = ucfirst($spelled);
        }

        return substr($text, 0, $offset) . $spelled . substr($text, $offset + \strlen($word));
    }

    /** Replace the body of the one fenced block holding the `(disabledTools)` report. */
    public static function withLaunchReport(string $text, string $sample, string $file = 'page'): string
    {
        // The prefix is read off the launcher's own format, not retyped here.
        $prefix = preg_quote((string) strstr(Bootstrap::STDERR_LINE_FORMAT, '%s', true), '/');
        $pattern = '/^```[^\n]*\n(' . $prefix . '[^\n]*\(disabledTools\).*?)\n```/ms';
        if (preg_match_all($pattern, $text, $m, \PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException("{$file} must hold exactly one fenced (disabledTools) launch-report sample");
        }

        [$body, $offset] = $m[1][0];

        return substr($text, 0, $offset) . $sample . substr($text, $offset + \strlen($body));
    }

    /**
     * @param list<CatalogEntry> $entries
     *
     * @return list<CatalogEntry> sorted by class short name
     */
    private static function byClassName(array $entries): array
    {
        usort($entries, static fn (CatalogEntry $a, CatalogEntry $b): int => strcmp($a->fileName(), $b->fileName()));

        return $entries;
    }

    /**
     * @param list<string> $names
     */
    private static function codeList(array $names, string $glue, ?string $lastGlue = null): string
    {
        return self::joined(array_map(static fn (string $n): string => '`' . $n . '`', $names), $glue, $lastGlue);
    }

    /**
     * @param list<string> $items
     */
    private static function joined(array $items, string $glue, ?string $lastGlue = null): string
    {
        if ($lastGlue === null || \count($items) < 2) {
            return implode($glue, $items);
        }

        $last = array_pop($items);

        return implode($glue, $items) . $lastGlue . $last;
    }
}
