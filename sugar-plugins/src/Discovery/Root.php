<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Discovery;

/**
 * One directory a discovery walk starts from, with the label its bundles are
 * recorded under and the tree they must stay inside.
 *
 * A null boundary is an operator-named path: its bundles are trusted as given
 * and no containment check applies. The tier label is opaque to this library;
 * the host decides what its labels mean.
 */
final readonly class Root
{
    public function __construct(
        public string $dir,
        public string $tier,
        public ?string $boundary,
    ) {
    }
}
