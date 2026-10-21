<?php

declare(strict_types=1);

namespace SugarCraft\Code;

/**
 * The per-frame mutable state of one {@see Renderer::renderView()} paint.
 *
 * These fields used to be fourteen process-global statics directly on
 * {@see Renderer}. They were only ever correct because rendering is serial:
 * the registries here are POSITIONAL to the frame being built — rows are
 * recorded while the transcript is walked and consumed after the shell is
 * composed — so two renders in flight at once would have marked each other's
 * rows and registered each other's zones. Collecting them into one object
 * gives that serial contract a name and a lifetime: {@see Renderer::renderView()}
 * owns one instance per paint, threads it through the pipeline, and publishes
 * it when the frame is done, which is what between-frame readers (the mouse
 * hit-test, the wheel clamp, the text selection) query.
 *
 * What stays on {@see Renderer} is deliberately NOT here: the content-keyed
 * render memos and the shared zone Scanner are genuinely process-scoped (their
 * own docblocks say why — a click arrives BETWEEN frames, and the image/markdown
 * caches exist to survive frames). The three compositor inputs below are
 * frame-scoped by their contract (each is "set around one paint and reset on
 * the way out", declared by the shell through
 * {@see Renderer::setPaletteAbandoned()} / {@see Renderer::setAgentsPaneDocked()} /
 * {@see Renderer::setAgentView()}); they ride a {@see beginNext()} swap
 * because they are declared before the paint they belong to, not during it.
 *
 * The class is mutable by design — it accumulates during a serial paint — and
 * it is public only as {@see Renderer}'s internal storage: nothing outside
 * `Renderer` should construct or fill one.
 */
final class RenderFrame
{
    /**
     * Tool-call rows this frame wants clickable, in document order, keyed by
     * call id (first-wins, so the key lookup replaces the O(n²) scan audit
     * 15b-10 measured away).
     *
     * @var array<array-key, array{id: string, label: string}>
     */
    private array $toolCallZones = [];

    /**
     * The styled HEAD of every tool row this frame emitted, in document order
     * — the classification {@see \SugarCraft\Code\Renderer::fitToPane()} keys
     * its truncate-instead-of-wrap branch off. A SEPARATE list from
     * {@see $toolCallZones} on purpose: that one is a CLICK registry (empty
     * when clicks are off or an id is off-charset), and keying the LAYOUT
     * decision off it made tool rows wrap in exactly those states. This one is
     * recorded unconditionally. See {@see \SugarCraft\Code\Renderer::fitToPane()}
     * for the full reason.
     *
     * @var list<string>
     */
    private array $toolRowHeads = [];

    /**
     * The key of the one thought Ctrl+O reaches in this frame — the only
     * collapsed row {@see \SugarCraft\Code\Renderer::renderThought()} may
     * advertise the chord on. Set at the top of the paint from
     * {@see Chat::latestThoughtKey()}.
     */
    private ?string $ctrlOThoughtKey = null;

    /**
     * Palette rows this frame wants clickable, as the FULLY rendered box lines
     * they became. Collected before the overlay composite and marked after it:
     * Veil measures the overlay it centres, and a sentinel pair measures ~29
     * columns, so a row marked before the composite shifts the whole palette
     * left and clips short. See {@see \SugarCraft\Code\Renderer::markPaletteItems()}.
     *
     * @var list<array{id: string, line: string}>
     */
    private array $paletteItemZones = [];

    /**
     * Session picker rows this frame wants clickable (E744 WS4), as the fully
     * rendered overlay lines they became — the same pre-composite detour
     * {@see $paletteItemZones} documents, for the identical reason.
     *
     * `acts` carries the highlighted row's action glyphs (verb => styled
     * glyph), wrapped as `session-act:` zones inside the row's own.
     *
     * @var list<array{id: string, line: string, acts: array<string, string>}>
     */
    private array $sessionRowZones = [];

    /**
     * Live agent lines this frame wants clickable (roadmap P-C2), as
     * `['id' => runId, 'label' => the line's plain text]`, collected while the
     * transcript is built and marked once the shell is drawn — the
     * {@see $toolCallZones} detour, for the same layout reason.
     *
     * @var list<array{id: string, label: string}>
     */
    private array $agentLineZones = [];

    /**
     * Whether the palette this frame would paint is ABANDONED: open on the
     * hosted `Chat` while a keyboard-owning shell view stands in front of it.
     * While true the overlay chain skips the palette link — the ONE rule
     * ordering that chain is that the overlay on screen must be the one
     * `Chat::update()` routes the next keystroke to. Declared per paint by
     * the shell compositor; see {@see \SugarCraft\Code\Renderer::$paletteAbandoned}'s
     * original contract at {@see Renderer::setPaletteAbandoned()}.
     */
    private bool $paletteAbandoned = false;

