<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

/**
 * Identity of one window slot: which kind of thing it is, a machine key it
 * can be addressed by, the label the strip paints, an optional tint from the
 * agent-preset `color:` vocabulary ({@see AgentColor::resolve()}), and the
 * parent key an agent carries to name the session it rides beneath.
 *
 * The key must stay inside sugar-mouse's click-zone id charset (ASCII
 * letters, digits, and `.` `_` `:` `-`); {@see WindowStrip} degrades an
 * out-of-charset key to "painted, not clickable" exactly as
 * {@see \SugarCraft\Code\Renderer}'s zone gate does, so a model-supplied key
 * can never throw during a frame.
 *
 * Immutable value object: every `with*()` answers with a new instance.
 */
final class WindowRef
{
    /** The zone-id alphabet sugar-mouse's {@see \SugarCraft\Mouse\Mark} accepts. */
    private const KEY_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._:-';

    public function __construct(
        public readonly WindowKind $kind,
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $color = null,
        public readonly ?string $parentKey = null,
    ) {}

    /** A session slot; a session has nothing to be a child of. */
    public static function session(string $key, string $label, ?string $color = null): self
    {
        return new self(WindowKind::Session, $key, $label, $color);
    }

    /** An agent slot under $parentKey. */
    public static function agent(string $key, string $label, string $parentKey, ?string $color = null): self
    {
        return new self(WindowKind::Agent, $key, $label, $color, $parentKey);
    }

    /** The same slot renamed — session titles and agent names change live. */
    public function withLabel(string $label): self
    {
        return new self($this->kind, $this->key, $label, $this->color, $this->parentKey);
    }

    /** The same slot with its `color:` vocabulary entry swapped in. */
    public function withColor(?string $color): self
    {
        return new self($this->kind, $this->key, $this->label, $color, $this->parentKey);
    }

    /** Whether $key may carry a click zone (sugar-mouse's charset and length). */
    public static function keyIsZoneable(string $key): bool
    {
        if ($key === '' || strlen($key) > \SugarCraft\Mouse\Mark::MAX_ID_BYTES) {
            return false;
        }

        // A strspn walk rather than a preg charset: a bracket-class regex
        // literal is a glob-shaped string for the tree's corpus figures.
        return strspn($key, self::KEY_ALPHABET) === strlen($key);
    }
}
