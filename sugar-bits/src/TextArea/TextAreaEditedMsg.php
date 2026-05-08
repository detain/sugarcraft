<?php

declare(strict_types=1);

namespace SugarCraft\Bits\TextArea;

use SugarCraft\Core\Msg;

/**
 * Dispatched after the external editor opened via Ctrl+O exits with
 * status 0. Carries the file's final contents; {@see TextArea::update()}
 * replaces its current value with `$value` on receipt.
 *
 * Editors that signal "discard" with a non-zero exit (e.g. vim `:cq`)
 * never produce this Msg — the temp file is dropped and the
 * pre-edit value is preserved.
 *
 * Mirrors the `editorFinishedMsg` pattern in upstream
 * `bubbles/textarea`'s editor example.
 */
final class TextAreaEditedMsg implements Msg
{
    public function __construct(public readonly string $value) {}
}
