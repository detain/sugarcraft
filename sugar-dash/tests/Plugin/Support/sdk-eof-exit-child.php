<?php

declare(strict_types=1);

// E723 (round 82) — real plugin child for SrcExitCensusTest.
//
// This file is the docblock example made executable: a concrete PluginSdk
// whose script ENDS with ::run(), so the only way the process can leave
// PluginSdk::run() is the justified exit(0) at the EOF terminus. The census
// test feeds one init request, closes stdin (the ExternalModule grace-rung
// door), and asserts the kernel reports status 0 with no uncaught throw.

use SugarCraft\Dash\Plugin\PluginSdk;
use SugarCraft\Dash\Plugin\Request;
use SugarCraft\Dash\Plugin\Response;

require __DIR__ . '/../../../vendor/autoload.php';

final class Q9EofChildPlugin extends PluginSdk
{
    protected function init(): array
    {
        return ['name' => 'q9-eof-child', 'minSize' => [20, 4], 'interval' => 0];
    }

    protected function update(array $state): array
    {
        return $state;
    }

    protected function view(array $state, int $width, int $height): string
    {
        return 'q9';
    }
}

Q9EofChildPlugin::run(
    static fn(Request $request): Response => Response::view('q9'),
);
