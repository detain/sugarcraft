<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests\Support;

use SugarCraft\Ansi\Parser\SubparamsAwareHandler;

/**
 * Same recorder, additionally opted into the
 * {@see SubparamsAwareHandler} capability.
 *
 * Extending {@see DispatchRecorder} (rather than duplicating it) is what makes
 * the degradation test meaningful: both handlers run the identical
 * argument-formatting code, so any difference between their `calls` logs can
 * only come from the parser — not from two fixtures drifting apart.
 */
final class CapableDispatchRecorder extends DispatchRecorder implements SubparamsAwareHandler
{
    /** @var list<list<bool>> every list handed to {@see setSubparams()} */
    public array $pushes = [];

    /** @var list<string> `calls` plus the pushes, in true interleaved order */
    public array $timeline = [];

    /**
     * @param list<bool> $subparams
     */
    public function setSubparams(array $subparams): void
    {
        $this->pushes[] = $subparams;
        $this->timeline[] = 'push:' . json_encode($subparams, JSON_THROW_ON_ERROR);
    }

    protected function emit(string $entry): void
    {
        parent::emit($entry);
        $this->timeline[] = $entry;
    }
}
