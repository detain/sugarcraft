<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Media\MediaRequest;

/**
 * What the operator decided in the media form, as a kind plus the finished
 * draft the decision carries ({@see MediaFormActionKind}).
 *
 * The form is I/O-free by construction — same law as the
 * {@see \SugarCraft\Crush\Tui\DirectoryPicker\DirectoryPickerAction} it
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

/**
 * The action object the shell consumes: kind plus, for the kinds that
 * decide values, the final {@see MediaRequest}.
 */
final class MediaFormAction
{
    private function __construct(
        public readonly MediaFormActionKind $kind,
        public readonly ?MediaRequest $request,
    ) {
    }

    public static function cancel(): self
    {
        return new self(MediaFormActionKind::Cancel, null);
    }

    public static function generate(MediaRequest $request): self
    {
        return new self(MediaFormActionKind::Generate, $request);
    }

    public static function savePreset(MediaRequest $request): self
    {
        return new self(MediaFormActionKind::SavePreset, $request);
    }

    public static function importPng(): self
    {
        return new self(MediaFormActionKind::ImportPng, null);
    }
}
