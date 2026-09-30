<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\ExternalModule;

/**
 * Stderr drain discipline of {@see ExternalModule} — audit finding #4.
 *
 * The child's stderr pipe used to be opened and then never read: once the
 * 64 KiB kernel buffer filled, the plugin blocked mid-`fwrite(STDERR, …)`,
 * never reached the stdout response it owed, and readResponse() timed out
 * after 5 s and silently disabled a plugin that was merely talkative.
 * These tests drive REAL children spamming more than one pipe buffer's
 * worth of stderr — before the first response, and again between two
 * round trips — because only the kernel can tell us the pipe was drained.
 *
 * As a regression leash, every spawn is paired with the same background
 * `pkill -9 -f MARKER` watchdog shape ExternalModuleTeardownTest uses: a
 * broken drain leaves the child parked on its stderr write, and without
 * the watchdog a red run would also pay the destructor's full ladder.
 */
final class ExternalModuleStderrTest extends TestCase
{
    /** Unique substring so the pkill watchdog matches only this file's children. */
    private const MARKER = 'sugar-dash-stderr-flood-child';

    /** More than the 64 KiB pipe buffer, so an undrained write MUST block. */
    private const FLOOD_BYTES = 71680;

    private $watchdogHandle = null;

    public function testInitCompletesWhenThePluginFloodsStderrBeforeResponding(): void
    {
        $module = $this->spawnFloodPlugin();

        $result = $module->init();

        $this->assertSame('stderr-flood', $result['name']);
        $this->assertSame(0, $result['interval']);
        $this->cancelWatchdog();
    }

    public function testUpdateRoundTripSurvivesASecondStderrFlood(): void
    {
        $module = $this->spawnFloodPlugin();
        $module->init();

        // The child floods stderr again before answering this request; the
        // drain must be per-read, not a startup formality.
        $state = $module->update(['tick' => 3]);

        $this->assertSame(['tick' => 4], $state);
        $this->cancelWatchdog();
    }

    /**
     * A protocol-complete child: reads one request line, dumps a full pipe
     * buffer-plus of stderr (blocking unless drained), answers, and repeats
     * for the update request before exiting on stdin EOF.
     */
    private function spawnFloodPlugin(): ExternalModule
    {
        $flood = self::FLOOD_BYTES;
        $code = '// ' . self::MARKER . "\n"
            . 'trim(fgets(STDIN));'
            . "fwrite(STDERR, str_repeat('E', {$flood}));"
            . 'fwrite(STDOUT, json_encode(["type"=>"init","data"=>["name"=>"stderr-flood","interval"=>0]])."\\n");'
            . 'trim(fgets(STDIN));'
            . "fwrite(STDERR, str_repeat('F', {$flood}));"
            . 'fwrite(STDOUT, json_encode(["type"=>"update","data"=>["state"=>["tick"=>4]]])."\n");';

        $this->armWatchdog();

        return new ExternalModule('stderr-flood', \PHP_BINARY, ['-r', $code]);
    }

    private function armWatchdog(): void
    {
        // If the drain regresses, the flood child parks on its stderr write
        // and outlives the test; this kills every marked child after 25 s.
        // The handle is KEPT (not proc_close()'d — that would block the test
        // INTO its own pkill) and cancelled on the green path, the exact
        // shape ExternalModuleTeardownTest::armWatchdog() uses.
        $marker = self::MARKER;
        $this->watchdogHandle = \proc_open(
            ['sh', '-c', "sleep 25; pkill -9 -f {$marker} 2>/dev/null; true"],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($this->watchdogHandle);
        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
    }

    private function cancelWatchdog(): void
    {
        if ($this->watchdogHandle !== null && \is_resource($this->watchdogHandle)) {
            \proc_terminate($this->watchdogHandle);
            \proc_close($this->watchdogHandle);
        }
        $this->watchdogHandle = null;
    }
}
