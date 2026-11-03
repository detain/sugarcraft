<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Snapshot;

/**
 * Thrown by {@see GoldenFile::create()} when the golden already exists.
 *
 * Creation is the first-baseline path; a lost create race or a stale
 * expectation surfaces as this typed error so callers (Assertions) can
 * fall back to comparing instead of silently overwriting.
 */
final class GoldenFileExistsException extends \RuntimeException
{
}
