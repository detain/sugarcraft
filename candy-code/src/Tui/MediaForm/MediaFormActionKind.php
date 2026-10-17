<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

/**
 * What the operator decided in the media form, as a kind (the finished draft
 * the decision carries rides on {@see MediaFormAction}).
 *
 * The form is I/O-free by construction — same law as the
 * {@see \SugarCraft\Code\Tui\DirectoryPicker\DirectoryPickerAction} it
 * mirrors: the widget model returns an action object, and the host (the App
 * shell) executes the consequence. The draft rides as the sdapi wire map's
 * source, so the Generate action hands the host exactly what
 * `GenerateImage::execute()` takes, folded from every visible section at the
 * moment the key went down.
 */
enum MediaFormActionKind: string
{
    /** `Esc` with nothing being edited: throw the draft away and close. */
    case Cancel = 'cancel';

    /** `Enter` on the frame: generate from the collected draft. */
    case Generate = 'generate';

    /** Save the draft as a named preset (bound in W3.7 — no key emits it yet). */
    case SavePreset = 'save-preset';

    /** Import parameters out of a PNG's infotext (W4; no key emits it yet). */
    case ImportPng = 'import-png';
}
