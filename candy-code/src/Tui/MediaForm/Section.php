<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Forms\Field;

/**
 * One tab of the media form: its rows, what may be edited on them, and how
 * its widgets fold back into the draft request (crush-media W3.1 shell;
 * W3.4's FieldSpec registry will ship the real sections through this door).
 *
 * The contract is the shape {@see MediaForm} renders and collects without
 * knowing what any section is: `rows()` says what is on screen, `fieldAt()`
 * says which rows own an editable sugar-forms Field (or null for read-only
 * rows), and `collect()` folds the section's current widget state into the
 * draft. A section never performs I/O and never builds a wire request of its
 * own — the draft is singular, folded section by section.
 */
interface Section
{
    /** Stable id, used as the tab's zone suffix and never shown. */
    public function key(): string;

    /** The tab label, already translated. */
    public function label(): string;

    /** Whether this section is on the tab strip for the given focus. */
    public function visible(MediaFormState $state): bool;

    /** Row labels in display order; the index is the row's zone id. */
    public function rows(): array;

    /** The editable field for a row, or null when the row is read-only. */
    public function fieldAt(int $row): ?Field;

    /**
     * The section holding $field on the row that row currently edits (the
     * form commits a live field back through this door); a field the section
     * does not own leaves it as it was.
     */
    public function withField(Field $field): self;

    /** The row's current display value (already a string, cell-safe). */
    public function valueAt(int $row): string;

    /** Fold this section's widgets into $draft and return the new draft. */
    public function collect(MediaRequest $draft): MediaRequest;
}
