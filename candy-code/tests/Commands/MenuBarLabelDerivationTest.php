<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Core\Util\Width;

/**
 * ISS-104's F10-menu half: a row with no paletteLabel used to fall back to the
 * bare lowercase command name, so the Session menu read `New session`,
 * `Switch session`, `compact`, `clear` — two label styles in one column.
 *
 * The fallback is now derived from the row's own short description
 * ({@see CommandRegistry::derivedMenuLabel()}), so no label is hand-authored a
 * second time: the first clause, Title-cased like the palette rows it sits
 * beside, fitted word-wise into the menu budget. The table below IS the
 * derivation — re-derive with `php -r` over {@see CommandRegistry::all()}
 * rather than editing a row here to make a failure pass.
 */
final class MenuBarLabelDerivationTest extends TestCase
{
    /**
     * Every built-in that carries no hand-authored paletteLabel — the
     * slash-only rows AND, since VIS-4, the palette rows promoted at their
     * derived text — and the menu text its description derives to.
     */
    private const DERIVED_LABELS = [
        'effort' => 'Read or set',
        'keys' => 'Show the keyboard',
        'help' => 'List every slash',
        'permissions' => 'Show permission mode',
        'notices' => 'Show every warning',
        'status' => 'Show where',
        'context' => 'Show what fills',
        'sweep' => 'Prune the tool outputs',
        'pruning' => 'Show or set',
        'compress' => 'Ask the model',
        'decompress' => 'Send a compressed',
        'recompress' => 'Restore the summary',
        // The `/hooks` row (roadmap VIS-3a) landed without its table entry —
        // red at the VIS-4 base, cured in-step here.
        'hooks' => 'List the hook chain',
        'rules' => 'List the rule packs',
        'newrule' => 'Have the agent draft',
        'pane' => 'Dock a pane to a side',
        'editor' => 'Compose the prompt',
        'compact' => 'Manually compact chat',
        'clear' => 'Clear the transcript',
        'budget' => 'Show reported spend',
        'usage' => 'Show the durable spend',
        'workflow' => 'Run, pause, resume',
        'memory' => 'Add, list, search',
        'skills' => 'List the invocable',
        'init' => 'Study this project',
        'rewind' => 'Restore an earlier',
        'undo' => 'Take back the last',
        'redo' => 'Step forward again',
        'diff' => 'Show what changed',
        'review' => 'Review the diff',
        'security-review' => 'Security-review',
        'bg' => 'Run a task',
        'shells' => 'List, read output',
        'schedules' => 'List, add, enable',
        'loop' => 'Re-run a prompt',
        'fork' => 'Clone',
        'websearch' => 'Search the web',
        'goal' => 'Work until a condition',
        'grind' => 'Like /goal',
        'btw' => 'Ask the title model',
        'handoff' => 'Continue in a new',
        'generate' => 'Generate an image',
        'sendto' => "Import a saved PNG's",
        'play' => 'Play a video artifact',
        'rendermode' => 'Show or switch',
    ];

    public function testEverySlashOnlyRowReadsAsThisTable(): void
    {
        $derived = [];
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->paletteLabel === null && !$spec->isFileBased()) {
                $derived[$spec->name] = $spec->label();
            }
        }

        self::assertSame(self::DERIVED_LABELS, $derived, 'the derivation moved, or a row changed its description');
    }

    public function testNoTwoRowsShareOneLabel(): void
    {
        // \SugarCraft\Code\App\App::dispatchMenuSelection() resolves a picked
        // row by label and takes the FIRST spec that answers, so two rows
        // colliding on one label would silently run the wrong command.
        $seen = [];
        foreach (CommandRegistry::all() as $spec) {
            $label = $spec->label();
            self::assertArrayNotHasKey($label, $seen, sprintf(
                '`/%s` and `/%s` both read "%s" in the menu',
                $spec->name,
                $seen[$label] ?? '?',
                $label,
            ));
            $seen[$label] = $spec->name;
        }

        self::assertCount(\count(CommandRegistry::all()), $seen, 'the registry lost a row since this was written');
    }

    public function testEveryLabelFitsTheMenuBudget(): void
    {
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->paletteLabel !== null || $spec->isFileBased()) {
                continue;
            }
            self::assertLessThanOrEqual(
                CommandRegistry::MAX_MENU_LABEL_CELLS,
                Width::of($spec->label()),
                sprintf('`/%s` overflows the menu column: %s', $spec->name, $spec->label()),
            );
        }
    }

    public function testTheHedgeThatOnceCollidedTwoRowsIsGone(): void
    {
        // `/permissions` ("Show this session's permission mode, …") and
        // `/budget` ("Show this session's reported spend, …") both truncated to
        // "Show this session's" at the fit budget; loading one of the two
        // descriptions alone is enough to show the hedge never survives the fit.
        $permissions = $this->row('permissions', "Show this session's permission mode, its rules and its grants; switch the mode or revoke a grant");
        $budget = $this->row('budget', "Show this session's reported spend, or cap it");

        self::assertSame('Show permission mode', $permissions->label());
        self::assertSame('Show reported spend', $budget->label());
    }

    public function testARowWithItsOwnPaletteLabelIsNeverRewritten(): void
    {
        $spec = CommandSpec::new('theme', 'Switch between the built-in themes', 'View', paletteLabel: 'Switch theme');

        self::assertSame('Switch theme', $spec->label(), 'an authored label wins outright');
    }

    public function testAFileBasedRowKeepsTheNameFallback(): void
    {
        // A file command's description is the first line of the user's own body
        // text; deriving a Title-case menu row out of it would rewrite the
        // user's prose, and the F10 menus list registry rows only anyway.
        $spec = CommandSpec::new('review', 'read the diff and complain', 'Custom', template: 'do the thing');

        self::assertTrue($spec->isFileBased());
        self::assertSame('review', $spec->label());
    }

    public function testTheDerivationTakesOnlyTheFirstClause(): void
    {
        $spec = $this->row('two', 'Say hello. Then say goodbye, at length, with a great many words indeed');

        self::assertSame('Say hello', $spec->label());
    }

    public function testAWordInternalPeriodDoesNotEndTheClause(): void
    {
        $spec = $this->row('png', "Import a saved PNG's parameters back into the generate form, please");

        self::assertSame("Import a saved PNG's", $spec->label(), 'the split needs a space after the punctuation');
    }

    public function testATrailingParentheticalIsADropCandidate(): void
    {
        $spec = $this->row('keys', 'Show the keyboard shortcut reference (or press ?)');

        self::assertSame('Show the keyboard', $spec->label());
        self::assertStringNotContainsString('(or', $spec->label(), 'the hint is not part of the row name');
    }

    public function testAFittedRowDoesNotEndOnAStopwordOrASeparator(): void
    {
        $spec = $this->row('long', 'Clone this conversation into a background session that starts from the current state');

        self::assertSame('Clone', $spec->label(), 'every trailing article, preposition and copula pops');

        $commas = $this->row('commas', 'Add, list, search, edit, import, clear, or restore memory entries, and show their history too');

        self::assertSame('Add, list, search', $commas->label());
        self::assertNotSame(',', substr($commas->label(), -1), 'the fit trims a dangling separator off the tail');
    }

    public function testAClauseThatAlreadyFitsIsKeptWhole(): void
    {
        $short = 'Clear the transcript';
        self::assertLessThanOrEqual(CommandRegistry::MAX_MENU_LABEL_CELLS, Width::of($short));

        self::assertSame($short, $this->row('clear', $short)->label());
    }

    private function row(string $name, string $description): CommandSpec
    {
        return CommandSpec::new($name, $description, 'Session');
    }
}
