<?php

declare(strict_types=1);

namespace CandyCore\Shell\Style;

use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Sprinkles\Style;

/**
 * Builds a {@see \CandyCore\Sprinkles\Style} from CLI flag values. Pure
 * (no IO) so commands can unit-test the mapping.
 */
final class StyleBuilder
{
    /**
     * @param array<string, string|bool|int|null> $flags
     *   Recognised keys: `foreground`, `background`, `bold`, `italic`,
     *   `underline`, `strikethrough`, `faint`, `padding`, `margin`,
     *   `width`, `align`. Unknown keys are ignored.
     */
    public static function fromFlags(array $flags): Style
    {
        $s = Style::new();

        // Track the narrowest colour profile any flag implies so palette
        // indices render at their advertised SGR codes (e.g. "--fg 4"
        // emits CSI 34m, not the RGB equivalent).
        $minProfile = ColorProfile::TrueColor;

        foreach (['foreground', 'background'] as $f) {
            $v = $flags[$f] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            [$color, $profile] = self::parseColorWithProfile((string) $v);
            if ($profile->value < $minProfile->value) {
                $minProfile = $profile;
            }
            $s = $f === 'foreground' ? $s->foreground($color) : $s->background($color);
        }
        if ($minProfile !== ColorProfile::TrueColor) {
            $s = $s->colorProfile($minProfile);
        }

        foreach (['bold', 'italic', 'underline', 'strikethrough', 'faint'] as $attr) {
            if (!empty($flags[$attr])) {
                $s = match ($attr) {
                    'bold'          => $s->bold(),
                    'italic'        => $s->italic(),
                    'underline'     => $s->underline(),
                    'strikethrough' => $s->strikethrough(),
                    'faint'         => $s->faint(),
                };
            }
        }

        foreach (['padding' => 'padding', 'margin' => 'margin'] as $key => $method) {
            $raw = $flags[$key] ?? null;
            if ($raw === null || $raw === '' || $raw === false) {
                continue;
            }
            $values = self::parseSides((string) $raw);
            $s = $method === 'padding' ? $s->padding(...$values) : $s->margin(...$values);
        }

        $width = $flags['width'] ?? null;
        if ($width !== null && $width !== '' && $width !== false) {
            $s = $s->width((int) $width);
        }

        $align = $flags['align'] ?? null;
        if (is_string($align) && $align !== '') {
            $aligned = match (strtolower($align)) {
                'left'   => \CandyCore\Sprinkles\Align::Left,
                'right'  => \CandyCore\Sprinkles\Align::Right,
                'center' => \CandyCore\Sprinkles\Align::Center,
                default  => null,
            };
            if ($aligned !== null) {
                $s = $s->align($aligned);
            }
        }

        return $s;
    }

    /**
     * Accept hex (`#ff8000` / `#f80`), 0-15 ANSI palette indices, or
     * 0-255 256-colour palette indices.
     */
    public static function parseColor(string $v): Color
    {
        return self::parseColorWithProfile($v)[0];
    }

    /**
     * Like {@see parseColor()} but also returns the narrowest
     * {@see ColorProfile} the input requires:
     *   - hex / 6-digit / 3-digit  → TrueColor
     *   - palette index 16-255      → Ansi256
     *   - palette index 0-15        → Ansi
     *
     * @return array{0:Color, 1:ColorProfile}
     */
    public static function parseColorWithProfile(string $v): array
    {
        $v = trim($v);
        if ($v === '') {
            throw new \InvalidArgumentException('empty color');
        }
        if ($v[0] === '#') {
            return [Color::hex($v), ColorProfile::TrueColor];
        }
        if (ctype_digit($v)) {
            $n = (int) $v;
            if ($n < 16) {
                return [Color::ansi($n), ColorProfile::Ansi];
            }
            return [Color::ansi256($n), ColorProfile::Ansi256];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $v) === 1 || preg_match('/^[0-9a-fA-F]{3}$/', $v) === 1) {
            return [Color::hex('#' . $v), ColorProfile::TrueColor];
        }
        throw new \InvalidArgumentException("unrecognised color: $v");
    }

    /**
     * Parse a CSS-style padding/margin shorthand: `1`, `1,2`, `1,2,3,4`.
     * Tokens must be valid integers; non-numeric inputs (`foo`, `1bar`)
     * raise an `InvalidArgumentException` rather than being coerced to
     * 0/1 silently.
     *
     * @return list<int>
     */
    public static function parseSides(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        $ints = [];
        foreach ($parts as $token) {
            // Accept optional leading sign + at least one digit, nothing else.
            if (preg_match('/^-?\d+$/', $token) !== 1) {
                throw new \InvalidArgumentException(
                    "padding/margin token must be an integer; got: '$token'",
                );
            }
            $ints[] = (int) $token;
        }

        if (!in_array(count($ints), [1, 2, 4], true)) {
            throw new \InvalidArgumentException(
                'padding/margin needs 1, 2, or 4 integers; got: ' . count($ints),
            );
        }
        return $ints;
    }
}
