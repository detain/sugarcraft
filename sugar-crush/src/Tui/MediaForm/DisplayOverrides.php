<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Lang;

/**
 * The ui-config display-override analog (crush-media W3.9, plan §5.5): the
 * `ui.mediaDisplayOverrides` map keys one entry per row prop in A1111's
 * element-id convention `"<section>/<Label>/<prop>"` — the verbatim example
 * being `txt2img/CFG Scale/step` — and the props are exactly
 * {@see PROPS}: value, minimum, maximum, step, visible.
 *
 * A1111 resolves these ids against live Gradio component paths; the media
 * form's lattice is static, so {@see elementId()} derives the id from the
 * FieldSpec itself (its section key plus the translated row label).
 * TOLERANCE PARITY IS THE POINT: an id nothing resolves to — stale, from
 * another tab naming, or a literal A1111 string — is silently ignored. One
 * dead key must never fail a generation (§5.5).
 *
 * The transform is pure over copied specs: ranges move, `value` re-seeds the
 * row's default (the pre-fill), and `visible: false` drops the row entirely
 * — out of render AND out of collect, which is the W3.5 hidden-row law
 * applied at lattice-build time.
 */
final class DisplayOverrides
{
    public const string PROP_VALUE = 'value';
    public const string PROP_MINIMUM = 'minimum';
    public const string PROP_MAXIMUM = 'maximum';
    public const string PROP_STEP = 'step';
    public const string PROP_VISIBLE = 'visible';

    /** The five props A1111 ui-config knows; anything else is inert (§5.5). */
    public const array PROPS = [
        self::PROP_VALUE,
        self::PROP_MINIMUM,
        self::PROP_MAXIMUM,
        self::PROP_STEP,
        self::PROP_VISIBLE,
    ];

    /** The id one row is addressed under, without a prop suffix. */
    public static function elementId(FieldSpec $spec): string
    {
        return $spec->section . '/' . Lang::t($spec->labelKey);
    }

    /** The full override key for one row prop. */
    public static function keyFor(FieldSpec $spec, string $prop): string
    {
        return self::elementId($spec) . '/' . $prop;
    }

    /**
     * The typed-valid subset of the map for one row: a prop counts only when
     * its key is present AND its shape fits the widget — booleans for
     * `visible`, numerics for the range props of a slider/number row, a
     * widget-matched type for `value`. Anything else reads as no override at
     * all, never as a coercion attempt.
     *
     * @param array<string, mixed> $overrides the whole ui.mediaDisplayOverrides map
     * @return array<string, mixed> keyed by the PROP_ constants
     */
    public static function forElement(FieldSpec $spec, array $overrides): array
    {
        $element = self::elementId($spec);
        $props = [];
        foreach (self::PROPS as $prop) {
            $path = $element . '/' . $prop;
            if (!\array_key_exists($path, $overrides)) {
                continue;
            }

            $raw = $overrides[$path];
            if (self::accepts($spec, $prop, $raw)) {
                $props[$prop] = $raw;
            }
        }

        return $props;
    }

    /**
     * Fold the map into a row lattice, returning `[keptSpecs, changedProps]`
     * — keptSpecs keyed exactly like the input minus any `visible:false`
     * rows, changedProps keyed by wire key with the row's accepted props (so
     * the owner can reshape only the widgets the ranges actually moved on).
     *
     * @param array<string, FieldSpec> $specs
     * @param array<string, mixed> $overrides
     * @return array{0: array<string, FieldSpec>, 1: array<string, array<string, mixed>>}
     */
    public static function apply(array $specs, array $overrides): array
    {
        if ($overrides === []) {
            return [$specs, []];
        }

        $kept = [];
        $changed = [];
        foreach ($specs as $key => $spec) {
            $props = self::forElement($spec, $overrides);
            if (($props[self::PROP_VISIBLE] ?? true) === false) {
                continue;
            }

            unset($props[self::PROP_VISIBLE]);
            if ($props !== []) {
                $spec = $spec->withDisplayProps($props);
                $changed[$key] = $props;
            }

            $kept[$key] = $spec;
        }

        return [$kept, $changed];
    }

    /** Whether this prop shape is meaningful for this widget (§5.5 typed tolerance). */
    private static function accepts(FieldSpec $spec, string $prop, mixed $raw): bool
    {
        if ($prop === self::PROP_VISIBLE) {
            return \is_bool($raw);
        }

        if ($prop !== self::PROP_VALUE) {
            return ($spec->widget === FieldSpec::WIDGET_SLIDER || $spec->widget === FieldSpec::WIDGET_NUMBER)
                && (is_int($raw) || is_float($raw));
        }

        return match ($spec->widget) {
            FieldSpec::WIDGET_SLIDER, FieldSpec::WIDGET_NUMBER => is_int($raw) || is_float($raw),
            FieldSpec::WIDGET_TEXT => \is_string($raw),
            FieldSpec::WIDGET_SELECT, FieldSpec::WIDGET_RADIO => \is_string($raw),
            FieldSpec::WIDGET_CONFIRM => \is_bool($raw),
            default => false,
        };
    }
}
