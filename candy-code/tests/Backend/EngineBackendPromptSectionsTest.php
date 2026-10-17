<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\Read;

/**
 * ISS-063: `/context` measured the prompt without the turn's tools, so the
 * tool-guidance layer it ships every turn read as absent — and the bare hook
 * manager it built never carried the permission-gate hook the plan-mode
 * layer looks itself up through. `promptSectionSizes()` now builds the App
 * and the hooks the way a turn does.
 */
final class EngineBackendPromptSectionsTest extends TestCase
{
    /** @return list<string> */
    private function labels(EngineBackend $backend): array
    {
        return array_column($backend->promptSectionSizes(), 'label');
    }

    public function testTheToolGuidanceLayerIsMeasuredWheneverTheTurnShipsTools(): void
    {
        $withTools = EngineBackend::new(new ScriptedProvider([]), 'm')->withTools([new Read()]);

        self::assertContains('tool-guidance', $this->labels($withTools), 'the layer a tool-bearing turn ships must not measure empty');
    }

    public function testAToollessEngineStillMeasuresNoToolGuidance(): void
    {
        $bare = EngineBackend::new(new ScriptedProvider([]), 'm');

        self::assertNotContains('tool-guidance', $this->labels($bare), 'a turn with no tools ships no guidance, measured or otherwise');
        self::assertContains('base', $this->labels($bare));
    }
}
