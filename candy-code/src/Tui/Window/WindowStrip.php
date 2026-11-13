<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Components\PaneLabel;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;
use SugarCraft\Style\Bar\ZoneEntry;
use SugarCraft\Style\Bar\ZonePiece;
use SugarCraft\Style\Bar\ZoneRow;
use SugarCraft\Style\Style;

/**
 * The one-row window strip: `[+]` first, then a tab per session
 * (attention glyph · name · badge · close), with the focused session's open
 * agent chips `▸ @explore` right after its tab.
 *
 * Part of the P-TABS.a kit (GAP-SES-12 / GAP-UI-N6). It is pure render over
 * {@see WindowList} — no App, no live registry, no clocks — because both
 * consumer steps (P-TABS.g tabs, P-AGENT.b attach) feed it their own state,
 * and a row that read the shell directly could not serve both.
 *
 * DENSITY. Three shapes, chosen by what fits $cols, the MenuBar rule
 * brought to this row: full paints `● name 3 ×`, icon drops each tab's name
 * (`● 3 ×`, chips shrink to `▸ ×`), terse keeps only the glyphs (`●`, no
 * close marks, no chips). The whole row is never wider than $cols: entries
 * that cannot fit collapse into a trailing `‹N›` count — and when even that
 * would not fit, the row degrades to `[+]` alone rather than over-painting.
 * `[+]` is never among the dropped: the row always keeps its new-session
 * affordance.
 *
 * ZONES. With $marked, each painted piece carries its click zone wrapped
 * from the PLAIN widths — the MenuBar marked-twin law (`compose()` measures
 * the plain strip even in the marked pass): Private-Use sentinels are
 * content to Width, so a budget measured on the marked row would shift
 * every later cell. {@see zones()} answers the spans from the same
 * arithmetic, so a zone can never cover a cell the renderer did not paint:
 * `win:new`, `win:s:<key>`, `winx:s:<key>`, `win:a:<runId>`,
 * `winx:a:<runId>`. A key outside the zone-id charset paints without its
 * zone rather than throwing mid-frame.
 *
 * NO_COLOR. Each state's glyph ({@see WindowAttention::glyph()}) is what
 * distinguishes it; colour rides on top and is never the only cue.
 */
final class WindowStrip
{
    /** Click zone of the new-session affordance. */
    public const ZONE_NEW = 'win:new';

    /** Click-zone prefix of a session tab body: `win:s:<key>`. */
    public const ZONE_SESSION_PREFIX = 'win:s:';

    /** Click-zone prefix of a session tab's close mark: `winx:s:<key>`. */
    public const ZONE_SESSION_CLOSE_PREFIX = 'winx:s:';

    /** Click-zone prefix of an agent chip body: `win:a:<runId>`. */
    public const ZONE_AGENT_PREFIX = 'win:a:';

    /** Click-zone prefix of an agent chip's close mark: `winx:a:<runId>`. */
    public const ZONE_AGENT_CLOSE_PREFIX = 'winx:a:';

    public const DENSITY_FULL = 0;
    public const DENSITY_ICON = 1;
    public const DENSITY_TERSE = 2;

    /** Widest a session or agent name may be on the strip, in cells. */
    public const NAME_COLS = 16;

    /** The plain new-session affordance, shared by both passes verbatim. */
    public const PLUS_PLAIN = '[+]';

    /** The plain close mark, shared by both passes verbatim. */
    public const CLOSE_PLAIN = '×';

    /** The plain chip marker an agent rides in front of its @name. */
    public const CHIP_PLAIN = '▸';

    /** The single cell between pieces. */
    private const SEP = ' ';

    private function __construct()
    {
    }

    /**
     * The painted row, or '' when there is no room for even `[+]`.
     *
     * Never wider than $cols in PAINTED cells under either pass: with
     * $marked the sentinels ride between cells invisibly, exactly where
     * {@see zones()} says they do.
     */
    public static function render(WindowList $list, Theme $theme, int $cols, bool $marked): string
    {
        return self::row($list, $theme, $cols)->render($cols, $marked);
    }

    /**
     * The zones of {@see render()} at the same size, as
     * [row, from column, to column (exclusive), zone id] quadruples.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string}>
     */
    public static function zones(WindowList $list, int $cols): array
    {
        // The theme only paints; widths come from the plain text, so zones
        // never depend on it.
        return self::row($list, Theme::dark(), $cols)->zones($cols);
    }

