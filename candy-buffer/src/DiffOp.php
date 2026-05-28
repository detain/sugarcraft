<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * Placeholder diff operation for Buffer::diff().
 *
 * The concrete shape of DiffOp (replace, insert, delete, etc.) is
 * determined in step-26 when the delta-ANSI emitter is built.
 * For now this is a minimal stub so the Buffer::diff() signature
 * compiles.
 *
 * @todo step-26 — define concrete DiffOp variants and implement Buffer::diff().
 *
 * @readonly
 */
final class DiffOp
{
    public const TYPE_REPLACE = 'replace';
    public const TYPE_INSERT   = 'insert';
    public const TYPE_DELETE   = 'delete';

    public function __construct(
        public readonly string $type,
        public readonly int $col,
        public readonly int $row,
        public readonly ?Cell $cell = null,
    ) {}
}
