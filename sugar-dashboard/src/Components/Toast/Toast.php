<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Toast;

use SugarCraft\Core\Util\Color;
use SugarCraft\Toast\Alert;
use SugarCraft\Toast\Position as ToastPosition;
use SugarCraft\Toast\Toast as ToastEngine;
use SugarCraft\Toast\ToastType;

/**
 * A toast / notification component.
 *
 * Displays a message notification:
 * - Multiple toast types (info, success, warning, error)
 * - Optional title
 * - Auto-dismiss timer display (visual only)
 * - Customizable icon and colors
 *
 * Rendering is delegated to the shared sugar-toast engine
 * ({@see \SugarCraft\Toast\Toast}) so sugar-dashboard no longer reimplements its
 * own box drawing: a per-type {@see \SugarCraft\Toast\Alert} carries the
 * background/foreground/border colours (sugar-toast PR #1289) and the engine
 * composites the styled box, which this class trims to the box itself.
 *
 * The public vocabulary — {@see Notification}, {@see Level},
 * {@see NoticePosition} — stays stable; only the rendered bytes change (the
 * engine draws its own severity icon and per-cell layout).
 *
 * Adapter: `fromNotification()` / `fromQueue()` bridge Notification DTOs
 * into styled toast output.
 */
final class Toast implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<string>|null D-152 per-width line memo (see renderLines) */
    private ?array $lineMemo = null;
    private ?int $memoWidth = null;

    public function __construct(
        private readonly string $message,
        private readonly ?string $title = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $foregroundColor = null,
        private readonly ?Color $borderColor = null,
        private readonly string $icon = '',
        private readonly int $maxWidth = 60,
        // Severity fed to the sugar-toast engine; drives the drawn icon and
        // icon colour. The neutral default maps to Info.
        private readonly ToastType $type = ToastType::Info,
    ) {}

    /**
     * Bridge a Notification DTO into a styled Toast.
     *
     * Maps the sugar-dashboard {@see Level} onto the sugar-toast {@see ToastType}
     * (both share the same string values) and applies the per-level palette.
     */
    public static function fromNotification(Notification $notification): self
    {
        $type = self::typeForLevel($notification->level);

        return match ($notification->level) {
            Level::Info => new self(
                message: $notification->message,
                title: $notification->title,
                backgroundColor: Color::hex('#1E3A5F'),
                foregroundColor: Color::hex('#E5E7EB'),
                borderColor: Color::hex('#3B82F6'),
                icon: '',
                maxWidth: 60,
                type: $type,
            ),
            Level::Warning => new self(
                message: $notification->message,
                title: $notification->title,
                backgroundColor: Color::hex('#451A03'),
                foregroundColor: Color::hex('#FEF3C7'),
                borderColor: Color::hex('#F59E0B'),
                icon: '',
                maxWidth: 60,
                type: $type,
            ),
            Level::Error => new self(
                message: $notification->message,
                title: $notification->title,
                backgroundColor: Color::hex('#450A0A'),
                foregroundColor: Color::hex('#FEE2E2'),
                borderColor: Color::hex('#EF4444'),
                icon: '',
                maxWidth: 60,
                type: $type,
            ),
            Level::Success => new self(
                message: $notification->message,
                title: $notification->title,
                backgroundColor: Color::hex('#052E16'),
                foregroundColor: Color::hex('#DCFCE7'),
                borderColor: Color::hex('#22C55E'),
                icon: '',
                maxWidth: 60,
                type: $type,
            ),
        };
    }

    /**
     * Bridge a NotificationQueue, rendering its current head as a toast.
     */
    public static function fromQueue(NotificationQueue $queue): ?self
    {
        $current = $queue->current();
        if ($current === null) {
            return null;
        }
        return self::fromNotification($current);
    }

    /**
     * Map a sugar-dashboard {@see Level} onto the sugar-toast {@see ToastType}.
     *
     * The two enums are string-backed with identical case values
     * (info/warning/error/success), so the mapping is a value lookup.
     */
    public static function typeForLevel(Level $level): ToastType
    {
        return ToastType::from($level->value);
    }

    /**
     * Create a new toast with default styling.
     *
     * Default: dark background, white text. Rendered through the sugar-toast
     * engine, which draws the neutral Info severity icon.
     */
    public static function new(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#1F2937'),
            foregroundColor: Color::hex('#F9FAFB'),
            borderColor: Color::hex('#374151'),
            icon: '',
            maxWidth: 60,
            type: ToastType::Info,
        );
    }

    /**
     * Create an info-style toast.
     */
    public static function info(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#1E3A5F'),
            foregroundColor: Color::hex('#E5E7EB'),
            borderColor: Color::hex('#3B82F6'),
            icon: '',
            maxWidth: 60,
            type: ToastType::Info,
        );
    }

    /**
     * Create a success-style toast.
     */
    public static function success(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#052E16'),
            foregroundColor: Color::hex('#DCFCE7'),
            borderColor: Color::hex('#22C55E'),
            icon: '',
            maxWidth: 60,
            type: ToastType::Success,
        );
    }

    /**
     * Create a warning-style toast.
     */
    public static function warning(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#451A03'),
            foregroundColor: Color::hex('#FEF3C7'),
            borderColor: Color::hex('#F59E0B'),
            icon: '',
            maxWidth: 60,
            type: ToastType::Warning,
        );
    }

    /**
     * Create an error-style toast.
     */
    public static function error(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#450A0A'),
            foregroundColor: Color::hex('#FEE2E2'),
            borderColor: Color::hex('#EF4444'),
            icon: '',
            maxWidth: 60,
            type: ToastType::Error,
        );
    }

    /**
     * Set the allocated dimensions for this toast.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the toast as a string via the sugar-toast engine.
     */
    public function render(): string
    {
        return implode("\n", $this->renderLines());
    }

    /**
     * Render the styled box to its exact lines through the sugar-toast
     * engine - once per allocation.
     *
     * @return list<string>
     */
    private function renderLines(): array
    {
        $useWidth = min($this->width ?? $this->maxWidth, $this->maxWidth);
        $useWidth = max($useWidth, 10);

        // D-152: the component is immutable between allocations (all fields
        // are readonly; setSize hands back a fresh clone), so the built lines
        // are memoised per width. render() and innerSize() used to drive the
        // full panel separately - 2-3 composites per frame.
        if ($this->memoWidth === $useWidth && $this->lineMemo !== null) {
            return $this->lineMemo;
        }

        // The engine's Alert has no title field, so a title becomes the first
        // paragraph (its own wrapped line via the "\n" break the engine's
        // word-wrap honours). A custom icon is folded into the body because
        // the engine draws only its own severity icon.
        $iconPrefix = $this->icon !== '' ? $this->icon . ' ' : '';
        $body = $this->title !== null
            ? $iconPrefix . $this->title . "\n" . $this->message
            : $iconPrefix . $this->message;

        $alert = (new Alert($this->type, $body))
            ->withBackgroundColor($this->backgroundColor)
            ->withForegroundColor($this->foregroundColor)
            ->withBorderColor($this->borderColor);

        $engine = ToastEngine::new($useWidth)
            ->withMinWidth(0)
            ->withPosition(ToastPosition::TopLeft)
            ->push($alert);

        // Single composite (D-152): render into a viewport tall enough to
        // hold any wrap (the body length bounds the line count), count the
        // non-blank rows, and TRIM the filler the compositor pads beneath a
        // TopLeft-anchored box.
        $bound = mb_strlen($body, 'UTF-8') + 4;
        $probeLines = explode("\n", $engine->View('', $useWidth, $bound));
        $height = self::boxHeightFrom($probeLines);
        // The exact-height re-render used to place the bottom border on the
        // viewport's last row, where the compositor flushes a closing SGR
        // reset (pinned by testColorResetAtEnd). Trimmed rows sit mid-
        // viewport, so the reset is appended by hand - coalesceSgr below
        // dedupes it should the compositor ever close the line itself.
        $trimmed = implode("\n", \array_slice($probeLines, 0, $height)) . \SugarCraft\Core\Util\Ansi::reset();

        // Collapse the per-cell SGR the engine emits (its color pass rebuilds
        // a fresh Style per cell, defeating the buffer's identity-based SGR
        // coalescing).
        $lines = explode("\n", self::coalesceSgr($trimmed));
        $this->memoWidth = $useWidth;
        $this->lineMemo = $lines;

        return $lines;
    }

    /**
     * Count the box rows in a composited render, ignoring the blank filler
     * rows padded beneath a TopLeft-anchored box.
     *
     * @param list<string> $lines
     */
    private static function boxHeightFrom(array $lines): int
    {
        $last = 0;
        foreach ($lines as $i => $line) {
            $visible = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            if ($visible !== '' && !ctype_space($visible)) {
                $last = $i;
            }
        }
        return $last + 1;
    }

    /**
     * Collapse runs of identical SGR escapes.
     *
     * The buffer serialiser only coalesces adjacent cells that share the same
     * Style *instance*; the engine's colour overlay assigns a freshly built
     * Style to every cell, so identical styling re-emits a full escape per
     * character. Dropping each SGR that repeats the currently-active one is a
     * visual no-op that restores contiguous text runs.
     */
    private static function coalesceSgr(string $s): string
    {
        $parts = preg_split('/(\x1b\[[0-9;]*m)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $s;
        }

        $out = '';
        $active = null;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\x1b\[[0-9;]*m$/', $part) === 1) {
                if ($part === $active) {
                    continue;
                }
                $active = $part;
            }
            $out .= $part;
        }

        return $out;
    }

    /**
     * Calculate the natural dimensions of this toast.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $useWidth = min($this->width ?? $this->maxWidth, $this->maxWidth);
        $useWidth = max($useWidth, 10);

        return [$useWidth, count($this->renderLines())];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the toast message.
     */
    public function withMessage(string $message): self
    {
        return new self(
            message: $message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
            type: $this->type,
        );
    }

    /**
     * Set the toast title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            message: $this->message,
            title: $title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
            type: $this->type,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $color,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
            type: $this->type,
        );
    }

    /**
     * Set the foreground (text) color.
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $color,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
            type: $this->type,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $color,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
            type: $this->type,
        );
    }

    /**
     * Set the icon.
     *
     * Folded into the message body ahead of the engine's own severity icon.
     */
    public function withIcon(string $icon): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $icon,
            maxWidth: $this->maxWidth,
            type: $this->type,
        );
    }

    /**
     * Set the maximum width.
     */
    public function withMaxWidth(int $maxWidth): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $maxWidth,
            type: $this->type,
        );
    }
}
