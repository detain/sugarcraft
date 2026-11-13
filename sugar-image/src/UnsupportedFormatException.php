<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Thrown when a container is recognised by magic bytes but carries a feature
 * (or codec) this library does not decode.
 *
 * The difference between "corrupt file" and "valid file we refuse
 * to pretend to understand" matters to callers — the first is retried or
 * reported as damage, the second must name the missing capability and where
 * to get it (for these containers that answer is the chafa CLI; animation
 * belongs to sugar-video, which decodes the frames).
 *
 * Before this exception existed both arrived as a generic
 * InvalidArgumentException ("GD failed to load image"), and on hosts whose
 * libwebp demuxed animated WebP to its first frame the file was not refused at
 * all — it silently degraded to a one-frame still. Now each case is named:
 * animated WebP throws with the decoder that handles it, and an undecodable
 * container throws with the CLI that would.
 *
 * Extends \InvalidArgumentException so callers that already catch the whole
 * "bad input" family keep catching this: the change refines the message, it
 * does not widen the blast radius.
 *
 * Mirrors no upstream — SugarCraft exception. The magic-known /
 * feature-unsupported split is this library's intake taxonomy.
 */
final class UnsupportedFormatException extends \InvalidArgumentException
{
}
