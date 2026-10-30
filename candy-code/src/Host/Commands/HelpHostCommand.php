<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tui\Components\PaneLabel;
use SugarCraft\Core\Util\Width;

/**
 * `/help` — the command listing over the wire (C-WEB-2.e).
 *
 * The same rows the TUI's `Chat::handleHelpCommand()` prints — the registry's
 * slash rows plus the project's file commands, grouped under one heading per
 * category — minus everything that only makes sense on a screen: there is no
 * column budget to clip to (a client renders the text in its own width), and
 * no keyboard trailer.
 *
 * The grouping is the pinned property, not decoration: the registry
 * INTERLEAVES its categories, so a "heading whenever the category changes"
 * walk would print some headings twice — the same defect
 * `Commands\SlashDispatchTest::testTheHelpListingPrintsOneHeadingPerCategoryNotOnePerRun()`
 * holds the TUI side of. Category order is first-appearance order there too.
 *
 * A row the server cannot answer gets its description suffixed "(terminal
 * only)": the listing says what exists, and `command.run` on one of these
 * answers {@see CommandResult::clientOnly()}, so the mark tells a web client
 * which half of the list it can actually use. A file command is never marked:
 * its body runs on the server through the command-exec path whatever host
 * surface it was discovered from.
 *
 * The file rows are loaded the way the retired `CommandMethods::files()`
 * loaded them — the workspace's own {@see \SugarCraft\Code\Commands\CommandLoader}
 * against the session's project root and trust flag — because a
 * {@see HostCommand} sees its session only through {@see CommandContext} and
 * never through the host that holds the merged map.
 */
final class HelpHostCommand implements HostCommand
{
    /** The name-column width of the TUI's listing (`Chat::HELP_NAME_COLS`), shared so both surfaces pad alike. */
    public const NAME_COLUMN = 24;

    public function run(CommandContext $context, string $text): CommandResult
    {
        $commands = $this->listing($context);

        $lines = [Lang::t('host.help.heading', ['count' => count($commands)])];

        /** @var array<string, list<CommandSpec>> $byCategory */
        $byCategory = [];
        foreach ($commands as $spec) {
            $byCategory[$spec->category][] = $spec;
        }

        foreach ($byCategory as $category => $rows) {
            $lines[] = '';
            $lines[] = $category;
            foreach ($rows as $spec) {
                $lines[] = $this->row($spec);
            }
        }

        return CommandResult::reply($text, implode("\n", $lines));
    }

    /**
     * The rows in listing order: the registry's visible slash rows, with each
     * file command replacing the built-in row of its name (ISS-036's override
     * direction, same as the TUI's merge) or appending after it.
     *
     * @return list<CommandSpec>
     */
    private function listing(CommandContext $context): array
    {
        $rows = CommandRegistry::slashCommands();

        $loader = $context->workspace?->commandLoader;
        if ($loader === null) {
            return $rows;
        }

        $trusted = $context->workspace?->projectCommandsTrusted ?? false;
        $byName = [];
        foreach ($rows as $index => $spec) {
            $byName[$spec->name] = $index;
        }

        foreach ($loader->loadAll($context->projectRoot(), $trusted) as $fileSpec) {
            if (!$fileSpec->isFileBased() || !$fileSpec->slashVisible) {
                continue;
            }
            if (isset($byName[$fileSpec->name])) {
                $rows[$byName[$fileSpec->name]] = $fileSpec;
                continue;
            }
            $byName[$fileSpec->name] = count($rows);
            $rows[] = $fileSpec;
        }

        return $rows;
    }

    /** One padded row, measured on the bytes that will print, never clipped. */
    private function row(CommandSpec $spec): string
    {
        $name = PaneLabel::of($spec->name);
        $hint = $spec->argumentHint !== null ? PaneLabel::of($spec->argumentHint) : '';
        $left = '  /' . $name . ($hint !== '' ? ' ' . $hint : '');

        $description = PaneLabel::of($spec->description);
        if (!$spec->isFileBased() && BuiltInCommands::forSpelling($spec->name)?->hostCommand === null) {
            $description .= Lang::t('host.help.terminal_only');
        }

        $leftWidth = Width::string($left);
        if ($leftWidth <= self::NAME_COLUMN - 2) {
            return $left . str_repeat(' ', self::NAME_COLUMN - $leftWidth) . $description;
        }

        // A hint too wide for the name column spills onto its own row rather
        // than shoving the description off the constant-width column — the
        // TUI's `--help` shape, kept identical so the two listings scan alike.
        return $left . "\n" . str_repeat(' ', self::NAME_COLUMN) . $description;
    }
}
