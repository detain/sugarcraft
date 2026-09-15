<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use RuntimeException;

/**
 * Thrown when a byte stream cannot be parsed as the graphics protocol it claims.
 *
 * Fail-fast: a decoder never returns a half-built image. Every structural
 * violation — missing introducer, unterminated string, bad base64, an RLE run
 * that overflows the raster — raises this with a precise, i18n'd reason.
 */
final class MalformedGraphicsException extends RuntimeException
{
}
