<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Live;

use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

/**
 * The parent's live view of every delegated run this session has heard
 * about: run id → {@see AgentLiveState}, and the parent's Task tool-call id →
 * the runs hung under that row (Appendix P §4.5, step P-B2).
 *
 * DELIBERATELY MUTABLE, like the live tool-event inbox and the
 * {@see \SugarCraft\Code\Agents\AgentManager} that `Chat` already mutates
 * from its event arms: the frames arrive through `update()`, the renderer
 * reads them in `view()`, and a copy per `mutate()` clone would drop every
 * beat that landed on a clone the screen no longer shows. Only `update()`
 * paths write it ({@see apply()}, {@see advance()}); `view()` only reads.
 *
 * THE CLOCK IS STATE TOO. {@see advance()} reads the injected clock and
 * stores the result; {@see now()} and {@see spinnerFrame()} only return what
 * was stored. So a frame is a pure function of the registry's contents — the
 * render never reads a clock — and a test that injects its own clock pins
 * the spinner glyph and every elapsed figure.
 *
 * Finished runs stay (their line is part of the Task row) until there are
 * more than {@see MAX_FINISHED} of them, when the oldest finished ones go.
 */
final class AgentLiveRegistry
{
    /** Finished runs kept for their rows before the oldest are forgotten. */
    public const MAX_FINISHED = 256;

