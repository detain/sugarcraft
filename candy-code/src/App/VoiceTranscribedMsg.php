<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;

/**
 * The forked transcription child answered with text (C-TUI-6.l).
 *
 * WHY ITS OWN MSG. {@see \SugarCraft\Code\Media\Voice\TranscribeCmd::cmd()}
 * launches the upload off the update path — a whisper request can sit at the
 * endpoint for a while, and a TUI must not freeze beside it. A result that
 * lands seconds later has to re-enter the model through a message of its
 * own, the same split {@see StatusLineResultMsg} makes.
 *
 * The fold inserts the text at the caret (C-TUI-6.m). It carries no turn
 * generation and never crosses the wire: a late transcription is a late
 * draft, which is exactly what the user was composing.
 */
final class VoiceTranscribedMsg implements Msg
{
    public function __construct(
        public readonly string $text,
    ) {
    }
}
