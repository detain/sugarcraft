<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Style;
use SugarCraft\CellBuffer\Region;
use SugarCraft\CellBuffer\Position as BufferPosition;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * Floating alert notification renderer.
 *
 * Renders one or more toast alerts (error/warning/info/success) at a fixed
 * screen position, composited over a background view. Stacked/queued alerts
 * are bounded by maxConcurrent + the Overflow strategy; runnable demos live
 * in examples/ (basic.php, types.php, accessibility.php).
 *
 * Design antecedent: DaltonSW/bubbleup. Custom alert types (its
 * RegisterNewAlertType seam) are unsupported here by design: string types
 * resolve only to the four built-ins, and styled built-ins plus per-Alert
 * colour overrides are the parity path (README 'Alert Types').
 *
 * @see https://github.com/DaltonSW/bubbleup
 */
final class Toast
{
    /**
     * sugar-mouse zone sentinels (U+E000 OPEN / U+E001 CLOSE). This lib does
     * NOT emit them and depends on nothing that does — the constants exist
     * solely so a marked-frame host's OWN markers survive the composite
     * (see carryHostZoneMarkers()).
     */
    private const ZONE_OPEN = "\u{e000}";
    private const ZONE_CLOSE = "\u{e001}";

    /**
     * Hard per-dimension ceiling for view()/actionRects()/actionAt() viewport
     * dimensions. The frame Buffer allocates width*height cells
     * eagerly, and a mis-parsed winsize used to reach Buffer::new unbounded —
     * an OOM Error a host cannot even catch. Out-of-range dimensions now fail
     * loud with a descriptive InvalidArgumentException instead of allocating.
     * 4 096 sits two orders of magnitude above any shipped emulator; the
     * per-dimension arms alone still admitted 4095x4095 (~1.6e7 cells) before
     * MAX_VIEWPORT_CELLS below bounded the product.
     */
    public const MAX_VIEWPORT_DIMENSION = 4096;

    /**
     * Product cap on viewport cells (width x height) — the allocation the
     * frame Buffer actually pays for. A ~1000x1000 grid is already far past
     * any terminal, yet a huge-but-thin winsize stays legitimate (4096x244
     * passes). Breaching the cap fails loud with the same throw-before-allocate
     * InvalidArgumentException as the per-dimension ceilings.
     */
    public const MAX_VIEWPORT_CELLS = 1_000_000;

    /**
     * Display-cell ceiling for a single alert message.
     * The README used to sell "arbitrary length, word-wrapped" and the queue
     * caveat only bounded alert COUNT — a remote string 100x longer than any
     * viewport was an amplifier for the (now-linear) renderer and for the
     * stack geometry. Over the cap the message truncates with a visible
     * ' …[truncated]' marker (leading space, ellipsis); the full text still
     * rides the Alert into history(). 8192 cells ≈ 170 wrapped rows at the
     * default width — past any readable notification, far below any hostile
     * budget.
     */
    public const MAX_MESSAGE_CELLS = 8192;

    /**
     * Action buttons rendered per alert. Extra
     * buttons fold into a visible '+N more' row instead of growing the box
     * without bound; hidden actions stay reachable via the Alert in
     * history()/activeAlerts(), just not clickable.
     */
    public const MAX_ACTIONS_PER_ALERT = 64;

    // Configuration
    private int $maxWidth   = 50;
    private int $minWidth   = 0;
    private Position $position = Position::TopLeft;
    private SymbolSet $symbols = SymbolSet::Unicode;
    private ?float $duration = null;  // seconds, null = no auto-dismiss

    /** Internal queue of active alerts. */
    private array $queue = [];

    /**
     * Dismissed flag — if true, Toast won't render any alerts ("stop
     * rendering until cleared"): {@see clear()} is the documented revival and
     * also resets this flag, while {@see alert()} / {@see progressToast()} /
     * {@see push()} refuse to queue into a dead instance.
     */
    private bool $dismissed = false;

    /**
     * Host-consumed flag: whether the host should dismiss on Escape key
     * press. The renderer stores this preference; it does not handle input
     * itself. Polarity note: upstream bubbleup treats ESC-close as an explicit
     * opt-in; flipped to default-on here because the flag is inert unless the
     * host wires it — the renderer never consumes keypresses either way.
     */
    private bool $allowEscToClose = true;

    /** Opt-in accessible chrome: severity word in the header + numeric percentage on progress rows. Default OFF keeps every existing frame byte-identical. */
    private bool $accessibleText = false;

    /**
     * Carry the host's U+E000..U+E001 zone markers through the composite.
     *
     * ON (default) preserves every published frame byte-for-byte: covered
     * sentinel cells are restored from the background so open/close pairing
     * survives the blit, at the documented cosmetic cost of holes in the box
     * chrome. OFF restores full chrome at the mirror-image cost: a marker
     * span running under the toast loses its covered half — hosts that read
     * zone spans back out of the composited output but prefer visual
     * integrity opt out. See sugar-toast finding 061.
     */
    private bool $hostZoneCarry = true;

    /**
     * Per-instance layout memo keyed by (background, width, height):
     * ['key'=>[bg,w,h],'layout'=>?array,'output'=>string,'frame'=>?ToastFrame].
     * mutate() drops it on every state change, so the frame the hit-test
     * doors read can never lag the queue they describe (finding 001).
     */
    private ?array $frameMemo = null;

    /**
     * Maximum number of concurrent alerts (null = unlimited).
     *
     * Growth caveat, stated honestly: with the default null cap the queue only
     * shrinks through `dismiss()`, `clear()`, or the opportunistic prune that
     * now runs on every write, so persistent (non-expiring) alerts accumulate
     * without bound until the host clears them.
     */
    private ?int $maxConcurrent = null;

    /** Overflow strategy when queue exceeds maxConcurrent. */
    private Overflow $overflow = Overflow::DropOldest;

    /** Maximum number of entries kept in the history log (null = unbounded). */
    private ?int $historyLimit = 100;

    /** History log of dismissed alerts. */
    private HistoryLog $historyLog;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public function __construct(int $maxWidth = 50)
    {
        $this->maxWidth = $maxWidth;
        $this->historyLog = HistoryLog::new();
    }

    public static function new(int $maxWidth = 50): self
    {
        return new self($maxWidth);
    }

    // -------------------------------------------------------------------------
    // Configuration (fluent with*)
    // -------------------------------------------------------------------------

    public function withMaxWidth(int $w): self
    {
        if ($w <= 0) {
            throw new \InvalidArgumentException(
                'withMaxWidth(): positive cells = the box cap; there is no non-positive width — '
                . 'a zero/negative value would floor silently to a permanently unreadable 1-cell box, '
                . 'refused like withMaxConcurrent() refuses its below-1 cap'
            );
        }
        return $this->mutate(['maxWidth' => $w]);
    }

    /**
     * Minimum box width in cells — a MASTER SWITCH, not just a floor:
     * 0 (default) keeps the box FIXED at full width (maxWidth, capped by the
     * frame); ANY positive value engages fit-content sizing, where the box
     * shrinks toward its message between this floor and maxWidth. A caller
     * setting withMinWidth(20) "to allow narrow alerts" flips the whole width
     * model — that is the switch engaging, not a side effect.
     */
    public function withMinWidth(int $w): self
    {
        if ($w < 0) {
            throw new \InvalidArgumentException(
                'withMinWidth(): 0 is the FIXED-WIDTH sentinel (box holds its full width cap, no fit-content), '
                . 'positive cells enable fit-content between this floor and maxWidth — only NEGATIVE is meaningless here'
            );
        }
        return $this->mutate(['minWidth' => $w]);
    }

    public function withPosition(Position $pos): self
    {
        return $this->mutate(['position' => $pos]);
    }

    public function withSymbolSet(SymbolSet $set): self
    {
        return $this->mutate(['symbols' => $set]);
    }

    /**
     * Auto-dismiss alerts after $duration seconds.
     * Pass null to disable auto-dismiss.
     *
     * The fail-fast counterpart of withMaxConcurrent()'s cap-below-1 and
     * withHistoryLimit()'s negative-argument refusals: a zero or negative
     * timer silently births every queued alert already expired — nothing
     * renders, nothing errors, and secondsUntilNextExpiry() pins at 0.0
     * for a host ticking on it. NAN/INF never expire anything honestly.
     *
     * @throws \InvalidArgumentException when $seconds is set but not a finite
     *         positive duration (null = never expires; positive seconds = TTL)
     */
    public function withDuration(?float $seconds): self
    {
        if ($seconds !== null && (!\is_finite($seconds) || $seconds <= 0)) {
            throw new \InvalidArgumentException(
                'withDuration(): null = never expires; positive seconds = TTL — '
                . "NAN/INF and values <= 0 are refused (a zero/negative timer silently "
                . "suppresses every alert), got {$seconds}"
            );
        }
        return $this->mutate(['duration' => $seconds]);
    }

    /**
     * Set the allowEscToClose preference flag.
     *
     * This is a host-consumed flag — the renderer stores the preference and
     * exposes it via allowEscToClose() so a host's key handler can decide
     * whether Escape dismisses. The library itself does not handle input.
     */
    public function withAllowEscToClose(bool $allow): self
    {
        return $this->mutate(['allowEscToClose' => $allow]);
    }

    /**
     * Opt into a text channel for information the frame otherwise carries
     * ONLY as colour, glyph, or block-run length:
     * the severity word (`Warning`, `Error`, … via `ToastType::label()`, so
     * it follows the active locale) joins the header, and progress rows
     * print the numeric percentage beside the bar. Default OFF — the plain
     * renderer's byte output is unchanged for every existing host.
     */
    public function withAccessibleText(bool $on = true): self
    {
        return $this->mutate(['accessibleText' => $on]);
    }

    /**
     * Opt in/out of carrying host zone markers through the composite.
     *
     * Default ON is today's published byte shape (see the $hostZoneCarry
     * property); OFF trades zone-span fidelity for unbroken box chrome.
     */
    public function withHostZoneCarry(bool $on = true): self
    {
        return $this->mutate(['hostZoneCarry' => $on]);
    }

    /** Returns the accessible-text preference flag (see {@see withAccessibleText()}). */
    public function accessibleText(): bool
    {
        return $this->accessibleText;
    }

    /**
     * Returns the allowEscToClose preference flag.
     *
     * Host code can read this to determine whether the user has requested
     * Escape-to-dismiss behavior. The renderer itself does not act on it.
     */
    public function allowEscToClose(): bool
    {
        return $this->allowEscToClose;
    }

    /**
     * Set the maximum number of concurrent alerts.
     * Pass null for unlimited.
     *
     * A cap below 1 is refused: it would silence the entire notification
     * system with no error — the fail-fast counterpart
     * of withHistoryLimit()'s negative-argument throw.
     *
     * @throws \InvalidArgumentException when $max is set but smaller than 1
     */
    public function withMaxConcurrent(?int $max): self
    {
        if ($max !== null && $max < 1) {
            throw new \InvalidArgumentException(
                "withMaxConcurrent() expects a cap >= 1 or null for unlimited; got {$max} — "
                . 'a zero/negative cap silently suppresses every alert'
            );
        }
        return $this->mutate(['maxConcurrent' => $max]);
    }

