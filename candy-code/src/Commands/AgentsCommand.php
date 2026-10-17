<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Lang;

/**
 * Implements the /agents and /agent commands for listing and inspecting agents.
 *
 * Usage:
 *   /agents        — render the roster (working agents first) as a table
 *   /agent <name>  — show details for a specific agent
 *
 * @mirrors charmbracelet/crush AgentsCommand
 */
final class AgentsCommand
{
    /**
     * The `/agents` roster table: header text => cell budget, in cells.
     *
     * Budgets rather than the old `str_repeat(" ", max(1, 20 - $nameLen))`
     * and `40 - strlen($agent->name)`, which had two separate defects. They
     * could not clip at all, so a preset with a 200-character `name:` emitted
     * a 200-column row into a transcript that wraps at the pane width; and
     * the description budget SHRANK as the name grew, so the same description
     * rendered at a different length depending on which agent it belonged to,
     * cut by `substr()` at a BYTE offset that could land inside a UTF-8
     * sequence and emit a broken rune.
     *
     * They sum to 72 cells; with {@see TranscriptTable::maxCells()}'s border
     * and padding overhead the dressed box wants 88. `Status` is 10 because
     * `○ inactive` is the longer of the two labels this command emits, and
     * `Tier` is 10 because `opencode` is the longest source a native-plus-
     * foreign roster names in everyday use (`agent-skills-spec` clips, and
     * clips honestly).
     *
     * THESE ARE A STARTING POINT, NOT A CEILING, and the number they are NOT
     * is worth writing down because two drafts of this doc-block got it wrong.
     * The transcript pane is `max(20, cols() - 6)`
     * ({@see TranscriptTable::CHROME_COLS}), so an 80-column terminal gives
     * this box **74** cells, not 80 — an 88-cell table does not fit an
     * 80-column terminal, and a row wider than the pane is hard-wrapped by
     * the Markdown pass, which shreds the box.
     *
     * {@see listAgents()} therefore runs these through
     * {@see TranscriptTable::fit()} against the live pane width taken off the
     * `Chat` `execute()` is handed: a narrow pane shrinks `Agent` and
     * `Description` rather than the box being shredded. An earlier revision
     * said the pane width was unknowable here; it is not —
     * {@see \SugarCraft\Code\Chat::cols()} has always exposed it, and
     * {@see \SugarCraft\Code\Chat::handleHelpCommand()} has always used it.
     */
    private const COLUMNS = [
        'Agent' => 16,
        'Description' => 24,
        'Model' => 12,
        'Tier' => 10,
        'Status' => 10,
    ];

    /**
     * The one column {@see TranscriptTable::fit()} may not shrink.
     *
     * `Status` holds one of two literals this class writes itself, and
     * `○ inactiv…` is a wrong status rather than a short one. The other four
     * carry on-disk preset text, where `…` reads as the abbreviation it is.
     */
    private const COLUMN_FLOORS = ['Status' => 10];

    public function __construct(
        private readonly AgentManager $agentManager,
    ) {
    }

    /**
     * Execute the /agents or /agent command.
     *
     * @param Chat|CommandContext $chat The session the command runs in (TUI or headless), for the pane width
     * @param array $args  Parsed command arguments (from CommandParser)
     * @return int         Exit code: 0 on success, non-zero on failure
     */
    public function execute(Chat|CommandContext $chat, array $args = []): int
    {
        // /agents with no args lists all active agents
        if ($args === []) {
            return $this->listAgents(TranscriptTable::paneWidth($chat));
        }

        // /agent <name> shows details for a specific agent
        $agentName = $args[0];

        return $this->showAgent($agentName);
    }

    /**
     * Render the full roster as a table: working agents first, then the rest
     * by name, with an explicit "nothing is working" line when only idle
     * agents exist.
     *
     * "Active" on {@see AgentManager::active()} means *currently doing work*,
     * not *configured*. Before ISS-034 the idle state answered with a bare
     * count — a roster the user could neither see nor discover — and the
     * paired `/agent <unknown>` hint sent them back to this count, closing the
     * loop. The table IS the roster now, so every agent in the session is
     * named on the surface that advertises them, alongside the model it runs
     * and the convention tier (native/claude/opencode/…) it arrived from.
     */
    private function listAgents(int $paneWidth): int
    {
        $roster = $this->agentManager->all();

        if ($roster === []) {
            // The genuinely-empty case: there is no roster to render. The hint
            // names the real directories (ISS-034) — "the agents configuration
            // file" described a file no reader could find, and the directory
            // spellings here are the ones Bootstrap::agentPresetTiers() walks.
            echo "\n  " . Lang::t('cmd.agents.none-configured') . "\n";
            echo '  ' . Lang::t('cmd.agents.none-configured-hint') . "\n\n";

            return 0;
        }

        $working = $this->agentManager->active();
        $workingNames = [];
        foreach ($working as $agent) {
            $workingNames[$agent->name] = true;
        }

        $idle = [];
        foreach ($roster as $agent) {
            if (!isset($workingNames[$agent->name])) {
                $idle[] = $agent;
            }
        }
        usort($idle, static fn(Agent $a, Agent $b): int => strcmp($a->name, $b->name));

        // COLUMNS is the budget at a comfortable width; fit() is what makes it
        // true at the width this pane actually has. Both the header row and
        // every cell are sized from the SAME returned array, so the derived cap
        // still cannot be reached from below.
        $labels = self::headers();
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::COLUMNS, $labels),
            $paneWidth,
            TranscriptTable::relabel(self::COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);

