<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\HelpHostCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tui\Components\PaneLabel;
use SugarCraft\Core\Util\Width;

/**
 * `/help` over the wire (C-WEB-2.e): the command listing a headless client
 * can act on.
 *
 * Every expectation here DERIVES from `CommandRegistry` / `BuiltInCommands`
 * rather than carrying a literal count — a pinned command count is exactly
 * the number that goes stale the next time a row lands, and the design says
 * no roster counts. What is pinned is the shape: one heading per category
 * (the property `Commands\SlashDispatchTest` holds for the TUI walk), file
 * commands listed with their category, body-less rows marked terminal-only,
 * and nothing clipped.
 */
final class HelpHostCommandTest extends TestCase
{
    private string $sandbox;

    private string|false $originalHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/cweb2e_help_' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/home', 0700, true);
        mkdir($this->sandbox . '/project', 0700, true);
        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->originalHome === false ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
        $this->removeTree($this->sandbox);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return list<string> */
    private function lines(CommandContext $context): array
    {
        return explode("\n", (new HelpHostCommand())->run($context, '/help')->rows[1]->content);
    }

    /** The registry row of $name, or null when no visible slash row carries it. */
    private function row(string $name): ?CommandSpec
    {
        foreach (CommandRegistry::slashCommands() as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }
        }

        return null;
    }

    public function testTheHelpSpellingCarriesTheHostBody(): void
    {
        $command = BuiltInCommands::forSpelling('help');

        $this->assertNotNull($command);
        $this->assertSame(HelpHostCommand::class, $command->hostCommand);
        $this->assertInstanceOf(HelpHostCommand::class, $command->instantiateHostCommand());
    }

    public function testTheDraftIsEchoedAndAnsweredLocallyWithOneAssistantRow(): void
    {
        $rows = (new HelpHostCommand())->run(CommandContext::new(), '/help')->rows;

        $this->assertCount(2, $rows);
        $this->assertSame(Role::User, $rows[0]->role);
        $this->assertSame('/help', $rows[0]->content);
        $this->assertSame(Role::Assistant, $rows[1]->role);
        $this->assertTrue($rows[1]->uiOnly, 'a command listing is UI text, never model context');
    }

    public function testOneHeadingPerCategoryEvenThoughTheRegistryInterleavesItsRows(): void
    {
        $categories = [];
        foreach (CommandRegistry::slashCommands() as $spec) {
            $categories[$spec->category] = true;
        }

        $lines = $this->lines(CommandContext::new());

        $headings = array_values(array_filter(
            $lines,
            static fn(string $line): bool => isset($categories[$line]),
        ));

        $this->assertSame(
            array_keys($categories),
            $headings,
            'one heading per category, in first-appearance order, none printed twice',
        );
    }

    public function testBodylessRowsAreMarkedTerminalOnly(): void
    {
        $marker = Lang::t('host.help.terminal_only');
        $lines = $this->lines(CommandContext::new());
        $body = implode("\n", $lines);

        $terminalCount = 0;
        foreach (CommandRegistry::slashCommands() as $spec) {
            if (!$spec->isFileBased() && BuiltInCommands::forSpelling($spec->name)?->hostCommand === null) {
                $terminalCount++;
                $this->assertStringContainsString(
                    $marker,
                    $this->rowWindow($lines, $spec),
                    '/' . $spec->name . ' has no host body and must read terminal-only',
                );
            }
        }

        $this->assertGreaterThan(0, $terminalCount, 'the mark polices a real set: /theme, /keys, the pickers');
        $this->assertSame($terminalCount, substr_count($body, $marker), 'and nothing else carries it');
    }

    public function testRowsWithAHostBodyAreNotMarked(): void
    {
        $marker = Lang::t('host.help.terminal_only');
        $lines = $this->lines(CommandContext::new());

        foreach (CommandRegistry::slashCommands() as $spec) {
            if (!$spec->isFileBased() && BuiltInCommands::forSpelling($spec->name)?->hostCommand !== null) {
                $this->assertStringNotContainsString(
                    $marker,
                    $this->rowWindow($lines, $spec),
                    '/' . $spec->name . ' answers over the wire and must not read terminal-only',
                );
            }
        }
    }

    /**
     * The printed window of one row: its own line, plus the indented spill
     * line the widest `  /name <hint>` columns push the description onto.
     *
     * @param list<string> $lines
     */
    private function rowWindow(array $lines, CommandSpec $spec): string
    {
        $needle = '  /' . PaneLabel::of($spec->name);
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, $needle . ' ') || rtrim($line) === trim($needle)) {
                $next = $lines[$index + 1] ?? '';

                return $next !== '' && str_starts_with($next, str_repeat(' ', HelpHostCommand::NAME_COLUMN))
                    ? $line . "\n" . $next
                    : $line;
            }
        }

        $this->fail('the listing carries no row for /' . $spec->name);
    }

    public function testWorkspaceFileCommandsAreListedUnderTheirCustomCategory(): void
    {
        $commandsDir = $this->sandbox . '/project/.candy-code/commands';
        mkdir($commandsDir, 0700, true);
        file_put_contents(
            $commandsDir . '/notes.md',
            "---\ndescription: Fold the session notes into the next prompt\n---\nSummarise the notes file.\n",
        );

        $context = CommandContext::new(
            root: $this->sandbox . '/project',
            workspace: WorkspaceContext::new(
                root: $this->sandbox . '/project',
                commandLoader: new CommandLoader(),
                projectCommandsTrusted: true,
            ),
        );

        $body = implode("\n", $this->lines($context));

        $this->assertStringContainsString('  /notes', $body, 'a project command is discoverable over /help');
        $this->assertStringContainsString('Fold the session notes into the next prompt', $body);
        $this->assertStringContainsString("\n" . Lang::t('cmd.category.custom') . "\n", $body, 'the file row groups under Custom');
        $this->assertStringNotContainsString('/notes' . Lang::t('host.help.terminal_only'), $body, 'a file command runs on the server: not terminal-only');
        $this->assertStringStartsWith(
            Lang::t('host.help.heading', ['count' => count(CommandRegistry::slashCommands()) + 1]),
            $body,
            'the count in the heading covers the file row too',
        );
    }

    public function testTheListingIsNotClipped(): void
    {
        $longest = '';
        foreach (CommandRegistry::slashCommands() as $spec) {
            if (\strlen($spec->description) > \strlen($longest)) {
                $longest = $spec->description;
            }
        }

        $body = implode("\n", $this->lines(CommandContext::new()));

        $this->assertNotSame('', $longest);
        $this->assertStringContainsString($longest, $body, 'the widest description rides the wire whole — a client clips in its own width');
    }

    public function testAHintTooWideForTheNameColumnSpillsItsDescriptionToItsOwnRow(): void
    {
        $spec = $this->row('websearch');
        $this->assertNotNull($spec, 'the registry keeps the row this pin is about');

        $left = '  /' . PaneLabel::of($spec->name) . ($spec->argumentHint !== null ? ' ' . PaneLabel::of($spec->argumentHint) : '');
        $this->assertGreaterThan(
            HelpHostCommand::NAME_COLUMN - 2,
            Width::string($left),
            'websearch is the spill fixture: its name column is wider than the constant',
        );

        $lines = $this->lines(CommandContext::new());
        $index = array_search($left, $lines, true);
        $this->assertIsInt($index, 'the row prints alone, description pushed off');
        $this->assertStringStartsWith(str_repeat(' ', HelpHostCommand::NAME_COLUMN), $lines[$index + 1]);
        $this->assertStringContainsString(PaneLabel::of($spec->description), $lines[$index + 1]);
    }
}
