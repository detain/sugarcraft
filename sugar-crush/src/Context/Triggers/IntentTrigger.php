<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Triggers;

use InvalidArgumentException;

/**
 * A one-line self-description offered to the model in the trigger listing.
 *
 * DESIGN SOURCE: prompt_expand.md §9.13 (third member of the trigger trio,
 * renamed from upstream OpenHands' TaskTrigger to IntentTrigger by this plan)
 * and §7.5/§9.7 (union placement). This is a SugarCraft architecture type,
 * not a port — charmbracelet/crush has no IntentTrigger symbol, so the
 * repo's "Mirrors charmbracelet/…" convention does not apply.
 *
 * WHY IT HAS NO `matches()` (and why that is honest, not a gap in the
 * interface): an intent never fires from PHP string logic. The listing prints
 * its description and the MODEL decides; there is no candidate string to
 * match against. Giving this class a `matches()` would be a method that lies,
 * which is exactly why {@see Trigger} is a zero-method marker and class
 * identity is the discriminator.
 *
 * TRUNCATION BOUNDARY — the decision this step pins. The listing is a
 * byte-budgeted layer, so a runaway description must not push it over:
 * - The unit is CHARACTERS (`mb_*`), not bytes: one accented letter costs one
 *   slot, and truncation can never split a multi-byte character, because
 *   every cut goes on a character boundary by construction.
 * - The cut keeps `maxChars - 1` leading characters and appends U+2026 (…)
 *   for exactly `maxChars` characters out. With `maxChars = 1` the emission
 *   is the bare ellipsis.
 * - Descriptions of length <= maxChars pass through byte-identical: the
 *   ellipsis appears ONLY when a cut actually happened, so short intents are
 *   never decorated.
 * - `description` remains the full truth on the value; truncation is a
 *   rendering projection (`truncated()`), never a mutation of stored state —
 *   consistent with this being an immutable value object.
 * - Default ceiling: 160 characters, a line-length convention, exposed as
 *   `self::DEFAULT_MAX_CHARS` and overridable per value via `withMaxChars()`.
 */
final class IntentTrigger implements Trigger
{
    public const int DEFAULT_MAX_CHARS = 160;

    /**
     * @param string $description The intent as offered to the model, untruncated.
     * @param int    $maxChars    Character ceiling for {@see truncated()}; at least 1.
     */
    private function __construct(
        public readonly string $description,
        public readonly int $maxChars = self::DEFAULT_MAX_CHARS,
    ) {
    }

    /**
     * @throws InvalidArgumentException on a blank description or a ceiling below 1 character.
     */
    public static function new(string $description, int $maxChars = self::DEFAULT_MAX_CHARS): self
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('IntentTrigger requires a non-blank description; an unnamed intent cannot inform the model.');
        }
        if ($maxChars < 1) {
            throw new InvalidArgumentException(sprintf('IntentTrigger maxChars must be at least 1, %d given.', $maxChars));
        }

        return new self($description, $maxChars);
    }

    /**
     * The same trigger over a different description.
     */
    public function withDescription(string $description): self
    {
        return $this->mutate($description, $this->maxChars);
    }

    /**
     * The same trigger with a different truncation ceiling.
     */
    public function withMaxChars(int $maxChars): self
    {
        return $this->mutate($this->description, $maxChars);
    }

    private function mutate(string $description, int $maxChars): self
    {
        return self::new($description, $maxChars);
    }

    /**
     * Whether {@see truncated()} would emit exactly the stored description.
     */
    public function isTruncated(): bool
    {
        return mb_strlen($this->description) > $this->maxChars;
    }

    /**
     * The listing projection of the description, cut per the class-docblock
     * boundary: at most `maxChars` characters, ending in U+2026 only when a
     * cut happened, never splitting a multi-byte character.
     */
    public function truncated(): string
    {
        if (!$this->isTruncated()) {
            return $this->description;
        }

        return mb_substr($this->description, 0, $this->maxChars - 1) . '…';
    }
}
