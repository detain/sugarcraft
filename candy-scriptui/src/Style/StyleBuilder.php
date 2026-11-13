<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Style;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use SugarCraft\ScriptUi\Runtime\PositiveInt;
use SugarCraft\Style\Style;

/**
 * Builds a {@see \SugarCraft\Style\Style} from CLI flag values. Pure
 * (no IO) so commands can unit-test the mapping.
 */
final class StyleBuilder
{
    /**
     * @param array<string, string|bool|int|null> $flags
     *   Recognised keys: `foreground`, `background`, `border-foreground`,
     *   `border-background`, `bold`, `italic`, `underline`, `strikethrough`,
     *   `faint`, `blink`, `reverse`, `padding`, `margin`, `width`, `height`,
     *   `align`, `border`. Unknown keys are ignored. Non-empty but
     *   unrecognised `align`/`border` values throw — a typo'd enum must
     *   not fail-silent (scriptui-25, candy-scriptui-044).
     */
    public static function fromFlags(array $flags): Style
    {
        $s = Style::new();

        // Track the narrowest colour profile any flag implies so palette
        // indices render at their advertised SGR codes (e.g. "--fg 4"
        // emits CSI 34m, not the RGB equivalent).
        $minProfile = ColorProfile::TrueColor;

        // scriptui-25 (candy-scriptui-018): all four colour legs — content
        // AND border — feed one profile computation. The border legs used to
        // run after the single clamp and re-widen the applied profile from
        // outside the gate, so NO_COLOR leaked border colour.
        $colourLegs = [
            'foreground'        => static fn (Style $x, Color $c): Style => $x->foreground($c),
            'background'        => static fn (Style $x, Color $c): Style => $x->background($c),
            'border-foreground' => static fn (Style $x, Color $c): Style => $x->borderForeground($c),
            'border-background' => static fn (Style $x, Color $c): Style => $x->borderBackground($c),
        ];
        foreach ($colourLegs as $f => $apply) {
            $v = $flags[$f] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            [$color, $profile] = self::parseColorWithProfile((string) $v);
            if ($profile->value < $minProfile->value) {
                $minProfile = $profile;
            }
            $s = $apply($s, $color);
        }
        // Never re-widen past the ambient capability pinned by
        // ColourGate (a parsed palette index claiming Ansi must not
        // leak colour back under NO_COLOR's Ascii). One clamp, one
        // application — AFTER every colour leg.
        $minProfile = ColourGate::clamp($minProfile);
        if ($minProfile !== ColorProfile::TrueColor) {
            $s = $s->colorProfile($minProfile);
        }

        // scriptui-25 (candy-scriptui-043): blink and reverse are advertised
        // props — SubStyleParser normalises them and sugar-style renders
        // them — yet fromFlags used to drop them silently (the worst
        // contract combination: fail-loud on unknown, fail-silent on known).
        foreach (['bold', 'italic', 'underline', 'strikethrough', 'faint', 'blink', 'reverse'] as $attr) {
            if (!empty($flags[$attr])) {
                $s = match ($attr) {
                    'bold'          => $s->bold(),
                    'italic'        => $s->italic(),
                    'underline'     => $s->underline(),
                    'strikethrough' => $s->strikethrough(),
                    'faint'         => $s->faint(),
                    'blink'         => $s->blink(),
                    'reverse'       => $s->reverse(),
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
            $s = $s->width(PositiveInt::parse('width', $width));
        }

        $height = $flags['height'] ?? null;
        if ($height !== null && $height !== '' && $height !== false) {
            $s = $s->height(PositiveInt::parse('height', $height));
        }

        $align = $flags['align'] ?? null;
        if (is_string($align) && $align !== '') {
            $aligned = match (strtolower($align)) {
                'left'   => \SugarCraft\Style\Align::Left,
                'right'  => \SugarCraft\Style\Align::Right,
                'center' => \SugarCraft\Style\Align::Center,
                default  => null,
            };
            if ($aligned === null) {
                // scriptui-25 (candy-scriptui-044): `align=middle` used to
                // no-op silently. Refuse with the allowed list instead.
                throw new \InvalidArgumentException(Lang::t('runtime.unknown_choice', [
                    'option'  => 'align',
                    'value'   => $align,
                    'allowed' => 'left|center|right',
                ]));
            }
            $s = $s->align($aligned);
        }

        // Border. `border <preset>` picks one of normal/rounded/thick/
        // double/block/ascii/hidden (plus the numeric spellings 0/1).
        $border = $flags['border'] ?? null;
        if (is_string($border) && $border !== '') {
            $b = match (strtolower($border)) {
                'normal',  '1'        => \SugarCraft\Style\Border::normal(),
                'rounded'             => \SugarCraft\Style\Border::rounded(),
                'thick'               => \SugarCraft\Style\Border::thick(),
                'double'              => \SugarCraft\Style\Border::double(),
                'block'               => \SugarCraft\Style\Border::block(),
                'ascii'               => \SugarCraft\Style\Border::ascii(),
                'hidden', '0', 'none' => null,
                default               => null,
            };
            if ($b === null && !in_array(strtolower($border), ['hidden', '0', 'none'], true)) {
                throw new \InvalidArgumentException(Lang::t('runtime.unknown_choice', [
                    'option'  => 'border',
                    'value'   => $border,
                    'allowed' => 'none|normal|rounded|thick|double|block|ascii|hidden',
                ]));
            }
            if ($b !== null) {
                $s = $s->border($b);
            }
        }

        return $s;
    }

    /**
     * The 16 canonical ANSI palette names (gum accepts these for
     * `--foreground`/`--background`), case-insensitive. No name is a
     * valid 3- or 6-digit bare hex token, so this lookup can sit
     * before the hex branches without shadowing any colour literal.
     *
     * @var array<string,int>
     */
    private const NAMED_COLOURS = [
        'black'          => 0,
        'red'            => 1,
        'green'          => 2,
        'yellow'         => 3,
        'blue'           => 4,
        'magenta'        => 5,
        'cyan'           => 6,
        'white'          => 7,
        'bright-black'   => 8,
        'bright-red'     => 9,
        'bright-green'   => 10,
        'bright-yellow'  => 11,
        'bright-blue'    => 12,
        'bright-magenta' => 13,
        'bright-cyan'    => 14,
        'bright-white'   => 15,
    ];

    /** Human-readable accepted-input summary appended to colour refusals. */
    public const COLOUR_ALLOWED_HINT =
        'black, red, green, yellow, blue, magenta, cyan, white (each also bright-*), '
        . '#rgb / #rrggbb (bare hex too), or a palette index 0-255';

    /**
     * Accept hex (`#ff8000` / `#f80`), 0-15 ANSI palette indices,
     * 0-255 256-colour palette indices, or the 16 canonical palette
     * names (`red`, `bright-blue`, …).
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
     *   - palette index 0-15 or a name → Ansi
     *
     * @return array{0:Color, 1:ColorProfile}
     */
    public static function parseColorWithProfile(string $v): array
    {
        $v = trim($v);
        if ($v === '') {
            throw new \InvalidArgumentException(Lang::t('style.empty_color'));
        }
        $named = strtolower($v);
        if (isset(self::NAMED_COLOURS[$named])) {
            return [Color::ansi(self::NAMED_COLOURS[$named]), ColorProfile::Ansi];
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
        throw new \InvalidArgumentException(Lang::t('style.unrecognised_color', [
            'value'   => $v,
            'allowed' => self::COLOUR_ALLOWED_HINT,
        ]));
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