    /** The spinner's frames, in order (the braille "dots" cycle). */
    public const SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * a11y-F5: the same spinner for a terminal negotiated down to the ASCII
     * colour profile — braille dots are not a glyph such a terminal is on-
     * record to draw, so there the frame cycle is the classic four-stroke
     * `|/-\`. Readers take the set through {@see spinnerGlyph()}, never by
     * indexing {@see SPINNER} directly.
     */
    public const SPINNER_ASCII = ['|', '/', '-', '\\'];

    /** Milliseconds per spinner frame: about 12 frames a second at most. */
    public const SPINNER_FRAME_MS = 80;

    /**
     * Reduced-motion switch (review rv-a11y-4): when on, {@see frameAt()}
     * answers one static frame and the spinner glyph never advances, while
     * elapsed-seconds movement still repaints. Gated HERE rather than at the
     * readers because every surface that draws the spinner (the transcript
     * renderer, the activity line) takes its glyph from {@see spinnerGlyph()}
     * over {@see spinnerFrame()} — zeroing the index makes them all static at
     * once.
     *
     * THE LAUNCH WIRE-UP LANDED (rv1014 FX1): `Bootstrap::chat()` reads
     * `CANDY_CODE_REDUCED_MOTION` (values `1`, `true`, `yes`, like this app's
     * other truthy env reads) exactly once on the shared launch funnel and
     * calls {@see self::setStaticSpinner()} with the verdict — the same change
     * tabulated the variable in docs/ENVIRONMENT.md, as the env-roster drift
     * guard demands. The read stays OUT of this class: a library-state holder
     * that sniffed its own environment could not be driven from tests that
     * want a deterministic answer.
     */
    private static bool $staticSpinner = false;

    /** @var \WeakMap<object, self>|null */
    private static ?\WeakMap $owned = null;

    /** @var array<string, AgentLiveState> run id → state, oldest first */
    private array $states = [];

    /** @var array<string, list<string>> parent call id → run ids, in arrival order */
    private array $byCall = [];

    /**
     * The workspace's preset-colour resolver (P-AGENT.a): a run name → the
     * colour word its preset declares, or null when the name is off-roster
     * or declares none. Seeded once at build time
     * ({@see withColorResolver()}); {@see apply()} threads it into every
     * fold so a run carries its colour from the first frame seen.
     *
     * @var (\Closure(string): ?string)|null
     */
    private ?\Closure $colorResolver = null;

    private float $now;

    private int $spinnerFrame;

    /**
     * @param \Closure(): float $clock seconds
     */
    private function __construct(private readonly \Closure $clock)
    {
        $this->now = ($clock)();
        $this->spinnerFrame = self::frameAt($this->now);
    }

    /**
     * @param (\Closure(): float)|null $clock seconds; null is microtime(true)
     */
    public static function new(?\Closure $clock = null): self
    {
        return new self($clock ?? static fn (): float => microtime(true));
    }

    /**
     * The registry belonging to $owner, made on first ask and gone with it.
     *
     * How a `Chat` with no workspace-registered registry keeps one without a
     * constructor slot of its own: it asks with an object every one of its
     * `mutate()` clones shares by identity (its live tool-event inbox), so
     * every clone reads the same registry and a new session's Chat a fresh
     * one. $clock applies only when the registry is made here.
     *
     * @param (\Closure(): float)|null $clock
     */
    public static function of(object $owner, ?\Closure $clock = null): self
    {
        self::$owned ??= new \WeakMap();

        return self::$owned[$owner] ??= self::new($clock);
    }

    /**
     * Seed the preset-colour resolver (P-AGENT.a). Build-time only, like the
     * clock in {@see new()}: this writes the one field that never moves after
     * the workspace is made, so it returns $this for the seeding chain in
     * {@see \SugarCraft\Code\Cli\Bootstrap::workspace()} rather than pretending
     * to be one of the value-object `with*()`s it is not.
     *
     * @param (\Closure(string): ?string)|null $resolver name → declared colour word
     */
    public function withColorResolver(?\Closure $resolver): self
    {
        $this->colorResolver = $resolver;

        return $this;
    }

    /**
     * Fold one frame into its run. A queued member's placeholder is replaced
     * by the run's own state the moment a non-queued frame for the same Task
     * call arrives.
     */
    public function apply(SubAgentActivity $activity): void
    {
        $current = $this->states[$activity->id] ?? null;
        $next = $current === null
            ? AgentLiveState::fromActivity($activity, $this->now, $this->colorResolver)
            : $current->apply($activity, $this->now, $this->colorResolver);
        if ($next === $current) {
            return;
        }

        $this->states[$activity->id] = $next;
        if ($next->parentCallId !== '') {
            $ids = $this->byCall[$next->parentCallId] ?? [];
            // A Task call runs one delegation. A NEW run under a call whose
            // runs have all finished means the provider reused the call id
            // (some number their calls per turn), and the old runs are not
            // this row's story.
            if ($current === null && $ids !== [] && $this->allFinished($ids)) {
                $ids = [];
            }
            if (!in_array($activity->id, $ids, true)) {
                $ids[] = $activity->id;
            }
            if ($activity->op !== SubAgentActivity::OP_QUEUED) {
                $queued = SubAgentActivity::queuedId($next->parentCallId);
                if ($queued !== $activity->id && isset($this->states[$queued])) {
                    unset($this->states[$queued]);
                    $ids = array_values(array_filter($ids, static fn (string $id): bool => $id !== $queued));
                }
            }
            $this->byCall[$next->parentCallId] = $ids;
        }

        if ($next->isFinished()) {
            $this->evictFinished();
        }
    }

    /**
     * Fold a run of frames — what one pump tick drained — in order.
     *
     * @param iterable<SubAgentActivity> $activities
     */
    public function applyBatch(iterable $activities): void
    {
        foreach ($activities as $activity) {
            $this->apply($activity);
        }
    }

    /**
     * The runs hung under one Task row, in the order they first reported.
     *
     * @return list<AgentLiveState>
     */
    public function forCall(string $parentCallId): array
    {
        $states = [];
        foreach ($this->byCall[$parentCallId] ?? [] as $id) {
            if (isset($this->states[$id])) {
                $states[] = $this->states[$id];
            }
        }

        return $states;
    }

    public function get(string $id): ?AgentLiveState
    {
        return $this->states[$id] ?? null;
    }

    /** @return list<AgentLiveState> every run known, oldest first */
    public function all(): array
    {
        return array_values($this->states);
    }

    /**
     * The runs belonging to one parent session (P-AGENT.a). A null
     * $sessionId is the unscoped answer — every run, exactly {@see all()} —
     * which is what the server host and every not-yet-scoped reader pass, so
     * the web agent tree never sees a filter. A real id answers only runs
     * whose frames carried that `parentSessionId`: a legacy run that carried
     * none stays invisible to a scoped view and visible to the unscoped one.
     *
     * @return list<AgentLiveState>
     */
    public function forSession(?string $sessionId): array
    {
        if ($sessionId === null) {
            return $this->all();
        }

        $states = [];
        foreach ($this->states as $state) {
            if ($state->parentSessionId === $sessionId) {
                $states[] = $state;
            }
        }

        return $states;
    }

    /**
     * Read the clock: the next frame's elapsed figures and spinner glyph.
     * Called from `update()` on the live pump's tick, never from `view()`.
     *
     * @return bool whether anything a frame shows moved
     */
    public function advance(): bool
    {
        $now = ($this->clock)();
        $frame = self::frameAt($now);
        $moved = $frame !== $this->spinnerFrame || (int) $now !== (int) $this->now;
        $this->now = $now;
        $this->spinnerFrame = $frame;

        return $moved;
    }

    /** The clock as of the last {@see advance()}, seconds. */
    public function now(): float
    {
        return $this->now;
    }

    /** Index into {@see SPINNER} as of the last {@see advance()}. */
    public function spinnerFrame(): int
    {
        return $this->spinnerFrame;
    }

    /**
     * The frame set the ambient color profile can draw: the ASCII cycle under
     * {@see ColorProfile::Ascii}, the braille dots otherwise (a11y-F5).
     *
     * This is NOT an environment sniff inside the registry — the profile is
     * latched process-wide at launch by `Tui\Renderer::applyAmbientColorProfile()`
     * and is null on pipes and in golden runs, so the default answer stays
     * braille and existing capture pins are byte-identical. A test drives both
     * profiles by calling `Style::setDefaultProfile()` directly, the same
     * contract that keeps the reduced-motion env read out of this class.
     *
     * @return list<string>
     */
    public static function spinnerFrames(): array
    {
        return Style::getDefaultProfile() === ColorProfile::Ascii ? self::SPINNER_ASCII : self::SPINNER;
    }

    /**
     * The glyph for spinner index $frame in the active frame set. The stored
     * index is a mod over {@see SPINNER}; the ASCII set is shorter and wraps
     * on its own count, so every reader must take the glyph through here
     * rather than index a const directly.
     */
    public static function spinnerGlyph(int $frame): string
    {
        $frames = self::spinnerFrames();
        $count = count($frames);

        return $frames[((($frame % $count) + $count) % $count)];
    }

    /**
     * @param list<string> $ids
     */
    private function allFinished(array $ids): bool
    {
        foreach ($ids as $id) {
            if (isset($this->states[$id]) && !$this->states[$id]->isFinished()) {
                return false;
            }
        }

        return true;
    }

    private static function frameAt(float $seconds): int
    {
        if (self::$staticSpinner) {
            return 0;
        }

        return intdiv((int) floor($seconds * 1000), self::SPINNER_FRAME_MS) % count(self::SPINNER);
    }

    /**
     * Turn the static spinner on or off ({@see $staticSpinner} for why and for
     * the launch wire-up this waits on). Process-wide: the spinner is one
     * presentation fact about the terminal, not per-run state.
     */
    public static function setStaticSpinner(bool $static): void
    {
        self::$staticSpinner = $static;
    }

    /** Whether the spinner is pinned to its first frame (see {@see setStaticSpinner()}). */
    public static function staticSpinner(): bool
    {
        return self::$staticSpinner;
    }

    private function evictFinished(): void
    {
        $finished = array_keys(array_filter($this->states, static fn (AgentLiveState $s): bool => $s->isFinished()));
        $excess = count($finished) - self::MAX_FINISHED;
        for ($i = 0; $i < $excess; $i++) {
            $id = $finished[$i];
            $call = $this->states[$id]->parentCallId;
            unset($this->states[$id]);
            if (isset($this->byCall[$call])) {
                $left = array_values(array_filter($this->byCall[$call], static fn (string $other): bool => $other !== $id));
                if ($left === []) {
                    unset($this->byCall[$call]);
                } else {
                    $this->byCall[$call] = $left;
                }
            }
        }
    }
}
