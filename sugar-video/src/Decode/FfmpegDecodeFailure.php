<?php

declare(strict_types=1);

namespace SugarCraft\Video\Decode;

/**
 * The ffmpeg child died abnormally while frames were expected.
 *
 * WHY a distinct type: {@see \SugarCraft\Video\Decode\Decoder::next()}
 * documents null as "clean end of stream (or not ready yet)". Before this
 * class existed, ffmpeg exiting non-zero on a corrupt file, a rejected
 * filtergraph, or a mid-stream crash returned exactly that null — and the
 * Player closed the book on the session as "[ended]" over a black frame
 * (review M3/M12). A typed failure lets the Player catch THIS and only this:
 * generic RuntimeExceptions raised elsewhere in an update still propagate to
 * the host instead of being silently reinterpreted as a decode failure.
 *
 * The message is user-presentable by construction — exit code, a
 * credential-redacted and control-swept source sketch, and the tail of
 * ffmpeg's stderr — because the Player paints it on the error row.
 */
final class FfmpegDecodeFailure extends \RuntimeException
{
}