        foreach ([...$working, ...$idle] as $agent) {
            $table = $table->row(
                TranscriptTable::cell($agent->name, $columns[$labels['Agent']]),
                TranscriptTable::cell($agent->description, $columns[$labels['Description']]),
                TranscriptTable::cell(
                    $agent->inheritsModel || trim($agent->model) === ''
                        ? Lang::t('cmd.agents.cell.session')
                        : $agent->model,
                    $columns[$labels['Model']],
                ),
                TranscriptTable::cell($agent->source->value, $columns[$labels['Tier']]),
                // Through cell() like its neighbours even though this class
                // writes the string itself: what keeps the table's natural
                // width under TranscriptTable's derived cap — and so keeps the
                // cap's proportional shrink from ever firing — is that EVERY
                // cell respects its budget, not most of them.
                TranscriptTable::cell($agent->isActive ? Lang::t('cmd.agents.cell.active') : Lang::t('cmd.agents.cell.inactive'), $columns[$labels['Status']]),
            );
        }

        echo "\n  " . Lang::t('cmd.agents.heading') . "\n";
        if ($working === []) {
            echo '  ' . Lang::t('cmd.agents.none-working') . "\n";
        }
        echo "\n" . $table->render() . "\n\n";

        return 0;
    }

    /**
     * Show detailed information for a specific agent.
     */
    private function showAgent(string $name): int
    {
        $agent = $this->agentManager->get($name);

        if ($agent === null) {
            echo "\n  " . Lang::t('cmd.agents.unknown', ['name' => $name]) . "\n";
            echo '  ' . Lang::t('cmd.agents.unknown-hint') . "\n\n";

            return 1;
        }

        echo "\n  " . Lang::t('cmd.agents.detail.agent', ['name' => $agent->name]) . "\n";
        echo "  " . str_repeat("─", 50) . "\n";
        echo '  ' . Lang::t('cmd.agents.detail.description', ['description' => $agent->description]) . "\n";
        echo '  ' . Lang::t('cmd.agents.detail.model', ['model' => $agent->model]) . "\n";
        echo '  ' . Lang::t('cmd.agents.detail.provider', ['provider' => $agent->provider]) . "\n";
        // ISS-031: which convention wrote this agent, and the exact file it was
        // read from (or null for the six built-ins, which have no file). A
        // roster the operator cannot trace to a path is the silence this item
        // exists to end.
        echo '  ' . Lang::t('cmd.agents.detail.source', ['source' => $agent->source->value]) . "\n";
        if ($agent->sourcePath !== null && $agent->sourcePath !== '') {
            echo '  ' . Lang::t('cmd.agents.detail.path', ['path' => $agent->sourcePath]) . "\n";
        }
        echo '  ' . Lang::t('cmd.agents.detail.status', [
            'status' => $agent->isActive ? Lang::t('cmd.agents.status.active') : Lang::t('cmd.agents.status.inactive'),
        ]) . "\n";

        if ($agent->skillNames !== []) {
            echo '  ' . Lang::t('cmd.agents.detail.skills', ['skills' => implode(", ", $agent->skillNames)]) . "\n";
        }

        if ($agent->tools !== []) {
            echo '  ' . Lang::t('cmd.agents.detail.tools', ['tools' => implode(", ", $agent->tools)]) . "\n";
        }

        if ($agent->hooks !== []) {
            echo '  ' . Lang::t('cmd.agents.detail.hooks', ['hooks' => implode(", ", $agent->hooks)]) . "\n";
        }

        echo "\n";

        return 0;
    }

    /**
     * Each {@see COLUMNS} id's header in the active locale.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Agent' => Lang::t('cmd.agents.column.agent'),
            'Description' => Lang::t('cmd.agents.column.description'),
            'Model' => Lang::t('cmd.agents.column.model'),
            'Status' => Lang::t('cmd.agents.column.status'),
            'Tier' => Lang::t('cmd.agents.column.tier'),
        ];
    }
}
