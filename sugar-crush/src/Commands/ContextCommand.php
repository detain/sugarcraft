<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ContextBreakdown;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Util\TokenCount;

/**
 * Implements `/context` (alias `/tokens`): where the context window goes,
 * roadmap 5.6.
 *
 * Usage:
 *   /context  — the next request's estimated size, split into the system
 *               prompt (per layer), the tool schemas and the history; the
 *               largest messages; what the session's context ledger prunes
 *               out of the history the model is sent; and how much of each
 *               prompt the provider served from its cache
 *
 * READ-ONLY, like `/permissions` and `/notices`: it measures and prints, and
 * never changes the conversation or calls a model. Arguments are ignored —
 * the report has no sub-views.
 *
 * The figures come from a {@see ContextBreakdown}; this class is only the
 * wording, kept pure ({@see compose()}) so a test can pin a report from a
 * hand-built breakdown. Every part the breakdown could not measure prints as
 * "not measured" with the reason, never as a zero. Transcript text quoted in
 * the largest-message rows goes through {@see Chat::reportField()}, so a
 * pasted escape sequence cannot repaint the screen around it.
 *
 * This is a SugarCraft architecture type, not a port — charmbracelet/crush has
 * no `/context`; the surface follows Claude Code's `/context` and opencode's
 * token panel.
 */
final class ContextCommand
{
    public function __construct(private readonly ContextBreakdown $breakdown)
    {
    }

    /** The report for the transcript. */
    public function report(): string
    {
        return self::compose($this->breakdown);
    }

    public static function compose(ContextBreakdown $b): string
    {
        $lines = [
            sprintf(
                'Context: ~%s of %s tokens (%d%%) for the next request — estimates, script-weighted.',
                TokenCount::compact($b->totalTokens()),
                TokenCount::compact($b->window),
                $b->percentOfWindow(),
            ),
            '',
        ];

        $system = $b->systemTokens();
        if ($system === null || $b->sections === null) {
            $lines[] = 'System prompt: not measured — this backend does not report the prompt it sends.';
        } else {
            $lines[] = sprintf(
                'System prompt: ~%s (%s, %s)',
                TokenCount::compact($system),
                self::bytes((int) $b->systemBytes()),
                self::plural(array_sum(array_column($b->sections, 'sections')), 'section'),
            );
            foreach ($b->sections as $row) {
                $label = $row['label'] . ($row['sections'] > 1 ? ' ×' . $row['sections'] : '');
                $lines[] = sprintf('  %-24s ~%-7s %s', Chat::reportField($label), TokenCount::compact($row['tokens']), $row['stability']);
            }
        }

        if ($b->toolTokens === null || $b->toolCount === null) {
            $lines[] = 'Tool schemas: not measured — this backend does not say which tools it sends.';
        } else {
            $lines[] = sprintf('Tool schemas: ~%s (%s)', TokenCount::compact($b->toolTokens), self::plural($b->toolCount, 'tool'));
        }

        $lines[] = sprintf(
            'History: ~%s (%s sent to the model; %s never sent)',
            TokenCount::compact($b->historyTokens),
            self::plural($b->historyMessages, 'message'),
            self::plural($b->uiOnlyRows, 'UI-only row'),
        );
        array_push($lines, ...self::pruningLines($b));
        $lines[] = sprintf('Free: ~%s', TokenCount::compact(max(0, $b->window - $b->totalTokens())));

        $lines[] = '';
        $lines[] = 'Largest messages:';
        if ($b->largest === []) {
            $lines[] = '  - none';
        }
        foreach ($b->largest as $row) {
            $lines[] = sprintf(
                '  #%-4d %-9s ~%-7s %s',
                $row['index'],
                $row['role'],
                TokenCount::compact($row['tokens']),
                Chat::reportField($row['preview']),
            );
        }

        $lines[] = '';
        $last = $b->lastCachePercent();
        $session = $b->sessionCachePercent();
        if ($last === null || $session === null || $b->sessionCache === null) {
            $lines[] = 'Prompt cache: no reply has reported a cache split yet.';
        } else {
            $lines[] = sprintf(
                'Prompt cache: %d%% of the last prompt was read from cache; %d%% across %s.',
                $last,
                $session,
                self::plural($b->sessionCache['replies'], 'reporting reply', 'reporting replies'),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The pruned part (roadmap 5.6 remainder): what the session's context
     * ledger takes out of the history the model is sent, by kind, and the
     * newest pruned outputs by ref. Nothing when not measured.
     *
     * @return list<string>
     */
    private static function pruningLines(ContextBreakdown $b): array
    {
        $p = $b->pruning;
        if ($p === null) {
            return [];
        }
        $mode = sprintf('mode %s, %s', $p['mode'], $p['sessionMode'] ? 'set for this session' : 'configured');
        if ($b->prunedTokens() === 0 && $p['outputs'] === 0 && $p['contextRows'] === 0) {
            return [sprintf('Pruned: nothing (%s) — /sweep prunes the last turn\'s tool outputs.', $mode)];
        }

        $parts = [];
        if ($p['outputs'] > 0) {
            $parts[] = sprintf('%s (~%s)', self::plural($p['outputs'], 'tool output'), TokenCount::compact($p['outputTokens']));
        }
        if ($p['contextRows'] > 0) {
            $parts[] = sprintf('%s (~%s)', self::plural($p['contextRows'], 'superseded state row'), TokenCount::compact($p['contextRowTokens']));
        }
        if ($p['block'] !== null) {
            $parts[] = sprintf(
                'summary b%d (~%s → ~%s)',
                $p['block']['id'],
                TokenCount::compact($p['block']['compressed']),
                TokenCount::compact($p['block']['summary']),
            );
        }
        $lines = [sprintf(
            'Pruned: ~%s out of what the model is sent (%s) — %s. The transcript keeps every row.',
            TokenCount::compact($b->prunedTokens()),
            $mode,
            implode(', ', $parts),
        )];
        foreach ($p['rows'] as $row) {
            $lines[] = sprintf(
                '  %-6s %-9s ~%-7s %s by %s',
                $row['ref'] === null ? '—' : RefTag::label($row['ref']),
                Chat::reportField($row['tool']),
                TokenCount::compact($row['tokens']),
                $row['reason'],
                $row['by'],
            );
        }

        return $lines;
    }

    private static function plural(int $count, string $one, ?string $many = null): string
    {
        return $count . ' ' . ($count === 1 ? $one : ($many ?? $one . 's'));
    }

    private static function bytes(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' B' : rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
    }
}
