<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Media\MediaRequest;

/**
 * A section whose rendered rows recompute from the LIVE draft rather than
 * only its own fields (crush-media W3.5): the Hires accordion's final-size
 * preview reads width/height/hr_scale from whatever the user last committed
 * anywhere in the form. {@see MediaForm} applies the context in bodyLines()
 * immediately before painting the focused section, so the preview can never
 * drift from what collect() would fold.
 */
interface DraftContextSection
{
    /** Same section, remembering the draft its view rows read from. */
    public function withDraftContext(MediaRequest $context): self;
}