    /**
     * Whether the Agents sidepane is docked in the frame being composited
     * (CL-2 FIX 2): the docked sidebar already lists every run, so the
     * transcript footer must not paint a SECOND listing in the same frame.
     * Same per-paint handoff as {@see $paletteAbandoned}.
     */
    private bool $agentsPaneDocked = false;

    /**
     * The Agent View the shell has open for this frame (roadmap P-C2), as
     * {@see \SugarCraft\Code\App\App::agentViewFrame()} built it, or null for
     * the parent transcript. The view's state is the SHELL's, so it reaches
     * this frame the same way {@see $paletteAbandoned} does.
     *
     * @var array{id: string, name: string, state: ?\SugarCraft\Code\Agents\Live\AgentLiveState, rows: list<Message>, composer: ?string, attach: ?\SugarCraft\Code\Tui\AgentOutputState, siblings: list<string>, transcript: bool}|null
     */
    private ?array $agentView = null;

    /**
     * Where this frame's top-left cell sits on the TERMINAL, as 0-based
     * `[col, row]` deltas (`[0, 0]` when this renderer's frame IS the root).
     * Reset at the start of every {@see \SugarCraft\Code\Renderer::scanRoot()}
     * pass and re-declared by a hosting compositor after it places the frame;
     * mouse consumers subtract it before hit-testing. See
     * {@see Renderer::setZoneOrigin()}.
     *
     * @var array{0: int, 1: int}
     */
    private array $zoneOrigin = [0, 0];

    /**
     * The rectangle of THIS frame a mouse drag may select text in, as
     * inclusive 1-based `[rowFrom, rowTo, colFrom, colTo]` cells, or null when
     * nothing on it is selectable. Measured after the tail clip and scroll
     * window, and withheld while an overlay owns the frame.
     *
     * @var array{0:int,1:int,2:int,3:int}|null
     */
    private ?array $selectableRegion = null;

    /**
     * THIS frame's lines, unhighlighted — what a finished selection is read
     * from, because the release that copies arrives between frames.
     *
     * @var list<string>
     */
    private array $selectableLines = [];

    /**
     * How many content lines this frame had to drop off the top to fit the
     * terminal — the largest {@see Chat::scrollOffset()} that still shows a
     * full screen of transcript. The wheel event that needs it arrives
     * BETWEEN frames, so it can only be clamped against what was painted.
     */
    private int $maxScrollOffset = 0;

    /**
     * How far this frame's keybinding reference overflowed its box, in lines;
     * 0 when it fits or was not painted. {@see Chat::withKeyHelp()} clamps
     * against it, and {@see \SugarCraft\Code\Renderer::renderKeyHelp()} runs
     * FIRST in the overlay chain so every painted frame re-measures it.
     */
    private int $keyHelpMaxOffset = 0;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The frame for the next paint, begun from this published one.
     *
     * Only the three compositor INPUTS are carried: they describe the shell
     * state App::view arms around each paint (palette handoff, docked Agents
     * pane, open Agent View) and reset after it, so the fresh frame must see
     * them to build the frame the user is looking at. Everything else —
     * registries, zone origin, selection, offsets — is reborn empty from the
     * chat state renderView walks; a leftover row from a previous frame would
     * be marked at the wrong place (or registered twice, which makes the zone
     * scan throw).
     */
    public function beginNext(): self
    {
        $next = new self();
        $next->paletteAbandoned = $this->paletteAbandoned;
        $next->agentsPaneDocked = $this->agentsPaneDocked;
        $next->agentView = $this->agentView;

        return $next;
    }

    // ------------------------------------------------------------------
    // Click/layout registries (built during the walk, consumed after it)
    // ------------------------------------------------------------------

    /**
     * Remember tool call $key as a clickable row, first call per id winning
     * (a duplicate zone id makes the scan throw and costs the frame every
     * zone — see {@see \SugarCraft\Code\Renderer::recordToolCallZone()} for
     * the guards that decide whether a row is recordable at all).
     */
    public function recordToolCallZone(string $key, string $label): void
    {
        // Keyed by id so the first-wins check is a lookup. A scan here made
        // the frame quadratic in the number of tool calls (audit 15b-10).
        if (isset($this->toolCallZones[$key])) {
            return;
        }

        $this->toolCallZones[$key] = ['id' => $key, 'label' => $label];
    }

    /** @return array<array-key, array{id: string, label: string}> in document order, keyed by id */
    public function toolCallZones(): array
    {
        return $this->toolCallZones;
    }

    /** Note the styled head of a tool row this frame emitted, in document order. */
    public function recordToolRowHead(string $head): void
    {
        $this->toolRowHeads[] = $head;
    }

    /** @return list<string> */
    public function toolRowHeads(): array
    {
        return $this->toolRowHeads;
    }

    public function resetToolRowHeads(): void
    {
        $this->toolRowHeads = [];
    }

