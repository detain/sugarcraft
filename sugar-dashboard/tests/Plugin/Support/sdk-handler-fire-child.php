<?php

declare(strict_types=1);

// D-103 (finding sugar-dashboard-089) — real plugin child proving a closure
// passed to PluginSdk::run() actually receives every request. The old body
// dispatched $sdk->handle() unconditionally and the handler was a dead
// parameter; this fixture serves a marker ONLY through the closure, so a
// regression to the ignoring body answers 'template' and the pin goes red.

use SugarCraft\Dashboard\Plugin\PluginSdk;
use SugarCraft\Dashboard\Plugin\Request;
use SugarCraft\Dashboard\Plugin\Response;

require __DIR__ . '/../../../vendor/autoload.php';

final class Q10HandlerFireChildPlugin extends PluginSdk
{
    protected function init(): array
    {
        return ['name' => 'q10-handler-fire', 'minSize' => [10, 2], 'interval' => 0];
    }

    protected function update(array $state): array
    {
        return $state;
    }

    protected function view(array $state, int $width, int $height): string
    {
        return 'template';
    }
}

Q10HandlerFireChildPlugin::run(
    static fn(Request $request): Response => Response::view('HANDLED:' . ($request->data['marker'] ?? '?')),
);