    /**
     * Set the overflow strategy when maxConcurrent is exceeded.
     */
    public function withOverflow(Overflow $overflow): self
    {
        return $this->mutate(['overflow' => $overflow]);
    }

    /**
     * Cap the history log at $limit entries, evicting the oldest first
     * (the Overflow::DropOldest policy applied to history). The default is
     * 100; pass null to keep every dismissed alert unbounded.
     *
     * @throws \InvalidArgumentException when $limit is negative
     */
    public function withHistoryLimit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException(
                "History limit must be >= 0 or null (unbounded), got {$limit}"
            );
        }
        return $this->mutate(['historyLimit' => $limit]);
    }

    /**
     * Create a new instance with the given changes merged in.
     *
     * Mirrors the Mutable trait pattern from sugar-core but avoids requiring
     * readonly fields or constructor-param-only initialization.
     */
    private function mutate(array $changes): self
    {
        $clone = clone $this;
        foreach ($changes as $k => $v) {
            $clone->{$k} = $v;
        }
        return $clone;
    }

    /**
     * Fresh state is a fresh frame: every door that clones (mutate() or the
     * raw queue-arrival clones) sheds the render memo in this one choke, so
     * no clone can ever serve bytes computed from its parent's queue.
     */
    private function __clone()
    {
        $this->frameMemo = null;
    }

    // -------------------------------------------------------------------------
    // Alert operations
    // -------------------------------------------------------------------------

    /**
     * Add an alert to the queue. Returns a new Toast instance.
     *
     * If $expiresAt is provided, it overrides the configured duration; when it
     * is left null the alert INHERITS withDuration() — a null $expiresAt is
     * "use the default", not "never expire". For a persistent alert keep the
     * toast's duration unset (the default, or withDuration(null)), queue a
     * ->withCancelledExpiry() Alert via push() (that sets the explicit persistent
     * sentinel, which the inheritance door honours),
     * or call cancelAlert() on a queued alert. Accepts a ToastType or a
     * string type name (case-insensitive).
     *
     * The message text is app-supplied: inline SGR sequences in it are honoured
     * as styling (re-encoded through the cell grid), but raw control bytes can
     * never reach the output — action labels obey the same law, normalised at
     * Action construction (only inline SGR survives there) — see the README
     * "Security & Trust Boundary" section for the host-side Sanitize contract.
     *
     * When maxConcurrent is set and the queue would exceed it, applies
     * the configured overflow strategy (DropOldest, DropNewest, or Enqueue).
     * Expired alerts sitting in the queue are pruned first, on this write.
     *
     * @param string|null $id  Stable identity: re-queuing with the same id
     *        REPLACES the live alert in place instead of appending a second
     *        box (progress/feedback toasts; see progressToast()).
     * @param list<Action>|null $actions  Clickable action buttons to render beneath the message
     * @param float|null $ttlSeconds  Relative lifetime resolved AT THIS DOOR
     *        into an absolute epoch ($expiresAt = microtime(true) + $ttlSeconds)
     *        — mutually exclusive with $expiresAt (supplying both throws); the
     *        resolved instant rides the same epoch guards as a hand-passed one,
     *        so a non-finite $ttlSeconds is refused rather than becoming an
     *        immortal or born-expired alert.
     * @throws \InvalidArgumentException when $expiresAt is not a finite
     *         absolute epoch — NAN/INF never expire, and a duration-shaped
     *         value below the epoch floor is a silently invisible alert
     *         otherwise — or when $expiresAt and $ttlSeconds are both supplied.
     * @throws \LogicException when the toast has been dismissed — call clear() to resume
     */
    public function alert(ToastType|string $type, string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null): self
    {
        if ($expiresAt !== null && $ttlSeconds !== null) {
            throw new \InvalidArgumentException(
                'alert() $expiresAt and $ttlSeconds are mutually exclusive — an absolute epoch and a '
                . 'relative lifetime name the same instant; supply exactly one'
            );
        }
        if ($expiresAt !== null && (!\is_finite($expiresAt) || $expiresAt < Alert::EPOCH_FLOOR)) {
            throw new \InvalidArgumentException('alert() $expiresAt ' . Alert::EXPIRY_RULE . $expiresAt);
        }
        if ($ttlSeconds !== null) {
            $expiresAt = \microtime(true) + $ttlSeconds;
        }
        $resolvedType = $type instanceof ToastType
            ? $type
            : ToastType::tryFrom(\strtolower($type))
                ?? throw new \InvalidArgumentException("Unknown toast type: {$type}");

        $clone = clone $this;
        $alert = new Alert($resolvedType, $message, $expiresAt, null, $actions ?? [], id: $id);
        if ($expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        $clone->queue = $clone->appendBounded($clone->queue, $alert);
        return $clone;
    }

    /**
     * Add a progress toast — renders a progress bar beneath the message.
     *
     * @param float $progress  Value between 0.0 and 1.0 (clamped)
     * @param list<Action>|null $actions  Clickable action buttons to render beneath the progress bar
     * @param float|null $ttlSeconds  Relative lifetime resolved at this door;
     *        mutually exclusive with $expiresAt (see alert())
     * @throws \InvalidArgumentException when $expiresAt is not a finite absolute
     *         epoch (NAN/INF, or a duration-shaped value below the floor),
     *         when $expiresAt and $ttlSeconds are both supplied,
     *         or $progress is non-finite (it reaches the Alert constructor)
     * @throws \LogicException when the toast has been dismissed — call clear() to resume
     */
    public function progressToast(ToastType|string $type, string $message, float $progress, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null): self
    {
        if ($expiresAt !== null && $ttlSeconds !== null) {
            throw new \InvalidArgumentException(
                'progressToast() $expiresAt and $ttlSeconds are mutually exclusive — an absolute epoch and a '
                . 'relative lifetime name the same instant; supply exactly one'
            );
        }
        if ($expiresAt !== null && (!\is_finite($expiresAt) || $expiresAt < Alert::EPOCH_FLOOR)) {
            throw new \InvalidArgumentException('progressToast() $expiresAt ' . Alert::EXPIRY_RULE . $expiresAt);
        }
        if ($ttlSeconds !== null) {
            $expiresAt = \microtime(true) + $ttlSeconds;
        }
        $resolvedType = $type instanceof ToastType
            ? $type
            : ToastType::tryFrom(\strtolower($type))
                ?? throw new \InvalidArgumentException("Unknown toast type: {$type}");

        $clone = clone $this;
        $alert = (new Alert($resolvedType, $message, $expiresAt, null, $actions ?? [], id: $id))->withProgress($progress);
        if ($expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        $clone->queue = $clone->appendBounded($clone->queue, $alert);
        return $clone;
    }

    /**
     * Enqueue a pre-built (optionally styled) Alert.
     *
     * Companion to {@see alert()} for callers that construct an Alert
     * directly — e.g. to attach background/foreground/border colours via
     * {@see Alert::withBackgroundColor()} / {@see Alert::withForegroundColor()}
     * / {@see Alert::withBorderColor()}. Honours the configured duration
     * (when the alert carries no expiry of its own), the expired-on-write
     * prune, and the maxConcurrent/overflow policy, exactly like {@see alert()}.
     *
     * @throws \LogicException when the toast has been dismissed — call clear() to resume
     */
    public function push(Alert $alert): self
    {
        $clone = clone $this;

        if ($alert->expiresAt === null && !$alert->persistent && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        $clone->queue = $clone->appendBounded($clone->queue, $alert);
        return $clone;
    }

    /**
     * Append an alert to a queue copy honouring the maxConcurrent/overflow
     * policy — the shared engine behind {@see alert()}, {@see progressToast()}
     * and {@see push()}.
     *
     * Expired alerts are pruned from the queue first, on this write, so a
     * long-lived instance cannot accumulate dead cruft between view() calls.
     * A dismissed instance refuses new writes outright rather than queueing
     * into a renderer that will not paint them — clear() revives first.
     *
     * DropNewest discards the INCOMING alert AND files it in history — it
     * was never displayed, so the history row is its only trace and the
     * message never vanishes silently; DropOldest evicts
     * the oldest queued alert AND records the evictee in history, since it
     * lived in the queue and may have been on screen. At a zero cap there is
     * nothing to evict, so a DropOldest arrival is discarded unrecorded
     * rather than letting the queue exceed the cap (withMaxConcurrent() now refuses
     * caps below 1 — the leg is defensive depth for the shared engine).
     * An alert carrying an id that matches a live alert bypasses all policy:
     * it is an update, not an append (see the replace-by-id leg).
     * Enqueue lets the queue grow past the cap (DropOldest is the default).
     *
     * @param list<Alert> $queue
     * @return list<Alert>
     * @throws \LogicException when the toast has been dismissed
     */
    private function appendBounded(array $queue, Alert $alert): array
    {
        if ($this->dismissed) {
            throw new \LogicException(
                'Toast has been dismissed; call clear() before queueing new alerts.'
            );
        }

        // Bounded-queue law: expiry leaves on the next write, not only on
        // view() — otherwise maxConcurrent=null instances grow forever.
        $queue = \array_values(
            \array_filter($queue, fn(Alert $a): bool => !$a->isExpired())
        );

        // Update-in-place door: an alert carrying an
        // id REPLACES the live alert with the same id, at that position —
        // progress ticks must not leak a new box per frame, and a replacement
        // must not consume (or evict) a maxConcurrent slot. It rides no
        // overflow policy: the queue length is unchanged by definition.
        if ($alert->id !== null) {
            foreach ($queue as $position => $existing) {
                if ($existing->id === $alert->id) {
                    $queue[$position] = $alert;
                    return $queue;
                }
            }
        }

        if ($this->maxConcurrent !== null && \count($queue) >= $this->maxConcurrent) {
            if ($this->overflow === Overflow::DropNewest) {
                // The refused arrival was never displayed — history is its ONLY
                // trace. Filing it keeps the guarantee "no message vanishes
                // silently" symmetric with the DropOldest eviction below; a
                // host can announce over-cap arrivals without re-implementing
                // the cap check.
                $this->historyLog = $this->historyLog->push($alert, $this->historyLimit);
                return $queue;
            }
            if ($this->overflow === Overflow::DropOldest) {
                if ($queue === []) {
                    return $queue;
                }
                $victim = \array_shift($queue);
                // The evicted alert was live (queued, possibly rendered) and
                // dies silently at the cap — file it in history like any
                // dismissal so a host can still recover its text — before
                // this, only dismiss() recorded and a cap eviction
                // vanished unfiled.
                $this->historyLog = $this->historyLog->push($victim, $this->historyLimit);
            }
            // Enqueue: fall through, allow exceeding max
        }
        $queue[] = $alert;
        return $queue;
    }

    /**
     * Convenience: show an error alert. Carries the full alert() tail
     * ($expiresAt/$actions/$id/$ttlSeconds, delegated verbatim) — a shortcut
     * once took only the message, so extra positional arguments were silently
     * dropped by PHP: the buttons and id a caller believed attached existed
     * nowhere.
     *
     * @param list<Action>|null $actions
     */
    public function error(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null): self
    {
        return $this->alert(ToastType::Error, $message, $expiresAt, $actions, $id, $ttlSeconds);
    }

    /**
     * Convenience: show a warning alert (full alert() tail, see error()).
     *
     * @param list<Action>|null $actions
     */
    public function warning(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null): self
    {
        return $this->alert(ToastType::Warning, $message, $expiresAt, $actions, $id, $ttlSeconds);
    }

    /**
     * Convenience: show an info alert (full alert() tail, see error()).
     *
     * @param list<Action>|null $actions
     */
    public function info(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null): self
    {
        return $this->alert(ToastType::Info, $message, $expiresAt, $actions, $id, $ttlSeconds);
    }

    /**
     * Convenience: show a success alert (full alert() tail, see error()).
     *
     * @param list<Action>|null $actions
     */
    public function success(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null): self
    {
        return $this->alert(ToastType::Success, $message, $expiresAt, $actions, $id, $ttlSeconds);
    }

    /**
     * Stop rendering and file EVERY queued alert into the history log.
     *
     * Dismiss is a MOVE, not a copy: queued alerts leave the queue —
     * including ones that expired but had not been pruned yet (they were
     * live from the host's point of view, and dismiss() is the recovery
     * door) — so a second dismiss() can neither
     * double-count history nor find leftovers. Rendering stays stopped until
     * the host calls {@see clear()} — alert() refuses meanwhile — and clear()
     * resets the flag.
     */
    public function dismiss(): self
    {
        if ($this->dismissed) {
            return $this;  // idempotent: already stopped, nothing left to move
        }

        $clone = clone $this;

        // Move EVERY queued alert to history, then empty the queue —
        // including alerts that expired but had not been pruned yet: they
        // were live from the host's point of view (queued, possibly rendered)
        // and dismiss() is the recovery door — the old expired filter gave
        // transient info ZERO recovery on the default path, a WCAG 2.2.1
        // by-default failure.
        foreach ($clone->queue as $alert) {
            $clone->historyLog = $clone->historyLog->push($alert, $clone->historyLimit);
        }
        $clone->queue = [];

        $clone->dismissed = true;
        return $clone;
    }

    /**
     * Remove expired alerts and return a new Toast.
     */
    public function pruneExpired(): self
    {
        $clone = clone $this;
        $clone->queue = \array_values(
            \array_filter($clone->queue, fn(Alert $a): bool => !$a->isExpired())
        );
        return $clone;
    }

    /**
     * Clear the entire queue and reset the dismissed flag, resuming
     * rendering — the documented revival path after {@see dismiss()}.
     *
     * Survivors are DISCARDED, not dismissed: nothing is filed under
     * {@see history()}. A host that wants the audit trail (or the recovery
     * path) uses dismiss(); clear() is the reset door.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->queue = [];
        $clone->dismissed = false;
        return $clone;
    }

    /**
     * Remove the queued alert carrying $id (first match), WITHOUT recording
     * it in history — this is the lifecycle door for updates and cancellations
     * the host performs deliberately (a finished download's progress box
     * disappearing is not a dismissal). The companion of the replace-by-id
     * leg in {@see appendBounded()}: before it existed, the
     * only way to retire an alert before expiry was dismiss(), which nukes
     * the whole queue and files every survivor under history).
     *
     * Returns an identical instance when no queued alert carries $id.
     */
    public function removeAlert(string $id): self
    {
        $queue = [];
        $removed = false;
        foreach ($this->queue as $alert) {
            if (!$removed && $alert->id === $id) {
                $removed = true;
                continue;
            }
            $queue[] = $alert;
        }
        if (!$removed) {
            return $this;
        }
        return $this->mutate(['queue' => $queue]);
    }

    /**
     * Map a host-visible {@see activeAlerts()} index onto the raw queue
     * offset: an expired entry in the raw queue is invisible to the host,
     * so counting live alerts is the only mapping that can cancel the alert
     * the host believes it addressed (cancelAlert()/extendAlert() once
     * indexed the raw array, so an expired entry at slot 0 silently shifted
     * every later index and cancelled the WRONG alert).
     * Null when the index names no live alert.
     */
    private function queuedIndexAt(int $index): ?int
    {
        if ($index < 0) {
            return null;
        }
        $seen = 0;
        foreach ($this->queue as $offset => $alert) {
            if ($alert->isExpired()) {
                continue;
            }
            if ($seen === $index) {
                return (int) $offset;
            }
            $seen++;
        }
        return null;
    }

    /**
     * Cancel the expiry on the alert at $index, making it persistent —
     * $index addresses {@see activeAlerts()} (the live queue, not the raw
     * array), and the change goes through the explicit sentinel {@see
     * Alert::withCancelledExpiry()}. Has no effect if $index is out of bounds or
     * the alert is already persistent.
     */
    public function cancelAlert(int $index): self
    {
        $target = $this->queuedIndexAt($index);
        if ($target === null) {
            return $this;
        }
        $queue = $this->queue;
        $queue[$target] = $queue[$target]->withCancelledExpiry();
        return $this->mutate(['queue' => $queue]);
    }

    /**
     * Re-arm the auto-dismiss timer on the alert at $index (a
     * {@see activeAlerts()} index — the live queue) to $additionalSeconds
     * from now. This is a reset, not a push-back: extending an alert that
     * still has 30 s left by 5 s leaves it with 5 s. Has no effect if $index
     * is out of bounds or the alert never expires — a persistent alert has
     * no timer to extend (mirrors {@see extendAll()}).
     */
    public function extendAlert(int $index, float $additionalSeconds): self
    {
        $target = $this->queuedIndexAt($index);
        if ($target === null) {
            return $this;
        }
        $alert = $this->queue[$target];
        if ($alert->expiresAt === null) {
            return $this;
        }
        $queue = $this->queue;
        $queue[$target] = $alert->withExtendedExpiry($additionalSeconds);
        return $this->mutate(['queue' => $queue]);
    }

    /**
     * Re-arm the auto-dismiss timer on every expiring alert to
     * $additionalSeconds from now (a reset — see {@see extendAlert()}).
     * Only affects alerts that have an expiry; non-expiring alerts are unchanged.
     */
    public function extendAll(float $additionalSeconds): self
    {
        $queue = \array_map(
            fn(Alert $a): Alert => $a->expiresAt !== null ? $a->withExtendedExpiry($additionalSeconds) : $a,
            $this->queue,
        );
        return $this->mutate(['queue' => $queue]);
    }

    /**
     * Returns true if there are active (non-expired) alerts in the queue.
     */
    public function hasActiveAlert(): bool
    {
        foreach ($this->queue as $alert) {
            if (!$alert->isExpired()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The soonest expiry instant (seconds since epoch) among the queued alerts
     * that auto-dismiss, or null when no queued alert has an expiry.
     *
     * This is the loop-integration primitive: instead of polling
     * {@see pruneExpired()} on a fixed interval, a TEA / event-loop host can
     * schedule ONE timer to fire at this instant, prune, then reschedule. The
     * value may be in the past if an alert is already due for pruning (the host
     * should prune immediately in that case) — see {@see secondsUntilNextExpiry()}
     * for a clamped, ready-to-schedule delay.
     */
    public function nextExpiry(): ?float
    {
        $soonest = null;
        foreach ($this->queue as $alert) {
            if ($alert->expiresAt === null) {
                continue;
            }
            if ($soonest === null || $alert->expiresAt < $soonest) {
                $soonest = $alert->expiresAt;
            }
        }
        return $soonest;
    }

    /**
     * Seconds from now until the next alert expires, clamped to >= 0.0 (an
     * already-due alert yields 0.0 → prune now), or null when no queued alert
     * auto-dismisses. Convenience for scheduling a single prune tick, e.g.
     * `Cmd::tick($toast->secondsUntilNextExpiry() ?? $idle, …)`.
     */
    public function secondsUntilNextExpiry(): ?float
    {
        $at = $this->nextExpiry();
        if ($at === null) {
            return null;
        }
        return \max(0.0, $at - \microtime(true));
    }

    /**
     * Return the history of dismissed alerts.
     *
     * Bare accessor per project convention (no `get` prefix).
     *
     * @return list<Alert>
     */
    public function history(): array
    {
        return $this->historyLog->all();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the toast layer composited over a background view using
     * Buffer-based composition.
     *
     * Lowercase `view()` per the project renderer convention; PHP method
     * dispatch is case-insensitive, so the legacy `View()` call sites in
     * sugar-dashboard/candy-sql keep resolving here without an alias.
     *
     * @param string $background  The underlying viewport content
     * @param int $viewportWidth  Viewport width in cells
     * @param int $viewportHeight Viewport height in lines
     * @return string  The composited output
     *
     * @throws \InvalidArgumentException when either viewport dimension is
     *         outside 1..self::MAX_VIEWPORT_DIMENSION, or the product exceeds
     *         self::MAX_VIEWPORT_CELLS (fail-fast: the
     *         frame allocates width*height cells eagerly)
     */
    public function view(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string
    {
        return $this->renderLayout($background, $viewportWidth, $viewportHeight)['output'];
    }

    /**
     * The single layout path: one layout() pass per (background, width,
     * height) triple, memoized on the instance until any state mutates.
     * view(), frame() and the hit-test doors all read through here, so the
     * bytes on screen and the rects describing them can never come from two
     * different layout passes.
     *
     * @return array{key:array{string,int,int},layout:?array,output:string,frame:?ToastFrame}
     */
    private function renderLayout(string $background, int $viewportWidth, int $viewportHeight): array
    {
        $memo = $this->frameMemo;
        if ($memo !== null
            && $memo['key'][0] === $background
            && $memo['key'][1] === $viewportWidth
            && $memo['key'][2] === $viewportHeight) {
            return $memo;
        }

        $layout = $this->layout($background, $viewportWidth, $viewportHeight);
        $output = $layout === null ? $background : $this->composite($layout);

        return $this->frameMemo = [
            'key' => [$background, $viewportWidth, $viewportHeight],
            'layout' => $layout,
            'output' => $output,
            'frame' => null,
        ];
    }

    /**
     * Blit the placed boxes over the filled background grid from layout().
     *
     * @param array $layout
     */
    private function composite(array $layout): string
    {
        $viewport = $layout['viewport'];
        $bgGrid = $viewport;

        // Region spans exactly the box, so nothing right of a narrow box is
        // overwritten with blanks; geometry comes from the shared layout()
        // pass — the same numbers actionRects() reports.
        foreach ($layout['boxes'] as $placed) {
            $box = $placed['buffer'];
            $region = new Region(BufferPosition::new($placed['x'], $placed['y']), $box->width(), $box->height());
            $viewport = $viewport->withRegion($region, $box);
            if ($this->hostZoneCarry) {
                $viewport = $this->carryHostZoneMarkers($viewport, $bgGrid, $placed['x'], $placed['y'], $box->width(), $box->height());
            }
        }

        return $viewport->toAnsi();
    }

    /**
     * Resolve optional frame arguments for the hit-test doors against the
     * last-rendered frame (finding 001). Omitted parts read the memo; with
     * nothing rendered yet the legacy ('', 80, 24) defaults apply. Supplied
     * parts that DIFFER from the memo throw \LogicException — a hit-test
     * describing a screen nobody rendered is worse than a loud crash.
     *
     * @return array{string,int,int}
     */
    private function resolveFrameArgs(string $door, ?string $background, ?int $viewportWidth, ?int $viewportHeight): array
    {
        $memo = $this->frameMemo;
        $bg = $background ?? ($memo === null ? '' : $memo['key'][0]);
        $w = $viewportWidth ?? ($memo === null ? 80 : $memo['key'][1]);
        $h = $viewportHeight ?? ($memo === null ? 24 : $memo['key'][2]);
        if ($memo !== null
            && ($background !== null || $viewportWidth !== null || $viewportHeight !== null)
            && ($bg !== $memo['key'][0] || $w !== $memo['key'][1] || $h !== $memo['key'][2])) {
            throw new \LogicException(\sprintf(
                '%s(): asked about %dx%d over %s, but the last view() rendered %dx%d over %s — hit-test the frame you drew, or omit the arguments to read the memo',
                $door,
                $w,
                $h,
                $bg === '' ? 'an empty background' : 'a marked background',
                $memo['key'][1],
                $memo['key'][2],
                $memo['key'][0] === '' ? 'an empty background' : 'a marked background'
            ));
        }
        return [$bg, $w, $h];
    }

    /**
     * Carry the host's sugar-mouse zone sentinels THROUGH the opaque blit.
     *
     * The composite replaced a rectangle without knowing what lived there.
     * A marked-frame host (zones marked U+E000 OPEN .. payload .. U+E001
     * CLOSE) previously lost every marker the box covered — and, worse, lost
     * ONE half of a pair, tearing the frame: an open-without-close row makes
     * every later zone on that row mis-route — tearing a marked host frame
     * was the common path, not an edge case.
     *
     * The rule is row-local and pairing-first: on each box row, ORIGINAL
     * background cells inside every OPEN..CLOSE span (payload included) are
     * restored over the box chrome; a dangling span extends to the row edge,
     * which is where the partner half already lived. The visible cost is
     * deliberate: where a host zone ran under the toast, the box shows a hole
     * instead of chrome. A click map that silently mis-pairs is a worse UI
     * than a chipped border — and the review's alternative (declare
     * marked-frame hosts unsupported) stayed the documented fallback only if
     * this carry-through ever regresses.
     *
     * Sentinels inside the TOAST's own message text are untouched: they pass
     * through as ordinary printable PUA glyphs exactly as the trust boundary
     * documents, and this method only ever restores ORIGINAL background
     * cells, never the box's.
     */
    private function carryHostZoneMarkers(Buffer $viewport, Buffer $bg, int $x, int $y, int $w, int $h): Buffer
    {
        $frameWidth = $bg->width();
        for ($row = $y; $row < $y + $h && $row < $bg->height(); $row++) {
            $protect = [];
            $openCol = null;
            for ($col = 0; $col < $frameWidth; $col++) {
                $rune = $bg->cellAt($col, $row)->rune();
                if ($rune === self::ZONE_OPEN) {
                    if ($openCol === null) {
                        $openCol = $col;
                    }
                    $protect[$col] = true;
                } elseif ($rune === self::ZONE_CLOSE) {
                    if ($openCol !== null) {
                        for ($c = $openCol; $c <= $col; $c++) {
                            $protect[$c] = true;
                        }
                        $openCol = null;
                    } else {
                        for ($c = 0; $c <= $col; $c++) {
                            $protect[$c] = true;
                        }
                    }
                } elseif ($openCol !== null) {
                    $protect[$col] = true;
                }
            }
            if ($openCol !== null) {
                for ($c = $openCol; $c < $frameWidth; $c++) {
                    $protect[$c] = true;
                }
            }
            if ($protect === []) {
                continue;
            }
            for ($col = $x; $col < $x + $w && $col < $frameWidth; $col++) {
                if (isset($protect[$col])) {
                    $viewport = $viewport->withCellAt($col, $row, $bg->cellAt($col, $row));
                }
            }
        }
        return $viewport;
    }

    /**
     * Env truthiness in the fleet idiom (sugar-crush palette short-circuit,
     * aa8955d75 lineage): a variable suppresses only when SET to something
     * other than the empty string or "0". The pure NO_COLOR spec treats any
     * non-empty value — including "0" — as suppression; the divergence is
     * deliberate and fleet-consistent.
     */
    private static function envTruthy(string $name): bool
    {
        $value = \getenv($name);
        return $value !== false && $value !== '' && $value !== '0';
    }

    /**
     * WCAG 2.2.2 reduced-motion preference. Read per
     * render, not cached: like sugar-spring's Spring.php the environment is
     * the living contract and a host may flip it between frames.
     */
    private function reducedMotionPreferred(): bool
    {
        return self::envTruthy('REDUCE_MOTION') || self::envTruthy('PREFERS_REDUCED_MOTION');
    }

    /**
     * Colour-suppression request (the fleet-wide NO_COLOR /
     * CLICOLOR family). Checked ENV-ONLY, never against a TTY: sugar-toast
     * is a compositing library — it does not print, its HOST does, so probing
     * this process's own stdout would query the wrong terminal entirely.
     * CLICOLOR_FORCE wins over NO_COLOR; NO_COLOR suppresses; CLICOLOR=0
     * suppresses.
     */
    private function coloursSuppressed(): bool
    {
        if (self::envTruthy('CLICOLOR_FORCE')) {
            return false;
        }
        if (self::envTruthy('NO_COLOR')) {
            return true;
        }
        return \getenv('CLICOLOR') === '0';
    }

    /**
     * Shared geometry pass behind {@see view()}, {@see actionRects()} and
     * {@see actionAt()} — the ONE place the box stack is computed, so the
     * pixels and the hit-test can never disagree — actions are dispatchable
     * at all only because this exports their geometry; they once shipped as
     * label+closure with nothing for a host to hit-test against.
     *
     * Layout ONCE: each box is rendered straight to its Buffer and its
     * height feeds both the frame growth and the placement pass, so the two
     * passes can no longer disagree about the coordinate system.
     *
     * The frame is the VIEWPORT canvas, not the box width: every background
     * column survives; only the alert box is capped at maxWidth (via
     * renderAlert's $capWidth), and top-stacked alerts may grow the frame
     * downward.
     *
     * xOffset()/yOffset() contract: the extra argument is the cumulative
     * height of the boxes placed BEFORE this one (top families stack
     * downward from it, bottom/middle stack upward against it), and the
     * box's REAL width is passed to xOffset so centre/right offsets become
     * non-zero.
     *
     * @return null|array{viewport:Buffer, boxes:list<array{alert:Alert,buffer:Buffer,x:int,y:int}>,frameWidth:int,contentHeight:int}
     *         null when nothing renders (dismissed / empty / all-expired)
     */
    private function layout(string $background, int $viewportWidth, int $viewportHeight): ?array
    {
        if ($viewportWidth < 1 || $viewportWidth > self::MAX_VIEWPORT_DIMENSION) {
            throw new \InvalidArgumentException(\sprintf(
                'viewportWidth %d is outside the supported range 1..%d (sugar-toast::MAX_VIEWPORT_DIMENSION)',
                $viewportWidth,
                self::MAX_VIEWPORT_DIMENSION
            ));
        }
        if ($viewportHeight < 1 || $viewportHeight > self::MAX_VIEWPORT_DIMENSION) {
            throw new \InvalidArgumentException(\sprintf(
                'viewportHeight %d is outside the supported range 1..%d (sugar-toast::MAX_VIEWPORT_DIMENSION)',
                $viewportHeight,
                self::MAX_VIEWPORT_DIMENSION
            ));
        }
        if ($viewportWidth * $viewportHeight > self::MAX_VIEWPORT_CELLS) {
            throw new \InvalidArgumentException(\sprintf(
                'viewport %dx%d (%d cells) exceeds the %d-cell product cap (sugar-toast::MAX_VIEWPORT_CELLS)',
                $viewportWidth,
                $viewportHeight,
                $viewportWidth * $viewportHeight,
                self::MAX_VIEWPORT_CELLS
            ));
        }

        if ($this->dismissed || $this->queue === []) {
            return null;
        }
        $active = $this->activeAlerts();
        if ($active === []) {
            return null;
        }

        $bgLines = $this->splitLines($background);
        // TST-ENVPERF: the env predicates are evaluated ONCE for this render
        // and threaded through the scan/apply helpers ([c0]=colour-suppression,
        // [c1]=reduced-motion). The docblock's "read per render, not cached"
        // cadence holds — every call below happens inside this single pass;
        // the NEXT view() re-reads, so a host flipping NO_COLOR between frames
        // heals on the next frame exactly as before.
        $envCtx = [$this->coloursSuppressed(), $this->reducedMotionPreferred()];
        $boxes = [];
        $stackHeight = 0;
        foreach ($active as $alert) {
            $box = $this->renderAlertToBuffer($alert, $viewportWidth, $envCtx);
            $boxes[] = ['alert' => $alert, 'buffer' => $box, 'x' => 0, 'y' => 0];
            $stackHeight += $box->height();
        }

        $frameWidth = max(1, $viewportWidth);
        $contentHeight = max(max(\count($bgLines), $viewportHeight), $stackHeight);

        $stackedBefore = 0;
        foreach ($boxes as $index => $placed) {
            $box = $placed['buffer'];
            $boxes[$index]['x'] = $this->position->xOffsetClamped($box->width(), $frameWidth);
            $boxes[$index]['y'] = max(0, $this->position->yOffset($box->height(), $contentHeight, $stackedBefore));
            $stackedBefore += $box->height();
        }

        $viewport = Buffer::new($frameWidth, $contentHeight);
        $viewport = $this->fillViewportFromString($viewport, $bgLines, $envCtx);

        return [
            'viewport' => $viewport,
            'boxes' => $boxes,
            'frameWidth' => $frameWidth,
            'contentHeight' => $contentHeight,
        ];
    }

    /**
     * The live queue exactly as {@see view()} sees it: queue order, expired
     * alerts filtered: before this accessor existed, cancelAlert() and
     * extendAlert() indexed a RAW queue the host could not observe, so a
     * stale expired entry silently shifted every later index.
     *
     * @return list<Alert>
     */
    public function activeAlerts(): array
    {
        return \array_values(
            \array_filter($this->queue, fn(Alert $a): bool => !$a->isExpired())
        );
    }

    /**
     * Hit-test rectangles for every rendered action button, in the same
     * coordinate system {@see view()} composites in (absolute frame rows /
     * columns, 0-based). The layout is RECOMPUTED from the arguments given —
     * there is no saved last-frame — over a frame of max(background lines,
     * viewportHeight, total stack height): a host that painted with a
     * non-empty $background must pass the same three arguments here, or the
     * default ''/80/24 assumes a shorter frame and every bottom/middle row
     * shifts out of register with the screen. Zone-neutral by design:
     * sugar-toast carries no
     * sugar-mouse dependency and forges no zone sentinels — hosts map their
     * own mouse events through actionAt() (or drive keyboards from this same
     * surface) and invoke the returned Action's callback themselves.
     *
     * @return list<array{action:Action, alert:Alert, alertIndex:int, actionIndex:int, row:int, col:int, width:int}>
     */
    public function actionRects(?string $background = null, ?int $viewportWidth = null, ?int $viewportHeight = null): array
    {
        [$bg, $w, $h] = $this->resolveFrameArgs(__FUNCTION__, $background, $viewportWidth, $viewportHeight);
        return $this->frame($bg, $w, $h)?->actionRects() ?? [];
    }

    /**
     * The frame currently on screen (or the one the arguments describe) as a
     * {@see ToastFrame}: composed bytes, top-box origin, and hit rects as
     * data. null when nothing renders. Optional arguments resolve against
     * the last render's memo exactly like {@see actionRects()}.
     */
    public function frame(?string $background = null, ?int $viewportWidth = null, ?int $viewportHeight = null): ?ToastFrame
    {
        [$bg, $w, $h] = $this->resolveFrameArgs(__FUNCTION__, $background, $viewportWidth, $viewportHeight);
        $memo = $this->renderLayout($bg, $w, $h);
        if ($memo['layout'] === null) {
            return null;
        }
        if ($memo['frame'] === null) {
            $boxes = $memo['layout']['boxes'];
            $top = $boxes[\count($boxes) - 1];
            $x0 = PHP_INT_MAX;
            $y0 = PHP_INT_MAX;
            $x1 = 0;
            $y1 = 0;
            foreach ($boxes as $placed) {
                $x0 = \min($x0, $placed['x']);
                $y0 = \min($y0, $placed['y']);
                $x1 = \max($x1, $placed['x'] + $placed['buffer']->width());
                $y1 = \max($y1, $placed['y'] + $placed['buffer']->height());
            }
            $memo['frame'] = new ToastFrame(
                $top['x'],
                $top['y'],
                $memo['output'],
                $this->computeRects($memo['layout']),
                ['x' => $x0, 'y' => $y0, 'width' => $x1 - $x0, 'height' => $y1 - $y0],
            );
            $this->frameMemo = $memo;
        }
        return $memo['frame'];
    }

    /**
     * @param array $layout
     *
     * @return list<array{action:Action,alert:Alert,alertIndex:int,actionIndex:int,row:int,col:int,width:int}>
     */
    private function computeRects(array $layout): array
    {
        $rects = [];
        foreach ($layout['boxes'] as $alertIndex => $placed) {
            $alert = $placed['alert'];
            $count = \count($alert->actions);
            if ($count === 0) {
                continue;
            }
            $boxWidth = $placed['buffer']->width();
            $boxHeight = $placed['buffer']->height();
            $inner = \max(1, $boxWidth - 2);
            $visible = $this->visibleActions($alert);
            $count = \count($visible); // folded buttons are not rects
            foreach ($visible as $actionIndex => $action) {
                // renderAlert() lays actions on the final rows before the
                // closing border (plus a '+N more' fold row when buttons
                // overflow MAX_ACTIONS_PER_ALERT), one action per row, in
                // list order. The clickable span is the LABEL's own cells —
                // the padding after it is background, not button.
                $foldRow = \count($alert->actions) > $count ? 1 : 0;
                $row = $placed['y'] + $boxHeight - 1 - $foldRow - $count + $actionIndex;
                $shown = Ansi::strip(Width::truncateAnsi('[' . $action->label . ']', $inner));
                $rects[] = [
                    'action' => $action,
                    'alert' => $alert,
                    'alertIndex' => $alertIndex,
                    'actionIndex' => $actionIndex,
                    'row' => $row,
                    'col' => $placed['x'] + 1,
                    'width' => \max(1, Width::string($shown)),
                ];
            }
        }
        return $rects;
    }

    /**
     * Which Action (if any) covers frame position ($col, $row) of the frame
     * the host is looking at? Omitted arguments read the last-rendered frame;
     * supplied ones that differ from it throw (see {@see actionRects()}).
     * Where two boxes share rows, the top-most (newest) one wins — the
     * visible button is the hittable one. This is the dispatch door the
     * README's host-side mouse/keyboard recipe calls.
     */
    public function actionAt(int $col, int $row, ?string $background = null, ?int $viewportWidth = null, ?int $viewportHeight = null): ?Action
    {
        [$bg, $w, $h] = $this->resolveFrameArgs(__FUNCTION__, $background, $viewportWidth, $viewportHeight);
        return $this->frame($bg, $w, $h)?->actionAt($col, $row);
    }

    /**
     * Consume an OSC/DCS-class string sequence starting at an ESC byte:
     * payload runs to BEL, ST (ESC \), or end-of-input. Unterminated
     * sequences swallow the remainder — fail-closed: framing never survives
     * as printable garbage, at the cost of trailing text on a truncated
     * malformed row (documented, matches the Action label ingress law).
     *
     * @return array{0:string,1:int} payload bytes (without introducer or
     *         terminator) and the new walker offset
     */
    private function consumeStringSequence(string $s, int $start, int $len): array
    {
        $j = $start + 2;
        while ($j < $len) {
            if ($s[$j] === "\x07") {
                return [\substr($s, $start + 2, $j - $start - 2), $j + 1];
            }
            if ($s[$j] === "\x1b" && ($s[$j + 1] ?? '') === '\\') {
                return [\substr($s, $start + 2, $j - $start - 2), $j + 2];
            }
            $j++;
        }
        return [\substr($s, $start + 2), $len];
    }

    /**
     * Map an OSC payload to the running hyperlink state (host-frame
     * ingestion only). '8;<params>;<url>' opens a link (empty url closes
     * the current one, per spec); every other OSC leaves the state
     * unchanged. URLs carrying control bytes cannot build a Hyperlink
     * (cellbuffer refuses them) — the link is discarded, the text stays.
     */
    private function hyperlinkFromOscPayload(string $payload, ?Hyperlink $current): ?Hyperlink
    {
        if (!\str_starts_with($payload, '8;')) {
            return $current;
        }
        $parts = \explode(';', $payload, 3);
        // Standard frames are '8;<params>;<url>'; sugar-cellbuffer's own
        // toAnsi() emits the params-less short form '8;<url>', which must
        // round-trip — a host feeding its previous composed frame back in
        // keeps its links, not silently closing them.
        $url = \end($parts);
        $url = $url === false ? '' : $url;
        if ($url === '') {
            return null;
        }
        $id = '';
        if (\count($parts) === 3 && $parts[1] !== '' && \preg_match('/^id=([\x20-\x7e]*)$/', $parts[1], $m) === 1) {
            $id = $m[1];
        }
        try {
            return Hyperlink::new($url, $id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Fill a viewport Buffer with content from an array of strings.
     * Each line is placed with ANSI parsing for styles.
     */
    private function fillViewportFromString(Buffer $buf, array $lines, ?array $envCtx = null): Buffer
    {
        // The host frame arrives as one flat grid built from single
        // scanLine passes — the old row-by-row place chain was the same
        // cell-clone amplifier as the alert box.
        $width = $buf->width();
        $height = $buf->height();
        $grid = [];
        $blank = Cell::new();
        for ($row = 0; $row < $height; $row++) {
            $cells = $row < \count($lines) ? $this->scanLine($lines[$row], $width, true, $envCtx) : [];
            for ($col = 0; $col < $width; $col++) {
                $grid[] = $cells[$col] ?? $blank;
            }
        }
        return Buffer::fromGrid($width, $height, $grid);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Lay out one alert as its bordered box string.
     *
     * @param int $capWidth Hard ceiling from the render canvas (the viewport
     *                      width) — the box can never outgrow what it is
     *                      drawn on, even when maxWidth is larger.
     */
    private function renderAlert(Alert $alert, int $capWidth, ?array $envCtx = null): string
    {
        // Null message renders as empty string — the width probe coalesces it
        // too, because under strict_types Width::string(null) would fatal
        // before any `?? ''` in the same statement could rescue it.
        $message = $this->boundMessage($alert->message);
        $actions = $this->visibleActions($alert);
        $hiddenActions = \count($alert->actions) - \count($actions);

        $width = $this->resolveWidth(Width::string($message ?? ''), $capWidth);
        $icon  = $alert->type->icon($this->symbols);
        $color = $alert->type->color();

        // Inner content width (between the two vertical borders).
        $inner = \max(1, $width - 2);

        // SGR prefix routed through sugar-core Ansi so the icon's colour
        // is emitted byte-identically to a hand-built CSI sequence. Under
        // NO_COLOR/CLICOLOR=0 the icon rides plain — Width::string
        // of the prefix therefore also collapses, giving the message one
        // extra cell of budget instead of dead SGR padding.
        $prefix = ($envCtx[0] ?? $this->coloursSuppressed())
            ? $icon . ' '
            : Ansi::CSI . $color . 'm' . $icon . Ansi::reset() . ' ';

        if ($this->accessibleText) {
            // Meaning must survive colour-blindness, a palette-less
            // terminal, and text-only extraction. The word rides AFTER the
            // icon's reset — plain cells, never styled chrome — and the
            // icon-drop degrade below can remove it along with the icon at
            // hopeless widths (ellipsize, never delete: the message keeps
            // priority over the word).
            $prefix .= $alert->type->label() . ' ';
        }

        // Top / bottom borders: '─' is a 3-byte UTF-8 grapheme but one
        // display cell, so repeat it $inner times for $inner cells.
        $chrome = $this->chrome();
        $v      = $chrome['v'];
        $top    = $chrome['tl'] . \str_repeat($chrome['h'], $inner) . $chrome['tr'];
        $bottom = $chrome['bl'] . \str_repeat($chrome['h'], $inner) . $chrome['br'];

        $middleLines = [];

        // Header line: coloured icon + first slice of the message, padded
        // to the inner cell width (Width::* are ANSI- and multibyte-aware).
        // Null message renders as empty string — never "null".
        $iconCells = Width::string($prefix);
        if ($inner < $iconCells + 1) {
            // A box narrower than icon + one content cell must ELLIPSIZE,
            // never DELETE: at cols<=4 the styled prefix consumed the whole
            // inner width and the message's leading characters vanished
            // silently. Dropping the icon is the honest degrade — while
            // colour is live the severity still shows in the border; under
            // NO_COLOR (or a CLICOLOR-disabled host) the icon was the only
            // severity channel left, so the narrow box loses it too.
            $prefix = '';
            $iconCells = 0;
            if (($envCtx[0] ?? $this->coloursSuppressed())) {
                // finding 056: zero-channel guard. With colour suppressed
                // AND the icon dropped, a critical alert in a monochrome
                // narrow terminal carried NO severity signal at all.
                // Retreat to the symbol set's ASCII letter, space-free so
                // it costs one cell — severity then survives on >=1 channel
                // by construction at any width where a message cell also
                // fits. At inner <= 1 the message keeps priority: nothing
                // survives, and that floor is the documented law.
                $letter = \trim($alert->type->asciiPrefix(), '[]');
                if ($inner >= Width::string($letter) + 1) {
                    $prefix = $letter;
                    $iconCells = Width::string($prefix);
                }
            }
        }
        $headerWrap = $this->wordWrap($message ?? '', \max(1, $inner - $iconCells));
        $firstWord  = \array_shift($headerWrap) ?? '';
        $headerBody = $prefix . $firstWord;
        $middleLines[] = $v . Width::padRight(Width::truncateAnsi($headerBody, $inner), $inner) . $v;

        // Remaining wrapped message lines, indented one cell.
        foreach ($headerWrap as $wl) {
            // One-cell indent — dropped on the same degrade path as the
            // icon: at inner<=2 the indent would itself truncate a chunk's
            // last character (delete, not ellipsize).
            $body = ($inner <= 2 ? '' : ' ') . $wl;
            $middleLines[] = $v . Width::padRight(Width::truncateAnsi($body, $inner), $inner) . $v;
        }

        // Render progress bar if set
        if ($alert->progress !== null) {
            $middleLines[] = $this->renderProgressBar(
                $alert->progress,
                $inner,
                $this->accessibleText
                    ? \sprintf('%d%%', (int) \round(\max(0.0, \min(1.0, $alert->progress)) * 100))
                    : ''
            );
        }

        // Render action buttons if any
        foreach ($actions as $action) {
            $label = '[' . $action->label . ']';
            if (Width::string(Ansi::strip($label)) > $inner) {
                // Same ellipsize-never-delete law as the header: a
                // button that cannot fit shows '…', not silence. Styling is
                // dropped on this edge path — a truncated button is already
                // degraded, and cell-exact width beats colour there.
                $middleLines[] = $v . Width::padRight(Width::truncateMiddle(Ansi::strip($label), $inner), $inner) . $v;
            } else {
                $middleLines[] = $v . Width::padRight(Width::truncateAnsi($label, $inner), $inner) . $v;
            }
        }
        if ($hiddenActions > 0) {
            // Folded buttons stay on the Alert (history()/activeAlerts());
            // the frame says so instead of growing a row per button. The
            // row is prose, NOT affordance: buttons paint as '[Label]', so
            // the fold wears no brackets and rides dim, and actionRects()
            // never lists it — a click there hits nothing. The wording rides
            // the 'more' lang key (README i18n section).
            $more = Lang::t('more', ['count' => $hiddenActions]);
            $middleLines[] = $v . "\x1b[2m" . Width::padRight(
                Width::truncateMiddle($more, $inner),
                $inner
            ) . "\x1b[0m" . $v;
        }

        $lines = [$top, ...$middleLines, $bottom];
        return \implode("\n", $lines);
    }

    /**
     * Truncate an over-long message to {@see MAX_MESSAGE_CELLS} display cells
     * with a visible marker. The Alert itself keeps the full text —
     * history()/activeAlerts() hosts never lose data to a render-time budget.
     */
    private function boundMessage(?string $message): ?string
    {
        if ($message === null || Width::string($message) <= self::MAX_MESSAGE_CELLS) {
            return $message;
        }
        $marker = ' ' . Lang::t('truncated');
        $cut = Width::truncateAnsi($message, self::MAX_MESSAGE_CELLS - Width::string($marker));
        // truncateAnsi may leave inline styling open; close it before the
        // marker so the truncation marker never inherits hostile colour.
        return $cut . Ansi::reset() . $marker;
    }

    /**
     * The actions {@see renderAlert()} and {@see actionRects()} render — the
     * FIRST {@see MAX_ACTIONS_PER_ALERT} of the alert's list, so the painted
     * buttons and the hit-map agree by construction (one source of truth).
     *
     * @return list<Action>
     */
    private function visibleActions(Alert $alert): array
    {
        return \array_slice($alert->actions, 0, self::MAX_ACTIONS_PER_ALERT);
    }

    /**
     * Render an alert into a Buffer sized to exactly its box.
     *
     * Parses the ANSI-encoded string from renderAlert() to extract SGR
     * sequences and builds cells with proper Buffer Style objects, so
     * toAnsi() produces correct styled output. Mirrors DaltonSW/bubbleup's
     * alert rendering pipeline.
     *
     * @param int $capWidth Ceiling from the render canvas (min of maxWidth is
     *                      applied inside resolveWidth); the returned buffer's
     *                      width equals the box's own cell width — never the
     *                      canvas — so view() blits precisely the box.
     */
    private function renderAlertToBuffer(Alert $alert, int $capWidth, ?array $envCtx = null): Buffer
    {
        $alertStr = $this->renderAlert($alert, $capWidth, $envCtx);
        $lines = $this->splitLines($alertStr);

        $height = \count($lines);
        $boxWidth = max(1, Width::string($lines[0] ?? ''));

        // One scanLine pass per row into a flat grid, ONE immutable
        // build — no per-cell clone anywhere on the production path.
        $grid = [];
        $blank = Cell::new();
        foreach ($lines as $line) {
            $cells = $this->scanLine($line, $boxWidth, false, $envCtx);
            for ($col = 0; $col < $boxWidth; $col++) {
                $grid[] = $cells[$col] ?? $blank;
            }
        }

        // Additive: overlay optional per-alert bg/fg/border colours. When
        // all three are null (the default) this pass is skipped and the
        // buffer stays byte-identical to the plain render path.
        if ($alert->backgroundColor !== null
            || $alert->foregroundColor !== null
            || $alert->borderColor !== null) {
            $grid = $this->colorAlertGrid($grid, $boxWidth, $alert, $lines, $envCtx);
        }

        return Buffer::fromGrid($boxWidth, $height, $grid);
    }

    /**
     * Box chrome glyphs for the active symbol set. Ascii means ASCII all the
     * way down — borders `+ - |` and bar `# .` — so LANG=C / TERM=dumb hosts
     * that opted out of non-ASCII glyphs do not get box-drawing back through
     * the frame (bubbleup's approach). NerdFont/Unicode keep the rounded
     * box-drawing frame and block bar, byte-stable.
     *
     * @return array{tl:string,h:string,tr:string,v:string,bl:string,br:string,fill:string,empty:string}
     */
    private function chrome(): array
    {
        return $this->symbols === SymbolSet::Ascii
            ? ['tl' => '+', 'h' => '-', 'tr' => '+', 'v' => '|', 'bl' => '+', 'br' => '+', 'fill' => '#', 'empty' => '.']
            : ['tl' => '╭', 'h' => '─', 'tr' => '╮', 'v' => '│', 'bl' => '╰', 'br' => '╯', 'fill' => '█', 'empty' => '░'];
    }

    /**
     * Overlay a queued Alert's optional bg/fg/border colours onto its grid:
     * every box cell gains the background fill; box-drawing glyphs
     * (╭ ─ ╮ │ ╰ ╯) take the border colour; remaining interior text takes
     * the foreground colour. Cells that already carry a colour — the
     * severity-coloured icon and any inline-SGR message spans — keep it.
     * sugar-dashboard's Toast adapter DELEGATES to this renderer — the
     * styled box originates here (PR #1289 folded dashboard's rendering
     * into this lib; an earlier 'mirrors sugar-dashboard' cite had the
     * direction inverted). The grid-native pass is the single truth the
     * production build calls directly; its former Buffer-shaped wrapper
     * went unreferenced and was deleted rather than left holding a
     * reflection-pinned claim no test honoured — the honest seam pins are
     * placeAnsiStringAt/sgrToBufferStyle in ToastRenderingTest.
     *
     * @param list<Cell> $grid
     * @return list<Cell>
     */
    private function colorAlertGrid(array $grid, int $bufWidth, Alert $alert, array $lines, ?array $envCtx = null): array
    {
        if (($envCtx[0] ?? $this->coloursSuppressed())) {
            // Custom background/foreground/border colours are colour
            // output too — under NO_COLOR the alert keeps its glyphs, layout
            // and (non-colour) attributes, the palette stays off the wire.
            return $grid;
        }

        $chrome = $this->chrome();
        $borderGlyphs = [
            $chrome['tl'] => true, $chrome['h'] => true, $chrome['tr'] => true,
            $chrome['v'] => true, $chrome['bl'] => true, $chrome['br'] => true,
        ];

        $bg     = $alert->backgroundColor !== null ? $this->colorToRgb($alert->backgroundColor) : null;
        $fg     = $alert->foregroundColor !== null ? $this->colorToRgb($alert->foregroundColor) : null;
        $border = $alert->borderColor !== null ? $this->colorToRgb($alert->borderColor) : null;

        $height   = (int) (\count($grid) / max(1, $bufWidth));
        $lineCount = \count($lines);

        for ($row = 0; $row < $height && $row < $lineCount; $row++) {
            $cols = \min($bufWidth, Width::string($lines[$row]));
            for ($col = 0; $col < $cols; $col++) {
                $cell = $grid[$row * $bufWidth + $col];
                if ($cell->width() === 0) {
                    continue;  // wide-char continuation — toAnsi() skips it
                }

                $existing = $cell->style();
                $newFg = $existing?->fg();
                $attrs = $existing?->attrs() ?? 0;

                if (isset($borderGlyphs[$cell->rune()])) {
                    if ($border !== null) {
                        $newFg = $border;
                    }
                } elseif ($newFg === null && $fg !== null) {
                    // Plain interior text/padding — icon and inline-styled
                    // cells (non-null fg) keep their own colour.
                    $newFg = $fg;
                }

                $newBg = $bg ?? $existing?->bg();

                $grid[$row * $bufWidth + $col] =
                    new Cell($cell->rune(), new Style($newFg, $newBg, $attrs), $cell->link(), $cell->width());
            }
        }

        return $grid;
    }

    /**
     * Pack a sugar-core {@see Color} into a 24-bit 0xRRGGBB int, the shape
     * sugar-cellbuffer's {@see Style} stores fg/bg colours in.
     */
    private function colorToRgb(Color $color): int
    {
        return ($color->r << 16) | ($color->g << 8) | $color->b;
    }

    /**
     * Scan one string into its sequence of display cells (the linear
     * build path: rows are scanned once into a flat grid and committed with
     * a single Buffer::fromGrid, instead of the old one immutable
     * withCellAt clone PER CELL — O(cells²) on the whole frame, which made a
     * 32KB hostile message kill the process past 120s (security MAJOR-2)).
     *
     * The walker itself is unchanged in semantics: OSC/DCS/2-char escapes
     * consumed (links honoured only on the host-frame path), CSI-SGR folded
     * cumulatively, wide runes paired with a continuation cell, and the row
     * stops at $maxCols cells.
     *
     * @return list<Cell>
     */
    private function scanLine(string $s, int $maxCols, bool $hostFrame = false, ?array $envCtx = null): array
    {
        $cells = [];
        $len = \strlen($s);
        $i = 0;
        $currentStyle = null;
        $currentLink = null;

        while ($i < $len) {
            $b = $s[$i];

            // OSC / DCS / other string sequences and 2-char escapes are
            // consumed HERE, never printed: an earlier walker recognised
            // recognised only CSI, so a host frame carrying an OSC-8 link
            // came back as printable "]8;;url \\ CLICKY" garbage — the ESCs
            // dropped by the width guard while the framing survived). On the
            // background path an OSC-8 also re-attaches a real Hyperlink to
            // the covered cells so toAnsi() re-emits the frame (the host's
            // link survives the toast); on the alert-box path (content) the
            // framing is dropped without honouring it — hostile text can
            // neither forge a clickable link nor spray escape bytes.
            if ($b === "\x1b" && ($s[$i + 1] ?? '') === ']') {
                [$payload, $i] = $this->consumeStringSequence($s, $i, $len);
                $currentLink = $hostFrame ? $this->hyperlinkFromOscPayload($payload, $currentLink) : null;
                continue;
            }

            if ($b === "\x1b" && \str_contains('PX^_', $s[$i + 1] ?? '')) {
                // DCS / SOS / PM / APC — terminated payloads, discarded.
                [, $i] = $this->consumeStringSequence($s, $i, $len);
                continue;
            }

            if ($b === "\x1b" && ($s[$i + 1] ?? '') !== '[') {
                // Fe/Fp two-character escapes (ESC M, ESC 7, ESC c, …) and
                // stray ESC ESC: consume both bytes, act on none — the grid
                // is a compositing surface, not a terminal emulator. A bare
                // trailing ESC is consumed on its own.
                $i += isset($s[$i + 1]) && $s[$i + 1] !== "\x1b" ? 2 : 1;
                continue;
            }

            if ($b === "\x1b" && ($s[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                $final = null;
                while ($j < $len) {
                    $c = \ord($s[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        $final = $s[$j - 1];
                        break;
                    }
                }
                if ($final !== null && $final === 'm') {
                    // Cumulative fold: a sequence mutates only the facets it
                    // names — styles survive across sequences on the same run
                    // of cells, and a sugar-cellbuffer-emitted `\e[0;…m`
                    // preamble resets then rebuilds instead of erasing
                    // everything.
                    $currentStyle = $this->applySgr($currentStyle, \substr($s, $i + 2, $j - $i - 3), $envCtx);
                }
                // Any other final byte (cursor-move H, erase K/J, DEC modes…)
                // CONSUMES the sequence and leaves the style run untouched —
                // the rule the walker already applies to DCS/APC/PM/SOS. The
                // old unconditional applySgr decoded `\e[10;5H` as SGR params:
                // blink bled across the host row. An unterminated CSI
                // (end-of-row) is likewise consumed: folding its bytes into
                // applySgr('') fabricated a RESET the host never sent.
                $i = $j;
                continue;
            }

            $cluster = $this->nextCluster($s, $i);
            $gw = $this->graphemeWidth($cluster);

            if ($gw === 0) {
                $i += \strlen($cluster);
                continue;
            }

            if (\count($cells) >= $maxCols) {
                break;
            }

            $cells[] = new Cell($cluster, $currentStyle, $currentLink, $gw);
            if ($gw === 2 && \count($cells) < $maxCols) {
                $cells[] = Cell::continuation();
            }
            $i += \strlen($cluster);
        }

        return $cells;
    }

    /**
     * Single-row placement kept as the reflection-pinned seam the renderer
     * tests drive directly (malformed-walk pins). Production paths compose
     * through scanLine() + one Buffer::fromGrid per surface; this wrapper
     * rebuilds the grid in ONE pass (O(w*h) reads, O(1) per cell) instead of
     * the historical cell-by-cell clone chain.
     */
    private function placeAnsiStringAt(Buffer $buf, int $col, int $row, string $s, bool $hostFrame = false): Buffer
    {
        $width = $buf->width();
        $height = $buf->height();
        if ($row < 0 || $row >= $height || $col >= $width) {
            return $buf;
        }

        $replaced = $this->scanLine($s, $width - max(0, $col), $hostFrame);

        $grid = [];
        for ($r = 0; $r < $height; $r++) {
            for ($c = 0; $c < $width; $c++) {
                if ($r === $row && $c >= $col && ($c - $col) < \count($replaced)) {
                    $grid[] = $replaced[$c - $col];
                } else {
                    $grid[] = $buf->cellAt($c, $r);
                }
            }
        }
        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Extract the next UTF-8 grapheme cluster from string at $i. Delegates
     * to sugar-core's canonical {@see Width::nextCluster()}. This
     * used to be a sanctioned verbatim fork (kept because the core splitter
     * was private); the duplicated body is deleted now that core promotes it
     * to a public entry point, so both invalid-UTF-8 guards (ICU position
     * rejection + continuation-byte validation) live in exactly one place.
     * The malformed-walk pins reach the guards through this seam: the
     * call-site contract is unchanged (same return shape, byte-for-byte
     * reproduction of malformed input, always advances by >= 1 byte).
     */
    private function nextCluster(string $s, int $i): string
    {
        return Width::nextCluster($s, $i);
    }

    /**
     * Convert an ANSI SGR parameter list (e.g. "31" or "1;32") into a Buffer
     * Style from a clean slate. SGR "0" resets everything, returning null.
     *
     * Thin single-sequence wrapper over {@see applySgr()} — kept as the
     * historical entry point the renderer tests cite.
     */
    private function sgrToBufferStyle(string $sgr): ?Style
    {
        return $this->applySgr(null, $sgr);
    }

    /**
     * Fold one SGR parameter list onto an existing style with terminal-facet
     * semantics: every code mutates only the facet it names.
     *
     * The old decoder REPLACED the whole style per sequence and understood
     * only fg-16/bold/reset, so 256-colour (`38;5;n`), truecolour
     * (`38;2;r;g;b`), backgrounds and the underline/reverse/dim/overline
     * family were silently dropped — and `38;2;10;20;30` even mis-parsed its
     * payload as a palette index, painting text black. Supported now:
     * 1-9/53 on, 22-29/55 off, 30-37/90-97/39 foreground, 40-47/100-107/49
     * background, 38/48 extended colours (index 5 → sugar-core's canonical
     * xterm-256 table, index 2 → packed truecolor). An empty parameter list
     * means SGR 0 (reset) per spec; a STRUCTURALLY malformed extended-colour
     * payload (missing components) stops the walk fail-soft like a real
     * terminal; an out-of-range component SATURATES to 0-255 in parity with
     * sugar-cellbuffer's decoder. Unknown codes are skipped without
     * disturbing the state.
     *
     * NOTE (seam row): this decoder is one of three
     * fleet forks (here, sugar-cellbuffer styleFromSgr, sugar-overlay
     * penFromSgr); colour-value parity is pinned against cellbuffer, but the
     * fleet still owes ONE public decoder boundary (candy-ansi-parser or a
     * widened cellbuffer ingestion). This surface additionally honours attrs
     * 22-29 (individual off) and 53/55 (overline) that cellbuffer lacks — a
     * documented superset, not a silent drift.
     */
    private function applySgr(?Style $base, string $sgr, ?array $envCtx = null): ?Style
    {
        // explode(';', '') yields [''] → intval 0 → the spec's implicit reset.
        $params = \array_map('intval', \explode(';', $sgr));
        $count = \count($params);

        $fg = $base?->fg();
        $bg = $base?->bg();
        $attrs = $base?->attrs() ?? 0;
        // TST-ENVPERF: the env booleans ride the per-render $envCtx
        // ([suppressColour, reducedMotion]) computed once in layout(). The
        // null fallback (direct seam calls) still reads env live — the
        // docblock cadence "per render, not cached" is exactly what this
        // threading restored: folded per escape, ~5 getenv ran for EVERY SGR
        // in a dense host frame instead of once per frame.
        $suppressColour = $envCtx !== null ? $envCtx[0] : $this->coloursSuppressed();
        $reducedMotion = $envCtx !== null ? $envCtx[1] : $this->reducedMotionPreferred();

        for ($k = 0; $k < $count; $k++) {
            $code = $params[$k];

            // NO_COLOR legs: extended-colour payloads must still be
            // CONSUMED so later codes stay aligned; the 16/256 colour sets
            // are skipped outright; 39/49 resets no-op on already-null
            // facets and fall through untouched.
            if ($suppressColour && ($code === 38 || $code === 48)) {
                if ($this->readExtendedColor($params, $k) === null) {
                    break; // malformed payload — stop the walk, like a terminal
                }
                continue;
            }
            if ($suppressColour && (($code >= 30 && $code <= 37) || ($code >= 40 && $code <= 47)
                || ($code >= 90 && $code <= 97) || ($code >= 100 && $code <= 107))) {
                continue;
            }

            if ($code === 38 || $code === 48) {
                $rgb = $this->readExtendedColor($params, $k);
                if ($rgb === null) {
                    break; // malformed payload — stop consuming, like a terminal
                }
                if ($code === 38) {
                    $fg = $rgb;
                } else {
                    $bg = $rgb;
                }
                continue;
            }

            if ($code === 0) {
                $fg = null;
                $bg = null;
                $attrs = 0;
            } elseif ($code === 1) {
                $attrs |= Style::ATTR_BOLD;
            } elseif ($code === 2) {
                $attrs |= Style::ATTR_FAINT;
            } elseif ($code === 3) {
                $attrs |= Style::ATTR_ITALIC;
            } elseif ($code === 4) {
                $attrs |= Style::ATTR_UNDERLINE;
            } elseif ($code === 5) {
                // Reduced motion: blink is the only CARRIED animation
                // this renderer emits, and it can arrive via UNTRUSTED
                // message text — WCAG 2.2.2 says honour the user's flag.
                // 25 (blink off) stays unconditional; nothing else is motion.
                if (!$reducedMotion) {
                    $attrs |= Style::ATTR_BLINK;
                }
            } elseif ($code === 7) {
                $attrs |= Style::ATTR_REVERSE;
            } elseif ($code === 8) {
                $attrs |= Style::ATTR_INVISIBLE;
            } elseif ($code === 9) {
                $attrs |= Style::ATTR_STRIKE;
            } elseif ($code === 53) {
                $attrs |= Style::ATTR_OVERLINE;
            } elseif ($code === 22) {
                $attrs &= ~(Style::ATTR_BOLD | Style::ATTR_FAINT);
            } elseif ($code === 23) {
                $attrs &= ~Style::ATTR_ITALIC;
            } elseif ($code === 24) {
                $attrs &= ~Style::ATTR_UNDERLINE;
            } elseif ($code === 25) {
                $attrs &= ~Style::ATTR_BLINK;
            } elseif ($code === 27) {
                $attrs &= ~Style::ATTR_REVERSE;
            } elseif ($code === 28) {
                $attrs &= ~Style::ATTR_INVISIBLE;
            } elseif ($code === 29) {
                $attrs &= ~Style::ATTR_STRIKE;
            } elseif ($code === 55) {
                $attrs &= ~Style::ATTR_OVERLINE;
            } elseif ($code >= 30 && $code <= 37) {
                $fg = $this->ansiColorToRgb($code - 30, false);
            } elseif ($code === 39) {
                $fg = null;
            } elseif ($code >= 40 && $code <= 47) {
                $bg = $this->ansiColorToRgb($code - 40, false);
            } elseif ($code === 49) {
                $bg = null;
            } elseif ($code >= 90 && $code <= 97) {
                $fg = $this->ansiColorToRgb($code - 90, true);
            } elseif ($code >= 100 && $code <= 107) {
                $bg = $this->ansiColorToRgb($code - 100, true);
            }
            // unknown code: skipped without disturbing the state
        }

        if ($fg === null && $bg === null && $attrs === 0) {
            return null;
        }
        return new Style($fg, $bg, $attrs);
    }

    /**
     * Decode the `5;n` / `2;r;g;b` payload trailing a 38/48 code at
     * {@see $k}, advancing $k past every consumed parameter. Returns the
     * packed 0xRRGGBB int, or null when the payload is missing, has an
     * unsupported selector, or carries an out-of-range component.
     *
     * @param list<int> $params
     */
    private function readExtendedColor(array $params, int &$k): ?int
    {
        $selector = $params[$k + 1] ?? null;
        if ($selector === 5) {
            $idx = $params[$k + 2] ?? null;
            if ($idx === null) {
                return null; // structurally malformed — fail-soft stop
            }
            $k += 2;
            // Out-of-range saturates into 0-255 exactly like sugar-cellbuffer's
            // channel(): the old NULL return BROKE the decode walk, so the
            // same bytes styled differently depending on which layer parsed
            // them.
            return $this->ansi256ToRgb(max(0, min(255, $idx)));
        }
        if ($selector === 2) {
            $r = $params[$k + 2] ?? null;
            $g = $params[$k + 3] ?? null;
            $b = $params[$k + 4] ?? null;
            if ($r === null || $g === null || $b === null) {
                return null; // structurally malformed — fail-soft stop
            }
            $k += 4;
            $clamp = static fn(int $c): int => max(0, min(255, $c));
            return ($clamp($r) << 16) | ($clamp($g) << 8) | $clamp($b);
        }
        return null;
    }

    /**
     * xterm-256 index → packed RGB via sugar-core's canonical table, so the
     * 16-colour slots can never fork away from {@see ansiColorToRgb()}.
     */
    private function ansi256ToRgb(int $idx): int
    {
        $color = Color::ansi256($idx);
        return ($color->r << 16) | ($color->g << 8) | $color->b;
    }

    /**
     * Map an SGR colour index (0-7, bright variant when {@see $bright}) to a
     * packed 24-bit RGB int. The triples come from sugar-core's canonical
     * xterm table — slot 4 = `#0000EE` (`main.h DEF_COLOR4 "blue2"`), slot 12
     * = `#5C5CFF` (`DEF_COLOR12 "rgb:5c/5c/ff"`) — indexed from
     * {@see Color::ANSI16_RGB} so this decode can never fork its own blues
     * again. Indices that resolve outside the 16-slot table fall back to its
     * white slot (7, or 15 for the bright half), preserving the old
     * default-to-white.
     */
    private function ansiColorToRgb(int $idx, bool $bright): int
    {
        $offset = $bright ? 8 : 0;
        [$r, $g, $b] = Color::ANSI16_RGB[$offset + $idx] ?? Color::ANSI16_RGB[$offset + 7];
        return ($r << 16) | ($g << 8) | $b;
    }

    /**
     * Display width of one grapheme cluster in cells: 0, 1, or 2.
     *
     * Delegates to sugar-core's canonical {@see Width} oracle — the old local
     * fork measured emoji (U+1F300+) and other EAW-wide ranges as 1 cell,
     * desynchronising box padding from every other SugarCraft renderer (a
     * padded row then overflowed its own border by one cell). Two
     * grid-specific divergences stay deliberate and documented: control bytes
     * never occupy a cell — a TAB included, which Width charges
     * TAB_WIDTH = 4 but a fixed cell grid cannot represent, so it scores 0
     * here and is skipped at placement (the old one-cell TAB special-case put
     * raw 0x09 on the wire and desynced the terminal's own tab stops) — and
     * the result is clamped into the Cell contract's 0–2 so a multi-cell
     * cluster can never blow the grid.
     */
    private function graphemeWidth(string $g): int
    {
        if ($g === '') return 0;
        // C0 controls (TAB included), DEL, and C1 never occupy a cell —
        // they are skipped at placement instead of being charged a cell and
        // emitted: the old one-cell TAB special-case put raw 0x09 on
        // the wire, desyncing the terminal's own tab stops.
        // Action labels are normalised at construction, so this guard is
        // the second door shared with the message channel.
        if (\strlen($g) === 1 && (\ord($g) < 0x20 || \ord($g) === 0x7f)) return 0;
        if (\preg_match('/^[\x{80}-\x{9f}]$/u', $g) === 1) return 0;
        return (int) \min(2, \max(0, Width::string($g)));
    }

    /**
     * Render a progress bar using Unicode block characters.
     *
     * @param float $progress  0.0 to 1.0
     * @param int $width  Available width in cells
     * @param string $percent  '' (bar only) or a ready-printed numeric label
     *                         like '80%' (accessible mode)
     */
    private function renderProgressBar(float $progress, int $width, string $percent = ''): string
    {
        // $width is the inner cell width (between the vertical borders) —
        // the bar fills EXACTLY that budget. It used to floor at 4 cells,
        // so a narrow box got a row longer than its own interior and the
        // right border fell off mid-box.
        $width = \max(1, $width);
        $progress = \max(0.0, \min(1.0, $progress));
        $chrome = $this->chrome();
        $v = $chrome['v'];

        if ($percent !== '' && Width::string($percent) + 2 <= $width) {
            // Accessible numeric read-out: the percentage label plus one space is
            // carved out of the bar's budget. Below that floor the number
            // would only survive mangled ('10' for '100%'), so the honest
            // degrade is the plain bar — every cell the box has is content.
            $barWidth = $width - Width::string($percent) - 1;
            $barFilled = \max(0, \min($barWidth, (int) \round($progress * $barWidth)));
            return $v . $percent . ' '
                . \str_repeat($chrome['fill'], $barFilled) . \str_repeat($chrome['empty'], $barWidth - $barFilled) . $v;
        }
        $filled = (int) \round($progress * $width);
        $filled = \max(0, \min($width, $filled));
        $empty = $width - $filled;

        // Each bar glyph is one display cell; build exactly
        // $width cells so the bar aligns flush with the borders.
        $bar = \str_repeat($chrome['fill'], $filled) . \str_repeat($chrome['empty'], $empty);
        return $v . $bar . $v;
    }

    /**
     * Box width for a message of $messageLen display cells, capped by BOTH
     * maxWidth and the render canvas ($capWidth) — a box may never exceed the
     * frame it is blitted onto.
     *
     * NOTE (master-switch law, see withMinWidth()): minWidth <= 0
     * short-circuits to the cap — FIXED full-width mode. Fit-content sizing
     * engages ONLY at minWidth > 0.
     */
    private function resolveWidth(int $messageLen, int $capWidth): int
    {
        $cap = max(1, min($this->maxWidth, $capWidth));
        if ($this->minWidth <= 0) {
            return $cap;
        }
        // WHY: NerdFont/Unicode icons are 1 display cell; ASCII "[E]" is 3 cells.
        // The +1 accounts for the trailing space after the icon in renderAlert().
        $iconSpace = match ($this->symbols) {
            SymbolSet::Ascii => 3,
            default => 1,
        } + 1;
        $needed = $messageLen + $iconSpace + 4;  // + borders + padding
        return max(1, min($cap, max($this->minWidth, min($needed, $this->maxWidth))));
    }

    /**
     * Wrap text into display-cell lines, one row per paragraph (or
     * overflow chunk).
     *
     * An empty paragraph emits ONE spacer row: "First\n\nSecond" is three
     * rows, not two. Collapsing deliberate blank lines would silently
     * delete layout the host authored. A single trailing newline is
     * dropped instead, mirroring splitLines().
     *
     * Carve-out: an OVERSIZED word is chunked with its ANSI stripped. The
     * walker advances $remaining by strlen(), which requires every chunk
     * to be a byte prefix of $remaining; truncateAnsi() appends reset
     * sequences that are not prefix bytes, and miscounted advancement
     * then shifts each following chunk into the word itself (measured:
     * cells vanished at every boundary). Style on that one word
     * therefore degrades to plain cells — every CELL survives, which is
     * the stronger half of the contract.
     */
    private function wordWrap(string $text, int $width): array
    {
        if ($width <= 0) return [''];
        $paragraphs = \explode("\n", $text);
        if (\count($paragraphs) > 1 && \end($paragraphs) === '') {
            \array_pop($paragraphs);
        }
        $result = [];
        foreach ($paragraphs as $para) {
            if ($para === '') {
                $result[] = '';
                continue;
            }
            $words = \preg_split('/\s+/', $para) ?: [];
            $current = '';
            foreach ($words as $word) {
                $test = $current === '' ? $word : $current . ' ' . $word;
                // Measure by display cells, not bytes, so multibyte words
                // wrap at the visible column rather than a byte boundary.
                if (Width::string($test) <= $width) {
                    $current = $test;
                } else {
                    if ($current !== '') $result[] = $current;
                    if (Width::string($word) > $width) {
                        // Split oversized word at cell boundaries (never
                        // mid-grapheme), on ANSI-stripped bytes so the
                        // strlen() walk stays exact (see docblock).
                        $remaining = Ansi::strip($word);
                        while (Width::string($remaining) > $width) {
                            $chunk = Width::truncate($remaining, $width);
                            if ($chunk === '') {
                                break;
                            }
                            $result[] = $chunk;
                            $remaining = \substr($remaining, \strlen($chunk));
                        }
                        $current = $remaining;
                    } else {
                        $current = $word;
                    }
                }
            }
            if ($current !== '') $result[] = $current;
        }
        return $result ?: [''];
    }

    private function splitLines(string $text): array
    {
        $lines = \explode("\n", $text);
        if (\end($lines) === '') \array_pop($lines);
        return $lines;
    }
}
