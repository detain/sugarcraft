<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ContextBreakdown;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Lang;
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
            Lang::t('cmd.context.total', [
                'used' => TokenCount::compact($b->totalTokens()),
                'window' => TokenCount::compact($b->window),
                'percent' => $b->percentOfWindow(),
            ]),
            '',
        ];

        $system = $b->systemTokens();
        if ($system === null || $b->sections === null) {
            $lines[] = Lang::t('cmd.context.system.unmeasured');
        } else {
            $lines[] = Lang::t('cmd.context.system', [
                'tokens' => TokenCount::compact($system),
                'bytes' => self::bytes((int) $b->systemBytes()),
                'sections' => self::plural(
                    array_sum(array_column($b->sections, 'sections')),
                    Lang::t('cmd.context.unit.section'),
                    Lang::t('cmd.context.unit.sections'),
                ),
            ]);
            foreach ($b->sections as $row) {
                $label = $row['label'] . ($row['sections'] > 1 ? ' ×' . $row['sections'] : '');
                $lines[] = sprintf('  %-24s ~%-7s %s', Chat::reportField($label), TokenCount::compact($row['tokens']), $row['stability']);
            }
        }

        if ($b->toolTokens === null || $b->toolCount === null) {
            $lines[] = Lang::t('cmd.context.tools.unmeasured');
        } else {
            $lines[] = Lang::t('cmd.context.tools', [
                'tokens' => TokenCount::compact($b->toolTokens),
                'tools' => self::plural($b->toolCount, Lang::t('cmd.context.unit.tool'), Lang::t('cmd.context.unit.tools')),
            ]);
        }

        $lines[] = Lang::t('cmd.context.history', [
            'tokens' => TokenCount::compact($b->historyTokens),
            'messages' => self::plural($b->historyMessages, Lang::t('cmd.context.unit.message'), Lang::t('cmd.context.unit.messages')),
            'rows' => self::plural($b->uiOnlyRows, Lang::t('cmd.context.unit.ui-row'), Lang::t('cmd.context.unit.ui-rows')),
        ]);
        array_push($lines, ...self::pruningLines($b));
        $lines[] = Lang::t('cmd.context.free', ['tokens' => TokenCount::compact(max(0, $b->window - $b->totalTokens()))]);

        $lines[] = '';
        $lines[] = Lang::t('cmd.context.largest');
        if ($b->largest === []) {
            $lines[] = '  - ' . Lang::t('cmd.context.none');
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
            $lines[] = Lang::t('cmd.context.cache.none');
        } else {
            $lines[] = Lang::t('cmd.context.cache', [
                'last' => $last,
                'session' => $session,
                'replies' => self::plural(
                    $b->sessionCache['replies'],
                    Lang::t('cmd.context.unit.reporting-reply'),
                    Lang::t('cmd.context.unit.reporting-replies'),
                ),
            ]);
        }
        $breaks = self::cacheBreakLine($b);
        if ($breaks !== null) {
            $lines[] = $breaks;
        }

        return implode("\n", $lines);
    }

    /**
     * The cache-break line (roadmap 3.B-5, DCP §13.2 P2-10): how many
     * requests lost the prefix the request before them had cached, and the
     * newest one's cached share against the request before it — the telemetry opencode-dcp #614
     * was diagnosed from, where a context rewrite that was not byte-stable
     * cost every later request its cache. Nothing when no reply has
     * reported a cache split and none broke: the cache line above already
     * says so.
     */
    private static function cacheBreakLine(ContextBreakdown $b): ?string
    {
        $breaks = $b->cacheBreaks;
        if ($breaks === null) {
            return Lang::t('cmd.context.breaks.unmeasured');
        }
        if ($breaks['breaks'] === 0) {
            return $b->sessionCache === null ? null : Lang::t('cmd.context.breaks.none');
        }
        $last = $breaks['last'];

        return Lang::t('cmd.context.breaks', [
            'breaks' => $breaks['breaks'],
            'newest' => $last === null ? '' : Lang::t('cmd.context.breaks.newest', ['to' => $last['to'], 'from' => $last['from']]),
        ]);
    }

    /**
     * The pruned part (roadmap 5.6 remainder): what the session's context
     * ledger takes out of the history the model is sent, by kind — the
     * pruned outputs with the files their calls named, grouped by category
     * (roadmap 3.B-5) — and the newest pruned outputs by ref. Nothing when
     * not measured.
     *
     * @return list<string>
     */
    private static function pruningLines(ContextBreakdown $b): array
    {
        $p = $b->pruning;
        if ($p === null) {
            return [];
        }
        $mode = Lang::t('cmd.context.pruned.mode', [
            'mode' => $p['mode'],
            'source' => $p['sessionMode'] ? Lang::t('cmd.context.pruned.mode.session') : Lang::t('cmd.context.pruned.mode.configured'),
        ]);
        if ($b->prunedTokens() === 0 && $p['outputs'] === 0 && $p['contextRows'] === 0) {
            return [Lang::t('cmd.context.pruned.nothing', ['mode' => $mode])];
        }

        $parts = [];
        if ($p['outputs'] > 0) {
            $files = $p['files'] ?? '';
            $parts[] = sprintf(
                '%s (~%s%s)',
                self::plural($p['outputs'], Lang::t('cmd.context.unit.tool-output'), Lang::t('cmd.context.unit.tool-outputs')),
                TokenCount::compact($p['outputTokens']),
                $files === '' ? '' : Lang::t('cmd.context.pruned.files', ['files' => $files]),
            );
        }
        if ($p['contextRows'] > 0) {
            $parts[] = sprintf(
                '%s (~%s)',
                self::plural($p['contextRows'], Lang::t('cmd.context.unit.state-row'), Lang::t('cmd.context.unit.state-rows')),
                TokenCount::compact($p['contextRowTokens']),
            );
        }
        if ($p['block'] !== null) {
            $parts[] = Lang::t('cmd.context.pruned.summary', [
                'id' => $p['block']['id'],
                'compressed' => TokenCount::compact($p['block']['compressed']),
                'summary' => TokenCount::compact($p['block']['summary']),
            ]);
        }
        $lines = [Lang::t('cmd.context.pruned', [
            'tokens' => TokenCount::compact($b->prunedTokens()),
            'mode' => $mode,
            'parts' => implode(', ', $parts),
        ])];
        foreach ($p['rows'] as $row) {
            $lines[] = sprintf(
                '  %-6s %-9s ~%-7s %s',
                $row['ref'] === null ? '—' : RefTag::label($row['ref']),
                Chat::reportField($row['tool']),
                TokenCount::compact($row['tokens']),
                Lang::t('cmd.context.pruned.row', ['reason' => $row['reason'], 'by' => $row['by']]),
            );
        }

        return $lines;
    }

    private static function plural(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }

    private static function bytes(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' B' : rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
    }
}
