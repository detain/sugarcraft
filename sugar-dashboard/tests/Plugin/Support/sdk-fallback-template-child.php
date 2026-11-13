<?php

declare(strict_types=1);

// D-103 — companion child proving the OMITTED handler keeps the documented
// class-template path alive: with no argument to ::run(), init/update/view
// on the subclass answer the wire exactly as before the handler parameter
// gained meaning (BC leg: the parameter became optional, nothing deleted).

use SugarCraft\Dashboard\Plugin\PluginSdk;

require __DIR__ . '/../../../vendor/autoload.php';

final class Q10FallbackChildPlugin extends PluginSdk
{
    protected function init(): array
    {
        return ['name' => 'q10-fallback-child', 'minSize' => [20, 4], 'interval' => 0];
    }

    protected function update(array $state): array
    {
        return array_merge($state, ['tick' => ($state['tick'] ?? 0) + 1]);
    }

    protected function view(array $state, int $width, int $height): string
    {
        return 'fallback ' . ($state['tick'] ?? 0);
    }
}

Q10FallbackChildPlugin::run();