    /**
     * The row for $cols at the densest shape whose WHOLE row fits. Placement,
     * the `‹N›` collapse, the `[+]` reserve, the plain-measure /
     * marked-wrap law and the zone arithmetic are sugar-style's
     * {@see ZoneRow} (rider R14); what stays here is the strip's own
     * vocabulary: density, tab and chip shapes, zone ids, colour.
     */
    private static function row(WindowList $list, Theme $theme, int $cols): ZoneRow
    {
        $entries = $list->entries();
        $focusedKey = $list->focusedKey();

        $row = null;
        foreach ([self::DENSITY_FULL, self::DENSITY_ICON, self::DENSITY_TERSE] as $density) {
            $row = self::build($entries, $focusedKey, $density, $theme);
            if ($row->width() <= $cols) {
                break;
            }
        }

        return $row;
    }

    /**
     * @param list<WindowEntry> $entries
     */
    private static function build(array $entries, ?string $focusedKey, int $density, Theme $theme): ZoneRow
    {
        $muted = static fn (string $plain): string => Style::new()->foreground($theme->shellMuted)->render($plain);

        $units = [];
        foreach ($entries as $entry) {
            $pieces = self::tabPieces($entry, $density, $theme, $entry->ref->key === $focusedKey);
            if ($density !== self::DENSITY_TERSE && $entry->ref->key === $focusedKey) {
                foreach ($entry->children as $chip) {
                    $pieces[] = ZonePiece::of(self::SEP);
                    array_push($pieces, ...self::chipPieces($chip, $density, $theme));
                }
            }
            $units[] = ZoneEntry::of($pieces, $entry);
        }

        return ZoneRow::new()
            ->withLead(ZonePiece::of(self::PLUS_PLAIN, self::ZONE_NEW, $muted))
            ->withEntries($units)
            ->withSeparator(self::SEP)
            ->withOverflow(static fn (int $count): ZoneEntry => ZoneEntry::of([
                ZonePiece::of(Lang::t('tui.window.overflow', ['count' => $count]), null, $muted),
            ]))
            ->withMarker(static fn (string $id, string $painted): string => Mark::zone($id, $painted))
            ->withZoneGate(static fn (string $id): bool => WindowRef::keyIsZoneable($id));
    }

    /**
     * The layout: [density, placed [entry, chips] pairs with their cost,
     * dropped count, overflow-shown flag].
     *
     * Density is chosen where the WHOLE row still fits at that density;
     * sessions are then placed in tab order while their tab (plus, for the
     * focused one, its chips) fits beside the `‹N›` the rest would need.
     * On break-out the overflow marker is re-measured at its true count and
     * the row trims trailing tabs until it too fits — the marker is shown
     * only when it does.
     *
     * @return ?array{0: int, 1: list<array{0: WindowEntry, 1: list<WindowEntry>, 2: int}>, 2: int, 3: bool}
     */
    private static function plan(WindowList $list, int $cols): ?array
    {
        if ($cols < Width::string(self::PLUS_PLAIN)) {
            return null;
        }

        $entries = $list->entries();
        $focusedKey = $list->focusedKey();
        $density = self::densityForWidth($entries, $focusedKey, $cols);

        $placed = [];
        $dropped = 0;
        $used = Width::string(self::PLUS_PLAIN);
        $total = count($entries);
        for ($i = 0; $i < $total; $i++) {
            $entry = $entries[$i];
            $chips = $density !== self::DENSITY_TERSE && $entry->ref->key === $focusedKey
                ? $entry->children
                : [];
            $cost = Width::string(self::SEP) + self::tabWidth($entry, $density);
            foreach ($chips as $chip) {
                $cost += Width::string(self::SEP) + self::chipWidth($chip, $density);
            }
            $left = $total - $i - 1;
            $tail = $left > 0 ? self::overflowWidth($left) : 0;
            if ($used + $cost + $tail > $cols) {
                $dropped = $total - $i;
                break;
            }
            $placed[] = [$entry, $chips, $cost];
            $used += $cost;
        }

        $overflowShown = $dropped > 0;
        while ($overflowShown && $used + self::overflowWidth($dropped) > $cols && $placed !== []) {
            $last = array_pop($placed);
            $used -= $last[2];
            $dropped++;
        }
        if ($overflowShown && $used + self::overflowWidth($dropped) > $cols) {
            $overflowShown = false;
        }

        return [$density, $placed, $dropped, $overflowShown];
    }

