<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RenderBatchCommand;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * Regression: RenderBatchCommand reuses a single TapeToGif across the
 * whole batch.
 *
 * Bug fixed in d070e742: the old loop called `TapeToGif::new()`
 * inside the per-tape foreach, paying the rasterizer/encoder
 * construction cost N times and defeating any cache the rasterizer
 * builds internally. The fix hoists `TapeToGif::new()` above the
 * loop.
 *
 * Signals checked:
 * - Structural: `TapeToGif::new()` lives OUTSIDE the per-tape loop
 *   in {@see RenderBatchCommand::execute}. Caught by token-walking
 *   the file to assert at most one create() call appears, and it
 *   appears before the `foreach`.
 * - Behavioural: when rendering N tapes that share the same theme +
 *   font size, the in-process TapeToGif rasterizer instance survives
 *   across renders (assert via reflection that the rasterizer property
 *   id is stable across two render() calls).
 */
final class RenderBatchReuseTest extends TestCase
{
    public function testTapeToGifNewLivesOutsideThePerTapeLoop(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Cli/RenderBatchCommand.php',
        );
        $this->assertIsString($source);

        $tokens = \PhpToken::tokenize($source);

        // `new` is a reserved word: after `::` it tokenizes as T_NEW, not
        // T_STRING, so the factory-call census runs on the raw offsets.
        $createOffsets = [];
        preg_match_all('/TapeToGif::new\(/', $source, $factoryHits, PREG_OFFSET_CAPTURE);
        foreach ($factoryHits[0] as $hit) {
            $createOffsets[] = $hit[1];
        }
        $foreachOffsets = [];
        foreach ($tokens as $tok) {
            if ($tok->is(T_FOREACH)) {
                $foreachOffsets[] = $tok->pos;
            }
        }

        $this->assertCount(1, $createOffsets, 'Exactly one TapeToGif::new() call expected — got ' . count($createOffsets));
        $this->assertNotEmpty($foreachOffsets, 'execute() should iterate via foreach');
        $this->assertLessThan(
            min($foreachOffsets),
            $createOffsets[0],
            'TapeToGif::new() must be hoisted above the per-tape foreach',
        );
    }

    public function testRasterizerInstanceSurvivesAcrossRenderCalls(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $tempDir = sys_get_temp_dir() . '/candy-vcr-reuse-' . bin2hex(random_bytes(4));
        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            $this->fail("Failed to create temp dir: {$tempDir}");
        }

        $tape = $tempDir . '/x.tape';
        file_put_contents(
            $tape,
            "Set Theme \"TokyoNight\"\nSet Width 700\nSet Height 240\nType \"x\"\nSleep 50ms\n",
        );

        try {
            $renderer = TapeToGif::new(['encoder' => 'php', 'backend' => 'gd']);

            $rasterizerProp = new \ReflectionProperty($renderer, 'rasterizer');
            $rasterizerProp->setAccessible(true);
            $rasterizerBefore = $rasterizerProp->getValue($renderer);
            $this->assertIsObject($rasterizerBefore);
            $idBefore = spl_object_id($rasterizerBefore);

            $renderer->render($tape, $tempDir . '/x.gif', ['encoder' => 'php', 'backend' => 'gd']);
            $renderer->render($tape, $tempDir . '/x2.gif', ['encoder' => 'php', 'backend' => 'gd']);

            $rasterizerAfter = $rasterizerProp->getValue($renderer);
            $this->assertIsObject($rasterizerAfter);
            $idAfter = spl_object_id($rasterizerAfter);
            $this->assertSame(
                $idBefore,
                $idAfter,
                'TapeToGif::$rasterizer must NOT be reassigned across render() calls — withTheme() clones live on the stack only',
            );
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tempDir);
        }
    }
}
