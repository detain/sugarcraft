<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use SugarCraft\Video\Decode\RgbFrame;

/**
 * A FakeDecoder that counts pulls and paces them at ~200µs each, so a test
 * can assert that a budgeted forward walk STOPPED (pull count stays small)
 * instead of trusting wall-clock luck — the pacing makes every budget
 * comparison between implementations comparable.
 */
class CountingDecoder extends FakeDecoder
{
    public int $pulls = 0;

    public function next(): ?RgbFrame
    {
        usleep(200);
        $this->pulls++;
        return parent::next();
    }
}
