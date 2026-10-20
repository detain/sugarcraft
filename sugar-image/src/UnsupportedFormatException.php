<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Thrown when a container is recognised by magic bytes but carries a feature
 * (or codec) this library does not decode.
 *
 * E-05/E-06: the difference between "corrupt file" and "valid file we refuse
 * to pretend to understand" matters to callers — the first is retried or
 * reported as damage, the second must name the missing capability and where
 * to get it (chafa CLI / ffmpeg). Today both arrive as a generic
 * InvalidArgumentException ("GD failed to load image"), and on hosts whose
 * libwebp demuxes animated WebP to its first frame the file is not refused at
 * all — it silently degrades to a one-frame still (survey E-05).
 *
 * Extends \InvalidArgumentException so callers that already catch the whole
 * "bad input" family keep catching this: the change refines the message, it
 * does not widen the blast radius.
 */
final class UnsupportedFormatException extends \InvalidArgumentException
{
}
