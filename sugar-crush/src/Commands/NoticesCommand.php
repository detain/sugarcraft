<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;

/**
 * Implements the `/notices` command — every warning this launch raised, whole.
 *
 * Usage:
 *   /notices  — the full, un-capped record of the launch's warnings
 *
 * E653 Shape A taught the launch to route its narrowed-grant sentences to
 * stderr WHOLE and to the transcript only as an aggregate of at most two
 * packed rows. That fixed the flood, and left the transcript unable to answer
 * "show me all of them" — which is what this surface is for: the full
 * collector output and the full notice shelf, one line per fact, nothing
 * clipped or pair-packed.
 *
 * WHY IT READS THE STORES RATHER THAN KEEPING ITS OWN. Both sources are
 * pull-only and already public by design:
 * {@see Bootstrap::launchNotices()} exists so "an embedder building its own
 * Chat can route them into whatever surface it has", and
 * {@see AgentManager::narrowedGrantWarnings()} is documented as the on-request
 * door for exactly that routing. A second store here would be a second truth
 * that could drift from the one stderr printed and the transcript seeded.
 *
 * WHAT IS NOT ON THIS LIST, and why. The runtime-notice sink
 * (`RuntimeNoticeSink`) is deliberately excluded: its backlog is drained into
 * the transcript whole on the next tick, so those warnings already appear in
 * full wherever the user is looking. `/notices` covers precisely what the
 * transcript could NOT show whole — the capped launch shelf (whose overflow
 * rows are recovered via {@see Bootstrap::launchNoticesDropped()}) and the
 * pair-packed grant aggregate.
 *
 * Foreign strings — notices embed config paths, and grant sentences embed
 * agent names and tool declarations that come from on-disk presets — go
 * through {@see Chat::reportField()}, the same discipline
 * `permissionsReport()` adopted after its line-forgery findings. One hostile
 * `agent:` name must not be able to fabricate a section header.
 *
 * @mirrors charmbracelet/crush notices surface (E653 Shape B)
 */
final class NoticesCommand
{
    /**
     * @param AgentManager|null $agentManager the launch's manager; null when
     *                                        none is wired, which degrades
     *                                        only the grants section — the
     *                                        launch shelf is manager-free.
     */
    public function __construct(private readonly ?AgentManager $agentManager) {}

    /**
     * The full report, gathered from the live stores.
     */
    public function report(): string
    {
        return self::compose(
            Bootstrap::launchNotices(),
            Bootstrap::launchNoticesDropped(),
            $this->agentManager?->narrowedGrantWarnings(),
        );
    }

    /**
     * The report built from GIVEN facts rather than live stores — pure, so the
     * panel can be tested against exactly the shapes a launch produces without
     * racing the process-global notice statics.
     *
     * Every section prints its header with its count EVEN AT ZERO and answers
     * with a `  - none` sentinel: the screen's whole claim is completeness, so
     * silence about a section must be a stated "none", never an absent header
     * a reader has to notice is absent. It also makes the line budget a
     * formula — base lines plus one per entry — which is what lets
     * `NoticesCommandTest` count lines instead of scanning for residue.
     *
     * @param list<string>       $launchNotices whole seated rows from {@see Bootstrap::launchNotices()}
     * @param list<string>       $droppedNotices whole clipped rows past the cap from {@see Bootstrap::launchNoticesDropped()}
     * @param list<string>|null  $grantWarnings the collector's whole sentences; null = no manager wired
     */
    public static function compose(array $launchNotices, array $droppedNotices, ?array $grantWarnings): string
    {
        $lines = [
            '/notices — every warning this launch raised, whole and un-capped.',
            'The transcript seeds capped and clipped rows; this list restates none of them short.',
            '',
        ];

        $lines[] = 'Launch notices (' . \count($launchNotices) . '):';
        $lines = self::appendEntries($lines, $launchNotices);

        $lines[] = '';
        $lines[] = 'Dropped past the transcript cap (' . \count($droppedNotices) . ') — each still went to stderr whole:';
        $lines = self::appendEntries($lines, $droppedNotices);

        $lines[] = '';
        if ($grantWarnings === null) {
            $lines[] = 'Narrowed agent tool grants: unavailable — no agent manager is wired into this session.';

            return implode("\n", $lines);
        }

        $lines[] = 'Narrowed agent tool grants (' . \count($grantWarnings)
            . ") — each compares an agent's declared tools to this session's ceiling:";
        $lines = self::appendEntries($lines, $grantWarnings);

        return implode("\n", $lines);
    }

    /**
     * One numbered line per fact, or the `  - none` sentinel when there are none.
     *
     * @param list<string> $lines
     * @param list<string> $entries
     *
     * @return list<string>
     */
    private static function appendEntries(array $lines, array $entries): array
    {
        if ($entries === []) {
            $lines[] = '  - none';

            return $lines;
        }

        $number = 0;
        foreach ($entries as $entry) {
            ++$number;
            $lines[] = sprintf('  %d. %s', $number, Chat::reportField($entry));
        }

        return $lines;
    }
}
