<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use PHPUnit\Framework\TestCase;

/**
 * sc-24 (findings sugar-core-041/-045): the dead-emitter adjudication,
 * machine-checked.
 *
 * SuggestionsReadyMsg and CapabilityMsg had no producer and no consumer
 * anywhere in the fleet (sugar-forms' FormSuggestionsReadyMsg is its own
 * class; name collisions elsewhere are prose). They are deleted, and the
 * deletions stay deleted: a resurrection without a fresh adjudication
 * reddens here. The guard is ON-DISK (file existence), never
 * class_exists() — an optimized vendor classmap that still remembers a
 * deleted class turns an autoload probe into a suppressed PHP warning
 * inside the test run (measured: 4 phantom test-warnings), and this
 * project's suites must stay silent.
 *
 * FocusLostMsg survives as the documented dormant twin (external replay
 * code — candy-vcr's serializer, candy-code's handler — still constructs
 * it by name); its docblock must keep saying so, and its removal is a
 * rider that lands only after those consumers flip to BlurMsg.
 * KeyPressMsg's docblock must stay truthful to the census: legacy keys
 * arrive as plain KeyMsg; this class is the kitty press arm only.
 */
final class DeadEmittersTest extends TestCase
{
    private const MSG_DIR = __DIR__ . '/../../src/Msg';

    public function testSuggestionsReadyMsgIsDeleted(): void
    {
        $this->assertFileDoesNotExist(
            self::MSG_DIR . '/SuggestionsReadyMsg.php',
            'sc-24 deleted SuggestionsReadyMsg (no producer, no consumer); restore needs a fresh wire-or-delete verdict'
        );
    }

    public function testCapabilityMsgIsDeleted(): void
    {
        $this->assertFileDoesNotExist(
            self::MSG_DIR . '/CapabilityMsg.php',
            'sc-24 deleted CapabilityMsg (zero producers — terminal-capability answers ride KeyboardEnhancementsMsg/ModeReportMsg); restore needs a producer'
        );
    }

    public function testFocusLostMsgSurvivesAsDocumentedDormantTwin(): void
    {
        $source = $this->source('FocusLostMsg.php');
        $this->assertStringContainsString('Dormant twin', $source, 'the honesty marker must survive edits');
        $this->assertStringContainsString('@deprecated', $source, 'sc-24: the removal rider is spelled in the contract');
    }

    public function testKeyPressMsgDocblockIsTheCensusTruth(): void
    {
        $source = $this->source('KeyPressMsg.php');
        $this->assertStringNotContainsString('emitted for every', $source, 'the 041 misdirection must not return');
        $this->assertStringContainsString('plain {@see KeyMsg}', $source, 'the legacy-path home must stay named');
    }

    private function source(string $file): string
    {
        $path = self::MSG_DIR . '/' . $file;
        $source = \file_get_contents($path);
        if ($source === false) {
            $this->fail("missing source under guard: {$path}");
        }

        return $source;
    }
}
