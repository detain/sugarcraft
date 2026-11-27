<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Disclosure;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Style\Style;

/**
 * A titled section that shows or hides its body: `▸ Title` while closed,
 * `▾ Title` followed by the indented body while open.
 *
 * `Disclosure::new($title, $body)->withOpen(true)->toggle()` drives it
 * directly. {@see update()} drives it from keys while focused: Enter or
 * Space toggles, Right or `l` opens, Left or `h` closes. A key that changes
 * the state returns a Cmd that sends a {@see DisclosureToggledMsg}; a key
 * that asks for the state it already has returns a null Cmd. Keys are read
 * only while focused, the same law Tabs, Table and Tree follow, so a new
 * Disclosure starts closed and blurred. Key releases are ignored, so a
 * terminal reporting press and release toggles once per press.
 *
 * The title is sanitized to one line and stripped of zone sentinels, since
 * it lands inside marked frames. The body keeps its SGR, so pre-styled text
 * (a rendered Markdown block, a diff) keeps its colors, but every other
 * escape, C0 control and C1 introducer is removed as `Sanitize::untrusted()`
 * removes it, and it is stripped of zone sentinels for the same reason as the
 * title, so model or tool output is safe to pass as the body. Line endings in
 * the body are normalized to `\n`.
 *
 * Several items under one cursor live in {@see CollapsibleList}.
 *
 * Immutable: every `with*()`, {@see toggle()}, {@see focus()} and
 * {@see blur()} returns a new instance through `mutate()` from sugar-core
 * `Concerns\Mutable` (nine carried fields).
 *
 * Original SugarCraft work: charmbracelet/bubbles has no disclosure
 * component, so there is no upstream Mirrors citation (the antecedent is
 * the HTML `<details>` element).
 */
final class Disclosure implements Model
{
    use Mutable;

    /** Indicator drawn before an open title by default. */
    public const OPEN_GLYPH = '▾';

    /** Indicator drawn before a closed title by default. */
    public const CLOSED_GLYPH = '▸';

    /** Spaces the open body is indented by by default. */
    public const INDENT = 2;

    private function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly bool $open,
        public readonly bool $focused,
        public readonly Style $titleStyle,
        public readonly Style $focusedStyle,
        public readonly string $openGlyph,
        public readonly string $closedGlyph,
        public readonly int $indent,
    ) {
    }

    public static function new(string $title, string $body = ''): self
    {
        return new self(
            title: self::cleanLine($title),
            body: self::cleanBody($body),
            open: false,
            focused: false,
            titleStyle: Style::new(),
            focusedStyle: Style::new()->reverse(),
            openGlyph: self::OPEN_GLYPH,
            closedGlyph: self::CLOSED_GLYPH,
            indent: self::INDENT,
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /**
     * @return array{0: Disclosure, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$this->focused || !$msg instanceof KeyMsg) {
            return [$this, null];
        }
        $target = self::targetState($msg, $this->open);
        if ($target === null || $target === $this->open) {
            return [$this, null];
        }
        $next = $this->withOpen($target);
        return [$next, Cmd::send(new DisclosureToggledMsg($next->title, $target))];
    }

    /**
     * The open state a key asks for, or null when the key is not a
     * disclosure key. Shared with {@see CollapsibleList} so both read the
     * same keys.
     *
     * @internal
     */
    public static function targetState(KeyMsg $msg, bool $open): ?bool
    {
        if ($msg instanceof KeyReleaseMsg || $msg->ctrl || $msg->alt) {
            return null;
        }
        return match (true) {
            $msg->type === KeyType::Enter, $msg->type === KeyType::Space => !$open,
            $msg->type === KeyType::Right, self::isRune($msg, 'l') => true,
            $msg->type === KeyType::Left, self::isRune($msg, 'h') => false,
            default => null,
        };
    }

    public function view(): string
    {
        $glyph = $this->open ? $this->openGlyph : $this->closedGlyph;
        $header = ($this->focused ? $this->focusedStyle : $this->titleStyle)
            ->render($glyph . ' ' . $this->title);
        if (!$this->open || $this->body === '') {
            return $header;
        }
        $pad = str_repeat(' ', $this->indent);
        $lines = array_map(
            // Blank lines stay empty so the body carries no trailing spaces.
            static fn (string $line): string => $line === '' ? '' : $pad . $line,
            explode("\n", $this->body),
        );
        return $header . "\n" . implode("\n", $lines);
    }

    public function __toString(): string
    {
        return $this->view();
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withOpen(bool $open = true): self
    {
        return $this->mutate(['open' => $open]);
    }

    /** Flip the open state. */
    public function toggle(): self
    {
        return $this->withOpen(!$this->open);
    }

    public function withTitle(string $title): self
    {
        return $this->mutate(['title' => self::cleanLine($title)]);
    }

    public function withBody(string $body): self
    {
        return $this->mutate(['body' => self::cleanBody($body)]);
    }

    public function withFocused(bool $focused = true): self
    {
        return $this->mutate(['focused' => $focused]);
    }

    public function focus(): self
    {
        return $this->withFocused(true);
    }

    public function blur(): self
    {
        return $this->withFocused(false);
    }

    /** Title style while blurred. */
    public function withTitleStyle(Style $style): self
    {
        return $this->mutate(['titleStyle' => $style]);
    }

    /** Title style while focused (reverse video by default). */
    public function withFocusedStyle(Style $style): self
    {
        return $this->mutate(['focusedStyle' => $style]);
    }

    /** Replace the open and closed indicators; each is sanitized to one line. */
    public function withIndicators(string $open, string $closed): self
    {
        return $this->mutate([
            'openGlyph' => self::cleanLine($open),
            'closedGlyph' => self::cleanLine($closed),
        ]);
    }

    /** Spaces the open body is indented by; negatives clamp to 0. */
    public function withIndent(int $indent): self
    {
        return $this->mutate(['indent' => max(0, $indent)]);
    }

    private static function isRune(KeyMsg $msg, string $rune): bool
    {
        return $msg->type === KeyType::Char && $msg->rune === $rune;
    }

    /**
     * A title is one line. Escapes go first, whole, so no `[31m` remnant
     * survives; the zone sweep keeps a hostile title from forging
     * sugar-mouse markers in a marked frame; then the line breaks and tabs
     * that `untrusted()` keeps by design fold to spaces.
     */
    private static function cleanLine(string $text): string
    {
        return str_replace(["\r\n", "\n", "\r", "\t"], ' ', Sanitize::untrustedForMarkedFrames($text));
    }

    /**
     * The body keeps its SGR and nothing else: the text between well-formed
     * 7-bit SGR runs goes through `untrusted()`, so an OSC 52 clipboard
     * write, a clear-screen CSI, an 8-bit C1 introducer or a BEL in tool or
     * model output never reaches the terminal, while pre-styled text keeps
     * its colors. An SGR run is only `ESC [`, digits, `;`/`:` and `m`, so
     * keeping it whole can smuggle nothing. The zone sweep runs last, since
     * a sentinel would otherwise forge sugar-mouse markers in a marked frame
     * and `untrusted()` alone does not remove it.
     */
    private static function cleanBody(string $body): string
    {
        $parts = preg_split('/(\x1b\[[0-9;:]*m)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            // Fail closed: without the split, drop every escape, SGR included.
            $clean = Sanitize::untrusted($body);
        } else {
            $clean = '';
            foreach ($parts as $i => $part) {
                $clean .= $i % 2 === 1 ? $part : Sanitize::untrusted($part);
            }
        }

        return str_replace(["\r\n", "\r"], "\n", Sanitize::stripZoneSentinels($clean));
    }
}
