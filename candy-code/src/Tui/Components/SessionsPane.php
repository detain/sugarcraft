<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Window\AgentColor;
use SugarCraft\Code\Tui\Window\WindowEntry;
use SugarCraft\Code\Tui\Window\WindowList;
use SugarCraft\Code\Tui\Window\WindowRef;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * The vertical sessions pane — the dockable-pane form of the window list
 * (GAP-UI-N6: "a tab up top to open a new session … tabs for the running
 * sessions under it").
 *
 * Body row 0 is always `＋ New session`; then one row per session tab —
 * attention glyph, name, badge and the summary tail `· N agents` when the
 * session carries open agent windows — with each session's agent rows
 * nested directly under it (`└ ⠋ @explore`) in the agent's preset colour
 * ({@see AgentColor}). The focused session's name rides the strip's focus
 * highlight ({@see WindowStrip}): primary, bold, underline.
 *
 * PURE RENDER. {@see layout()} reads only the {@see WindowList} model and
 * does no I/O — the same kit law that keeps the horizontal strip testable
 * at any size: the shell-side state (which session is focused, what badge
 * it wears) is already IN the model by the time either surface paints.
 *
 * SCROLL. A list longer than the pane keeps the focused row in view with
 * the TodoPane anchor rule — the anchor rides one row from the top of the
 * window so the row before it paints for context — while the new-session
 * row stays pinned at row 0 of every window. {@see layout()} answers the
 * furthest this pane can scroll in its third slot, the same contract the
 * wheel clamps against for the Tools and Agents panes.
 *
 * WIDTHS follow the PaneFrame chrome the other dockable panes use: the
 * frame costs two rows and four columns (border two, padding two), and
 * every body row is measured on its finished bytes and cut to the inner
 * width, so no row ever over-pays its slot.
 */
final class SessionsPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    /** Row key of the pinned new-session row. */
    public const KEY_NEW = 'new';

    /** Row-key prefix of a session row: `s:<key>`. */
    public const KEY_SESSION_PREFIX = 's:';

    /** Row-key prefix of an agent row: `a:<runId>`. */
    public const KEY_AGENT_PREFIX = 'a:';

    /** The structural branch an agent row hangs off its session row by. */
    public const BRANCH = '└';

    /** The separator between a row's pieces, shared by both line shapes. */
    private const SEP = ' ';

    /**
     * The pane at $width x $rows. The block alone — {@see layout()} answers
     * it with its row keys.
     */
    public static function renderModel(WindowList $list, Theme $theme, int $width, int $rows): string
    {
        return self::layout($list, $theme, $width, $rows)[0];
    }

    /**
     * The pane, and the click key of each BODY line, in the shape
     * {@see \SugarCraft\Code\Tui\Renderer::keyed()} consumes:
     * `new` on the pinned row, `s:<key>` on a session row, `a:<runId>` on
     * an agent row. A key outside the zone-id charset yields null — the
     * row paints, it just is not clickable, the same degradation the
     * strip promises for hostile keys.
     *
     * The third slot is the furthest the pane can be scrolled, which the
     * wheel clamps against.
     *
     * @return array{0: string, 1: list<?string>, 2: int}
     */
    public static function layout(WindowList $list, Theme $theme, int $width, int $rows): array
    {
        $budget = max(1, $rows - self::CHROME_ROWS);
        $inner = max(1, $width - self::CHROME_COLS);
        $windowBudget = max(0, $budget - 1);

        // One flat list: session row first, its agent rows nested right
        // under it, so slicing one window keeps every pair adjacent.
        /** @var list<array{0: string, 1: string, 2: WindowEntry}> $flat */
        $flat = [];
        foreach ($list->entries() as $entry) {
            $flat[] = [self::KEY_SESSION_PREFIX . $entry->ref->key, self::sessionLine($entry, $theme, $list->focusedKey(), $inner), $entry];
            foreach ($entry->children as $agent) {
                $flat[] = [self::KEY_AGENT_PREFIX . $agent->ref->key, self::agentLine($agent, $theme, $inner), $agent];
            }
        }

        $maxOffset = max(0, count($flat) - $windowBudget);
        $offset = max(0, min(self::anchorIndex($flat, $list->focusedKey()) - 1, $maxOffset));

        $keys = [self::zoneableKey(self::KEY_NEW)];
        $lines = [Style::new()->foreground($theme->shellMuted)
            ->render(Width::truncate(Lang::t('tui.sessions.new'), $inner))];
        for ($i = $offset; $i < $offset + $windowBudget && $i < count($flat); $i++) {
            [$raw, $line] = $flat[$i];
            $keys[] = self::zoneableKey($raw);
            $lines[] = $line;
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' ' . Lang::t('tui.sessions.title') . ' '))
            ->padding(0, 1)
            ->width($width)
            ->borderForeground($theme->border);

        return [PaneFrame::render($st, implode("\n", $lines), $theme->border), $keys, $maxOffset];
    }

    /**
     * The TodoPane anchor rule brought to the flat row list: the focused
     * session's own row rides one line from the top of the window (the row
     * before it stays for context), clamped to what the pane can scroll.
     */
    private static function anchorIndex(array $flat, ?string $focusedKey): int
    {
        if ($focusedKey === null) {
            return 0;
        }
        $needle = self::KEY_SESSION_PREFIX . $focusedKey;
        foreach ($flat as $index => $row) {
            if ($row[0] === $needle) {
                return $index;
            }
        }

        return 0;
    }

    /** A row key only as long as it can actually become a zone id. */
    private static function zoneableKey(string $key): ?string
    {
        return WindowRef::keyIsZoneable($key) ? $key : null;
    }

    /**
     * One session row: glyph, name, badge, and the agent-count summary
     * tail when the session carries agent windows.
     */
    private static function sessionLine(WindowEntry $entry, Theme $theme, ?string $focusedKey, int $inner): string
    {
        $glyph = $entry->attention->glyph();
        $badge = $entry->badge > 0 ? self::SEP . (string) $entry->badge : '';
        $summary = $entry->children === []
            ? ''
            : self::SEP . '·' . self::SEP . Lang::t('tui.sessions.agents', ['count' => count($entry->children)]);

        // The name yields space to everything else, never the frame: what
        // is left after glyph, badge and summary is what it may use.
        $overhead = Width::string($glyph) + Width::string(self::SEP) + Width::string($badge) + Width::string($summary);
        $name = Width::truncate(self::name($entry), max(1, $inner - $overhead));

        $line = Style::new()->foreground($entry->attention->color($theme))->render($glyph)
            . ($entry->ref->key === $focusedKey
                ? Style::new()->foreground($theme->shellPrimary)->bold()->underline()->render(self::SEP . $name)
                : Style::new()->foreground($theme->shellForeground)->render(self::SEP . $name))
            . ($badge . $summary === '' ? '' : Style::new()->foreground($theme->shellMuted)->render($badge . $summary));

        return Width::string($line) > $inner ? Width::truncateAnsi($line, $inner) : $line;
    }

    /** One nested agent row: branch, its own attention glyph, @name in its preset colour. */
    private static function agentLine(WindowEntry $agent, Theme $theme, int $inner): string
    {
        $prefix = '  ' . self::BRANCH . self::SEP;
        $glyph = $agent->attention->glyph();
        $overhead = Width::string($prefix) + Width::string($glyph) + Width::string(self::SEP);
        $name = Width::truncate('@' . self::name($agent), max(1, $inner - $overhead));

        $resolved = AgentColor::resolve($agent->ref->color, $theme);
        $line = Style::new()->foreground($theme->shellMuted)->render($prefix)
            . Style::new()->foreground($agent->attention->color($theme))->render($glyph)
            . ($resolved === null
                ? Style::new()->foreground($theme->shellForeground)->render(self::SEP . $name)
                : Style::new()->foreground($resolved)->render(self::SEP . $name));

        return Width::string($line) > $inner ? Width::truncateAnsi($line, $inner) : $line;
    }

    /** The row's display name: safe label, or the honest unnamed word. */
    private static function name(WindowEntry $entry): string
    {
        $safe = PaneLabel::safe($entry->ref->label);

        return $safe === '' ? Lang::t('tui.sessions.unnamed') : $safe;
    }
}
