<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

/**
 * A {@see Field} that can restore itself from a persisted snapshot value
 * (SF-23, sugar-forms-025).
 *
 * `charmbracelet/huh` has no persistence story at all — there is no
 * upstream method to mirror; this is the SugarCraft extension that makes
 * `Form::hydrate($form->values())` an open world instead of a closed match
 * over the shipped concrete classes. Third-party fields opt in by
 * implementing this interface and gain identical treatment to the
 * built-ins; a plain {@see Field} implementation stays un-hydratable and
 * fails loud by name (an error message is not a corruption).
 *
 * The hook deliberately lives on a capability interface and NOT on
 * {@see Field} itself: adding a method to the interface would break every
 * third-party implementation's compile contract (the same ship-BC posture
 * that grew {@see AsyncValidatable}). Widening Field is a 2.0.0 candidate.
 *
 * Note the companion trait {@see HasHydration}: stateless helpers that
 * parse the raw snapshot into the shape the field's own setter accepts,
 * raising the byte-identical `form.hydrate_type` refusal.
 */
interface Hydratable extends Field
{
    /**
     * Return a field carrying the snapshot's value for this field.
     *
     * `$raw` is the untrusted persisted datum: an implementation either
     * parses it (throwing {@see \InvalidArgumentException} on a shape it
     * cannot accept) or declines it by recording a human-readable
     * divergence in `$notes` (keyed by {@see Field::key()}, the SF-22
     * told-not-thrown channel surfaced as `Form::$hydrateNotes`) and
     * returning itself. `$raw === null` only reaches here where
     * `value()` genuinely exports null (NumberField empty box, Date,
     * FilePicker, RadioPills with nothing under the cursor); the
     * field's own wither semantics govern what null means.
     *
     * @param array<string,string> $notes divergence notes, by reference
     */
    public function hydrateValue(mixed $raw, array &$notes = []): static;
}
