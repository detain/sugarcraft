<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Attribute;

use Attribute;

/**
 * Marks a class as a CLI command for auto-discovery.
 *
 * Classes bearing this attribute are picked up by
 * {@see \SugarCraft\Shell\Discovery\CommandScanner} and registered
 * into the Application.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Command
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $descriptionSection = '',
    ) {
    }
}
