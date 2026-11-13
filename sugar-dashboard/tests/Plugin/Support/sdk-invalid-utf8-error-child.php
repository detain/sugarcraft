<?php

declare(strict_types=1);

// D-103 (finding sugar-dashboard-209) — the reply channel must survive an
// exception whose message carries raw invalid bytes. Before the SUBSTITUTE
// flag, Response::error($e->getMessage())->toJson() threw INSIDE run()'s
// catch: the child died 255 with an uncaught JsonException trace and the
// host's readResponse starved — precisely the moment the child most owes an
// answer. This handler throws the poison; the test asserts an honest error
// JSON line back and a clean 0 exit at EOF.

use SugarCraft\Dashboard\Plugin\PluginSdk;
use SugarCraft\Dashboard\Plugin\Request;
use SugarCraft\Dashboard\Plugin\Response;

require __DIR__ . '/../../../vendor/autoload.php';

final class Q11InvalidUtf8ErrorChildPlugin extends PluginSdk
{
    protected function init(): array
    {
        return ['name' => 'q11-invalid-utf8', 'minSize' => [10, 2], 'interval' => 0];
    }

    protected function update(array $state): array
    {
        return $state;
    }

    protected function view(array $state, int $width, int $height): string
    {
        return 'q11';
    }
}

Q11InvalidUtf8ErrorChildPlugin::run(
    static function (Request $request): Response {
        throw new \RuntimeException('bad ' . "\xFF\xFE" . ' message');
    },
);
