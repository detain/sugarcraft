<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Loader;

/**
 * What one launch's load decided, one bucket per entrypoint.
 *
 * Every entry lands in exactly one bucket: loaded, quarantined (listed this
 * launch, loads at the next), or refused with a machine reason word. The
 * loader answers; the caller renders notices. The row shapes are the ones the
 * candy-code channel loader already exposes, so {@see toArray()} hands them
 * over unchanged.
 */
final readonly class LoadResult
{
    /**
     * @param list<array{bundle: string, path: string, class: string, kind: string}> $loaded
     * @param list<array{bundle: string, path: string, class: string, kind: string}> $quarantined
     * @param list<array{bundle: string, path: string, class: string, kind: string, reason: string, error?: string}> $refusals
     */
    public function __construct(
        public array $loaded,
        public array $quarantined,
        public array $refusals,
    ) {
    }

    /**
     * @return array{loaded: list<array{bundle: string, path: string, class: string, kind: string}>, quarantined: list<array{bundle: string, path: string, class: string, kind: string}>, refusals: list<array{bundle: string, path: string, class: string, kind: string, reason: string, error?: string}>}
     */
    public function toArray(): array
    {
        return ['loaded' => $this->loaded, 'quarantined' => $this->quarantined, 'refusals' => $this->refusals];
    }
}
