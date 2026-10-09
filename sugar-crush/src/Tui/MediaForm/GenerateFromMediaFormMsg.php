<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\App\Msg;
use SugarCraft\Crush\Media\MediaRequest;

/**
 * "Generate this" — the shell's answer to a {@see MediaFormActionKind::Generate}
 * action, handed to the chat so the run joins the transcript exactly where
 * the typed `/generate …` command puts it. One dispatch, one pipeline: the
 * request's wire map rides into `GenerateImage::execute()` through
 * `GenerateHostCommand::runRequest()`, the same body the typed path runs.
 */
final readonly class GenerateFromMediaFormMsg implements Msg
{
    public function __construct(public MediaRequest $request)
    {
    }
}
