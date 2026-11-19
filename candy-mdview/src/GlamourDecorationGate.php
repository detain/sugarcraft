<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

/**
 * The one refusal candy-mdview keeps in front of sugar-markdown's
 * {@see \SugarCraft\Markdown\StyleGuide} loader (P1-12c-mdview, owner option b).
 *
 * A glamour style file's document and paragraph `block_prefix` /
 * `block_suffix` carry glamour's vertical spacing, so only inert whitespace
 * (`[\n\t ]*`) may cross into the render. StyleGuide deliberately accepts
 * visible glyph affixes (its own pinned `«` / `P> ` cases), so the whitespace
 * law is applied here, before the loader runs. A payload-bearing affix (an
 * OSC title-set, raw SGR, a forged OSC 8 envelope) refuses by slot name.
 *
 * Non-string affix values coerce to '' before the loader sees them. This is
 * the door's long-standing contract: StyleGuide's `(string)` cast would emit
 * "Array to string conversion" for a nested value and render a number as a
 * glyph. The coercion is not a refusal and never names a slot.
 */
final class GlamourDecorationGate
{
    /** The four affix slots that cross into the render, in refusal order. */
    private const AFFIX_SLOTS = [
        ['document', 'block_prefix'],
        ['document', 'block_suffix'],
        ['paragraph', 'block_prefix'],
        ['paragraph', 'block_suffix'],
    ];

    /**
     * Refuse or normalize the affix slots of a decoded glamour document.
     *
     * An absent or non-array element block is skipped, exactly as
     * StyleGuide skips it, so the gate never invents a slot.
     *
     * @param array<string, mixed> $decoded a document that carries the glamour signature
     * @return array<string, mixed> the same document, with non-string affixes set to ''
     * @throws \InvalidArgumentException naming the first slot that carries a non-whitespace affix
     */
    public static function admit(array $decoded): array
    {
        foreach (self::AFFIX_SLOTS as [$element, $field]) {
            if (!is_array($decoded[$element] ?? null)) {
                continue;
            }
            if (!array_key_exists($field, $decoded[$element])) {
                continue;
            }

            $value = $decoded[$element][$field];
            if (!is_string($value)) {
                $decoded[$element][$field] = '';
                continue;
            }
            if (preg_match('/\A[\n\t ]*\z/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    Lang::t('glamour.decoration_unsafe', ['slot' => $element . '.' . $field])
                );
            }
        }

        return $decoded;
    }
}
