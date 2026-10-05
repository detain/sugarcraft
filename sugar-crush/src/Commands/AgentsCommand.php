<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Lang;

/**
 * Implements the /agents and /agent commands for listing and inspecting agents.
 *
 * Usage:
 *   /agents        — list the agents currently working, or the idle roster count
 *   /agent <name>  — show details for a specific agent
 *
 * @mirrors charmbracelet/crush AgentsCommand
 */
final class AgentsCommand
{
    /**
     * The `/agents` list table: header text => cell budget, in cells.
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
     * They sum — with {@see TranscriptTable::maxCells()}'s border overhead —
     * to 76 cells. `Status` is 10 because `○ inactive` is the longer of the
     * two labels this command emits.
     *
     * 76 IS A STARTING POINT, NOT A CEILING, and the number it is NOT is
     * worth writing down because two drafts of this doc-block got it wrong.
     * The transcript pane is `max(20, cols() - 6)`
     * ({@see TranscriptTable::CHROME_COLS}), so an 80-column terminal gives
     * this box **74** cells, not 76 or 80 — a 76-cell table does not fit an
     * 80-column terminal, and a row wider than the pane is hard-wrapped by the
     * Markdown pass, which shreds the box.
     *
     * {@see listAgents()} therefore runs these through
     * {@see TranscriptTable::fit()} against the live pane width taken off the
     * `Chat` `execute()` is handed: at 80 columns `Agent` and `Description`
     * each give up one cell, and at 60 they give up considerably more, rather
     * than the box being shredded at either. An earlier revision said the pane
     * width was unknowable here; it is not —
     * {@see \SugarCraft\Crush\Chat::cols()} has always exposed it, and
     * {@see \SugarCraft\Crush\Chat::handleHelpCommand()} has always used it.
     */
    private const COLUMNS = [
        'Agent' => 20,
        'Description' => 36,
        'Status' => 10,
    ];

    /**
     * The one column {@see TranscriptTable::fit()} may not shrink.
     *
     * `Status` holds one of two literals this class writes itself, and
     * `○ inactiv…` is a wrong status rather than a short one. `Agent` and
     * `Description` carry on-disk preset text, where `…` reads as the
     * abbreviation it is, so they absorb the whole loss on a narrow pane.
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
     * List the agents that are currently WORKING, falling back to a count of
     * the registered-but-idle roster when none of them is.
     *
     * "Active" on {@see AgentManager::active()} means *currently doing work*,
     * not *configured*, so an empty result is the normal state of a healthy
     * launch rather than an empty manager — which is why the two no-work
     * branches below have to say different things.
     */
    private function listAgents(int $paneWidth): int
    {
        $agents = $this->agentManager->active();

        if ($agents === []) {
            // "Active" means *currently working* (see AgentManager::active()),
            // so on a real launch this is the normal idle state, not an empty
            // manager -- Bootstrap::agentRoster() registers six built-in agents
            // plus any on-disk preset. The two branches are worded apart on
            // purpose: pairing "No active agents configured." with "9 agent(s)
            // registered and idle" read as a self-contradiction, when what is
            // true is that nine agents exist and none is busy. The names are
            // reachable through `/agent <name>`, which does not filter on
            // active.
            $idle = count($this->agentManager->all());
            if ($idle > 0) {
                echo "\n  " . Lang::t('cmd.agents.none-working') . "\n";
                echo '  ' . Lang::t('cmd.agents.idle', ['count' => $idle]) . "\n\n";

                return 0;
            }

            echo "\n  " . Lang::t('cmd.agents.none-configured') . "\n";
            echo '  ' . Lang::t('cmd.agents.none-configured-hint') . "\n\n";

            return 0;
        }

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

        foreach ($agents as $agent) {
            $table = $table->row(
                TranscriptTable::cell($agent->name, $columns[$labels['Agent']]),
                TranscriptTable::cell($agent->description, $columns[$labels['Description']]),
                // Through cell() like its neighbours even though this class
                // writes the string itself: what keeps the table's natural
                // width under TranscriptTable's derived cap — and so keeps the
                // cap's proportional shrink from ever firing — is that EVERY
                // cell respects its budget, not most of them.
                TranscriptTable::cell($agent->isActive ? Lang::t('cmd.agents.cell.active') : Lang::t('cmd.agents.cell.inactive'), $columns[$labels['Status']]),
            );
        }

        echo "\n  " . Lang::t('cmd.agents.heading') . "\n\n";
        echo $table->render() . "\n\n";

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
            'Status' => Lang::t('cmd.agents.column.status'),
        ];
    }
}
