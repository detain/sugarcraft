<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Style;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Shell\Lang;
use SugarCraft\Sprinkles\Style;

/**
 * Builds a {@see \SugarCraft\Sprinkles\Style} from CLI flag values. Pure
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

        $height = $flags['height'] ?? null;
        if ($height !== null && $height !== '' && $height !== false) {
            $s = $s->height((int) $height);
        }

        $align = $flags['align'] ?? null;
        if (is_string($align) && $align !== '') {
            $aligned = match (strtolower($align)) {
                'left'   => \SugarCraft\Sprinkles\Align::Left,
                'right'  => \SugarCraft\Sprinkles\Align::Right,
                'center' => \SugarCraft\Sprinkles\Align::Center,
                default  => null,
            };
            if ($aligned !== null) {
                $s = $s->align($aligned);
            }
        }

        // Border. `--border <preset>` picks one of normal/rounded/thick/
        // double/block/hidden. `--border-foreground` / `-background`
        // accept the same colour syntax as the content colours.
        $border = $flags['border'] ?? null;
        if (is_string($border) && $border !== '') {
            $b = match (strtolower($border)) {
                'normal',  '1' => \SugarCraft\Sprinkles\Border::normal(),
                'rounded'      => \SugarCraft\Sprinkles\Border::rounded(),
                'thick'        => \SugarCraft\Sprinkles\Border::thick(),
                'double'       => \SugarCraft\Sprinkles\Border::double(),
                'block'        => \SugarCraft\Sprinkles\Border::block(),
                'hidden', '0', 'none' => null,
                default        => null,
            };
            if ($b !== null) {
                $s = $s->border($b);
            }
        }
        $bf = $flags['border-foreground'] ?? null;
        if (is_string($bf) && $bf !== '') {
            [$color, $bp] = self::parseColorWithProfile($bf);
            $s = $s->borderForeground($color);
            if ($bp->value < $minProfile->value) {
                $minProfile = $bp;
                $s = $s->colorProfile($bp);
            }
        }
        $bb = $flags['border-background'] ?? null;
        if (is_string($bb) && $bb !== '') {
            [$color, $bp] = self::parseColorWithProfile($bb);
            $s = $s->borderBackground($color);
            if ($bp->value < $minProfile->value) {
                $minProfile = $bp;
                $s = $s->colorProfile($bp);
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
            throw new \InvalidArgumentException(Lang::t('style.empty_color'));
        }
        if ($v[0] === '#') {
            return [self::parseHexColor($v), ColorProfile::TrueColor];
        }
        if (ctype_digit($v)) {
            $n = (int) $v;
            if ($n < 16) {
                return [Color::ansi($n), ColorProfile::Ansi];
            }
            return [Color::ansi256($n), ColorProfile::Ansi256];
        }
        // Bare hex digits (no '#') are also treated as hex colour input, so
        // a wrong digit count gets the specific hex error, not the generic one.
        if (ctype_xdigit($v)) {
            return [self::parseHexColor('#' . $v), ColorProfile::TrueColor];
        }
        throw new \InvalidArgumentException(Lang::t('style.unrecognised_color', ['value' => $v]));
    }

    /**
     * Parse a hex colour (`#ff8000`, `ff8000`, `#f80`, `f80`), expanding the
     * 3-digit CSS shorthand to 6 digits. Any other digit count is rejected
     * with a message naming the offending value — before this existed, inputs
     * like `#1234` fell through to a generic "unrecognised color" error.
     */
    public static function parseHexColor(string $v): Color
    {
        $h = ltrim($v, '#');
        $len = strlen($h);
        if (($len !== 3 && $len !== 6) || !ctype_xdigit($h)) {
            throw new \InvalidArgumentException(
                Lang::t('style.hex_digit_count', ['value' => $v]),
            );
        }
        if ($len === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return Color::hex('#' . $h);
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
                    Lang::t('style.padding_token_int', ['token' => $token]),
                );
            }
            $ints[] = (int) $token;
        }

        if (!in_array(count($ints), [1, 2, 4], true)) {
            throw new \InvalidArgumentException(
                Lang::t('style.padding_count', ['count' => count($ints)]),
            );
        }
        return $ints;
    }
}