    /**
     * The tab's plain pieces: body (glyph · name · badge) and close, the
     * close null at terse density. The body carries the tab's zone, the
     * close its `winx` zone.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function tabPlains(WindowEntry $entry, int $density): array
    {
        $glyph = $entry->attention->glyph();
        if ($density === self::DENSITY_TERSE) {
            return [$glyph, null];
        }

        $name = $density === self::DENSITY_FULL ? self::SEP . self::name($entry) : '';
        $badge = $entry->badge > 0 ? self::SEP . (string) $entry->badge : '';

        return [$glyph . $name . $badge, self::SEP . self::CLOSE_PLAIN];
    }

    /**
     * The chip's plain pieces at a density that paints chips (full/icon).
     *
     * @return array{0: string, 1: string}
     */
    private static function chipPlains(WindowEntry $chip, int $density): array
    {
        $body = $density === self::DENSITY_ICON
            ? self::CHIP_PLAIN
            : self::CHIP_PLAIN . ' @' . self::name($chip);

        return [$body, self::SEP . self::CLOSE_PLAIN];
    }

    private static function name(WindowEntry $entry): string
    {
        $safe = PaneLabel::safe($entry->ref->label);

        return $safe === '' ? Lang::t('tui.window.unnamed') : Width::truncate($safe, self::NAME_COLS);
    }

    /**
     * The tab as zoned pieces: the body (zone `win:s:<key>`) and, below terse
     * density, the close mark (zone `winx:s:<key>`).
     *
     * @return list<ZonePiece>
     */
    private static function tabPieces(WindowEntry $entry, int $density, Theme $theme, bool $focused): array
    {
        [$body, $close] = self::tabPlains($entry, $density);
        $pieces = [ZonePiece::of(
            $body,
            self::ZONE_SESSION_PREFIX . $entry->ref->key,
            static fn (string $plain): string => self::paintTab($entry, $plain, $density, $theme, $focused),
        )];
        if ($close !== null) {
            $pieces[] = ZonePiece::of(
                $close,
                self::ZONE_SESSION_CLOSE_PREFIX . $entry->ref->key,
                static fn (string $plain): string => Style::new()->foreground($theme->shellMuted)->render($plain),
            );
        }

        return $pieces;
    }

    /** Body with the glyph in its attention colour, the name focused-styled, the badge muted. */
    private static function paintTab(WindowEntry $entry, string $body, int $density, Theme $theme, bool $focused): string
    {
        $glyphStyle = Style::new()->foreground($entry->attention->color($theme));
        if ($density === self::DENSITY_TERSE) {
            return $glyphStyle->render($body);
        }

        $glyph = $entry->attention->glyph();
        $rest = substr($body, strlen($glyph));
        $badge = $entry->badge > 0 && str_ends_with($rest, self::SEP . (string) $entry->badge)
            ? self::SEP . (string) $entry->badge
            : '';
        $name = $badge === '' ? $rest : substr($rest, 0, strlen($rest) - strlen($badge));

        $nameStyle = $focused
            ? Style::new()->foreground($theme->shellPrimary)->bold()->underline()
            : Style::new()->foreground($theme->shellForeground);

        return $glyphStyle->render($glyph) . $nameStyle->render($name)
            . Style::new()->foreground($theme->shellMuted)->render($badge);
    }

    /**
     * The agent chip as zoned pieces: body (zone `win:a:<runId>`) and close
     * mark (zone `winx:a:<runId>`).
     *
     * @return list<ZonePiece>
     */
    private static function chipPieces(WindowEntry $chip, int $density, Theme $theme): array
    {
        [$body, $close] = self::chipPlains($chip, $density);

        $resolved = AgentColor::resolve($chip->ref->color, $theme);
        $nameStyle = $resolved !== null
            ? Style::new()->foreground($resolved)
            : Style::new()->foreground($theme->shellForeground);
        $marker = Style::new()->foreground($chip->attention->color($theme));

        return [
            ZonePiece::of(
                $body,
                self::ZONE_AGENT_PREFIX . $chip->ref->key,
                static function (string $plain) use ($marker, $nameStyle): string {
                    return $marker->render(self::CHIP_PLAIN) . $nameStyle->render(substr($plain, strlen(self::CHIP_PLAIN)));
                },
            ),
            ZonePiece::of(
                $close,
                self::ZONE_AGENT_CLOSE_PREFIX . $chip->ref->key,
                static fn (string $plain): string => Style::new()->foreground($theme->shellMuted)->render($plain),
            ),
        ];
    }
}
