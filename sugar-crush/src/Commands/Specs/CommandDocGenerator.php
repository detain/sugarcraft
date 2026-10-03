<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands\Specs;

use SugarCraft\Crush\Commands\CommandRegistry;

/**
 * The command documentation that is DERIVED from the spec files, rendered.
 *
 * Two marked blocks, each replaced whole between its `<!-- commands:<name>:begin -->`
 * and `<!-- commands:<name>:end -->` comments:
 *
 *  - `README.md` `roster`: the "### Slash commands" roster — every row the "/"
 *    popup advertises, alphabetically, each followed by its aliases in
 *    parentheses ({@see BuiltInCommand::$aliases}).
 *  - `docs/COMMANDS.md` `table`: the built-in table — every row in
 *    {@see CommandRegistry::all()} order with its S (slash-visible) and CP
 *    ({@see CommandRegistry::CONTROL_PLANE}) ticks, its argument hint and its
 *    description.
 *
 * Pure: the caller (`tools/gen-command-docs.php`, the drift test) owns the file
 * I/O. Everything outside the markers is the pages' own prose.
 */
final class CommandDocGenerator
{
    public const README = 'README.md';
    public const COMMANDS_DOC = 'docs/COMMANDS.md';

    /** The roster wraps at this many columns, like the prose around it. */
    private const ROSTER_WIDTH = 80;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /** @return list<string> package-relative pages this generator writes */
    public function targets(): array
    {
        return [self::README, self::COMMANDS_DOC];
    }

    /** @return array<string, array<string, string>> page => block name => content */
    public function blocks(): array
    {
        return [
            self::README => ['roster' => $this->readmeRoster()],
            self::COMMANDS_DOC => ['table' => $this->commandsTable()],
        ];
    }

    /** README's slash roster: advertised rows A–Z, aliases parenthesised after their row. */
    public function readmeRoster(): string
    {
        $tokens = [];
        $commands = array_values(array_filter(
            BuiltInCommands::all(),
            static fn (BuiltInCommand $c): bool => $c->spec->slashVisible,
        ));
        usort($commands, static fn (BuiltInCommand $a, BuiltInCommand $b): int => strcmp($a->name(), $b->name()));

        foreach ($commands as $command) {
            $token = '`/' . $command->name() . '`';
            foreach ($command->aliases as $alias) {
                $token .= ' (`/' . $alias . '`)';
            }

            $tokens[] = $token;
        }

        $tokens[\count($tokens) - 1] .= '.';

        $lines = [];
        $line = '';
        foreach ($tokens as $token) {
            if ($line !== '' && mb_strlen($line . ' ' . $token) > self::ROSTER_WIDTH) {
                $lines[] = $line;
                $line = '';
            }

            $line = $line === '' ? $token : $line . ' ' . $token;
        }

        $lines[] = $line;

        return implode("\n", $lines);
    }

    /** COMMANDS.md's built-in table, in registry order. */
    public function commandsTable(): string
    {
        $lines = [
            '| Command | S | CP | Takes | What the row says |',
            '|---|---|---|---|---|',
        ];
        foreach (CommandRegistry::all() as $spec) {
            $cells = [
                '`/' . $spec->name . '`',
                $spec->slashVisible ? '✓' : '',
                CommandRegistry::isControlPlane($spec->name) ? '✓' : '',
                $spec->argumentHint === null ? '—' : '`' . self::cell($spec->argumentHint) . '`',
                self::cell($spec->description),
            ];
            // An empty marker cell is `| |`, not `|  |`: the page's hand-written
            // tables spell it that way and the generated one reads the same.
            $lines[] = implode('', array_map(
                static fn (string $cell): string => $cell === '' ? '| ' : '| ' . $cell . ' ',
                $cells,
            )) . '|';
        }

        return implode("\n", $lines);
    }

    /**
     * Each target page as it should read, given the pages as they read now.
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

    /**
     * `$text` with block `$name` replaced by `$content`. A missing or doubled
     * marker pair is an error, never a silent append: a generator that guessed
     * where a lost block belonged would put a roster in the wrong section.
     */
    public static function replaceBlock(string $text, string $name, string $content, string $file = 'page'): string
    {
        $begin = '<!-- commands:' . $name . ':begin -->';
        $end = '<!-- commands:' . $name . ':end -->';
        if (substr_count($text, $begin) !== 1 || substr_count($text, $end) !== 1) {
            throw new \RuntimeException("{$file} must carry exactly one {$begin} … {$end} pair");
        }

        $start = (int) strpos($text, $begin) + \strlen($begin);
        $stop = (int) strpos($text, $end);
        if ($stop < $start) {
            throw new \RuntimeException("{$file}: {$end} comes before {$begin}");
        }

        return substr($text, 0, $start) . "\n" . $content . "\n" . substr($text, $stop);
    }

    /** A markdown table cell: a literal pipe must not end the cell. */
    private static function cell(string $text): string
    {
        return str_replace('|', '\\|', $text);
    }
}