    public function noteCtrlOThoughtKey(?string $key): void
    {
        $this->ctrlOThoughtKey = $key;
    }

    public function ctrlOThoughtKey(): ?string
    {
        return $this->ctrlOThoughtKey;
    }

    /**
     * Remember one rendered palette box line as a clickable item row.
     *
     * @param array{id: string, line: string} $item
     */
    public function recordPaletteItemZone(array $item): void
    {
        $this->paletteItemZones[] = $item;
    }

    /** @return list<array{id: string, line: string}> in row order */
    public function paletteItemZones(): array
    {
        return $this->paletteItemZones;
    }

    /**
     * Remember one rendered session-picker overlay line as a clickable row.
     *
     * @param array{id: string, line: string, acts: array<string, string>} $row
     */
    public function recordSessionRowZone(array $row): void
    {
        $this->sessionRowZones[] = $row;
    }

    /** @return list<array{id: string, line: string, acts: array<string, string>}> in row order */
    public function sessionRowZones(): array
    {
        return $this->sessionRowZones;
    }

    /**
     * Remember one live agent line as a clickable row.
     *
     * @param array{id: string, label: string} $line
     */
    public function recordAgentLineZone(array $line): void
    {
        $this->agentLineZones[] = $line;
    }

    /** @return list<array{id: string, label: string}> */
    public function agentLineZones(): array
    {
        return $this->agentLineZones;
    }

    // ------------------------------------------------------------------
    // Compositor inputs (declared around one paint, ride a frame swap)
    // ------------------------------------------------------------------

    public function notePaletteAbandoned(bool $abandoned): void
    {
        $this->paletteAbandoned = $abandoned;
    }

    public function paletteAbandoned(): bool
    {
        return $this->paletteAbandoned;
    }

    public function noteAgentsPaneDocked(bool $docked): void
    {
        $this->agentsPaneDocked = $docked;
    }

    public function agentsPaneDocked(): bool
    {
        return $this->agentsPaneDocked;
    }

    /**
     * @param array{id: string, name: string, state: ?\SugarCraft\Code\Agents\Live\AgentLiveState, rows: list<Message>, composer: ?string, attach: ?\SugarCraft\Code\Tui\AgentOutputState, siblings: list<string>, transcript: bool}|null $view
     */
    public function noteAgentView(?array $view): void
    {
        $this->agentView = $view;
    }

    /** @return array{id: string, name: string, state: ?\SugarCraft\Code\Agents\Live\AgentLiveState, rows: list<Message>, composer: ?string, attach: ?\SugarCraft\Code\Tui\AgentOutputState, siblings: list<string>, transcript: bool}|null */
    public function agentView(): ?array
    {
        return $this->agentView;
    }

    // ------------------------------------------------------------------
    // Frame outputs (published with the frame, read between frames)
    // ------------------------------------------------------------------

    /**
     * @param array{0: int, 1: int} $origin
     */
    public function setZoneOrigin(array $origin): void
    {
        $this->zoneOrigin = $origin;
    }

    public function resetZoneOrigin(): void
    {
        $this->zoneOrigin = [0, 0];
    }

    /** @return array{0: int, 1: int} */
    public function zoneOrigin(): array
    {
        return $this->zoneOrigin;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int}|null $region
     */
    public function setSelectableRegion(?array $region): void
    {
        $this->selectableRegion = $region;
    }

    /** @return array{0:int,1:int,2:int,3:int}|null */
    public function selectableRegion(): ?array
    {
        return $this->selectableRegion;
    }

    /**
     * @param list<string> $lines
     */
    public function setSelectableLines(array $lines): void
    {
        $this->selectableLines = $lines;
    }

    /** @return list<string> */
    public function selectableLines(): array
    {
        return $this->selectableLines;
    }

    public function noteMaxScrollOffset(int $overflow): void
    {
        $this->maxScrollOffset = $overflow;
    }

    public function maxScrollOffset(): int
    {
        return $this->maxScrollOffset;
    }

    public function noteKeyHelpMaxOffset(int $overflow): void
    {
        $this->keyHelpMaxOffset = $overflow;
    }

    public function keyHelpMaxOffset(): int
    {
        return $this->keyHelpMaxOffset;
    }

    /**
     * Drop the between-frame zone/selection/origin state. This is what
     * {@see Renderer::clearZones()} applies to the published frame when a
     * shell paints something that does NOT contain this renderer's output —
     * stale boxes under fresh content would fire the wrong click.
     * `maxScrollOffset` and `keyHelpMaxOffset` are NOT zeroed here: every
     * paint that sets them runs before they matter, and a frame that never
     * renders must leave the last clamps standing, exactly as the loose
     * statics always did.
     */
    public function clearZoneState(): void
    {
        $this->selectableRegion = null;
        $this->selectableLines = [];
        $this->zoneOrigin = [0, 0];
        $this->paletteAbandoned = false;
    }
}
