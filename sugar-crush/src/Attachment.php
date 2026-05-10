<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * A file attachment for a message. Immutable value object.
 */
final readonly class Attachment
{
    public function __construct(
        public string $path,
        public AttachmentType $type,
    ) {}
}