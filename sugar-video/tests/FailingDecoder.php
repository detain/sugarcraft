<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use SugarCraft\Video\Decode\FfmpegDecodeFailure;
use SugarCraft\Video\Decode\RgbFrame;

/**
 * A FakeDecoder that reports a crashed media child, exactly as FfmpegDecoder
 * does when ffmpeg dies abnormally mid-stream (M12).
 *
 * next() throws the TYPED failure the Player's update() choke converts into
 * an ended "[error]" state — these tests prove that conversion without
 * spawning a real ffmpeg.
 */
final class FailingDecoder extends FakeDecoder
{
    public int $nextCount = 0;

    public function __construct(
        private readonly string $message = 'sugar-video: ffmpeg exited with code 7 before the stream ended (/tmp/clip.mp4)',
    ) {
        parent::__construct([]);
    }

    public function next(): ?RgbFrame
    {
        $this->nextCount++;

        throw new FfmpegDecodeFailure($this->message);
    }
}
