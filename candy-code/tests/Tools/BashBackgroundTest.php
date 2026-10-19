<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Sessions\BackgroundShellRegistry;
use SugarCraft\Code\Tools\BuiltIn\Bash;

/**
 * SHL-1: Bash's `run_in_background` flag. The synchronous path must be
 * untouched (it is pinned by the whole existing Bash family); what is pinned
 * here is the door in front of the spawn — refusals for the combinations
 * that cannot work, and the hand-off to the registry when they can.
 *
 * Liveness follows the registry suite's law: the shell is observed through
 * the registry's own polling status, never a single-shot post-mortem probe.
 */
final class BashBackgroundTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/w2d-bash-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $this->root . '/' . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    private function registry(): BackgroundShellRegistry
    {
        return BackgroundShellRegistry::forSession('w2d-test', $this->root);
    }

    public function testTheSchemaAdvertisesTheFlagAndStillRequiresNothingNew(): void
    {
        $schema = (new Bash())->inputSchema();
        self::assertArrayHasKey('run_in_background', $schema['properties']);
        self::assertSame('boolean', $schema['properties']['run_in_background']['type']);
        self::assertSame(['command', 'description'], $schema['required'], 'the flag rides optional — every existing call shape is untouched');
    }

    public function testTheSynchronousPathStillWaitsAndCaptures(): void
    {
        $result = (new Bash())->execute(['command' => 'printf sync-ok', 'description' => 'sync']);
        self::assertFalse($result->isError());
        self::assertStringContainsString('sync-ok', $result->content());
    }

    public function testBackgroundAndInteractiveRefuseEachOtherAtTheDoor(): void
    {
        $result = (new Bash())->execute([
            'command' => 'true', 'description' => 'clash',
            'run_in_background' => true, 'interactive' => true,
        ]);
        self::assertTrue($result->isError());
        self::assertStringContainsString('cannot both be true', $result->content());
    }

    public function testABashWithoutARegistryRefusesToBackgroundLoudly(): void
    {
        // A registry-less Bash is the shape an older wiring produces; the
        // honest answer is a refusal that names the fix, never a silently
        // synchronous run the caller believes is detached.
        $result = (new Bash())->execute([
            'command' => 'true', 'description' => 'orphan', 'run_in_background' => true,
        ]);
        self::assertTrue($result->isError());
        self::assertStringContainsString('no background shell registry attached', $result->content());
    }

    public function testTheFlagHandsOffToTheRegistryAndAnswersTheIdAtOnce(): void
    {
        $registry = $this->registry();
        $bash = new Bash(registry: $registry);
        $startedAt = microtime(true);

        $result = $bash->execute([
            'command' => 'printf late-output', 'description' => 'detached', 'run_in_background' => true,
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertThat(microtime(true) - $startedAt, self::lessThan(5.0), 'the answer is immediate — the call must not wait for the shell');
        self::assertMatchesRegularExpression('/sh-[0-9a-f]{8}/', $result->content(), 'the answer carries the shell id');
        self::assertStringContainsString('ShellOutput', $result->content(), 'the answer teaches the read channel');
        self::assertStringContainsString('ShellStop', $result->content(), 'the answer teaches the stop channel');
        self::assertStringContainsString((string) BackgroundShellRegistry::MAX_LIFETIME_SECONDS, $result->content(), 'the lifetime wall is disclosed at spawn');

        $id = (string) (preg_match('/sh-[0-9a-f]{8}/', $result->content(), $m) ? $m[0] : '');
        for ($i = 0; $i < 160; $i++) {
            if (($registry->status($id)['state'] ?? '') === 'finished') {
                break;
            }
            usleep(25_000);
        }
        $settled = $registry->status($id);
        self::assertSame('finished', $settled['state']);
        self::assertSame(0, $settled['exitCode']);
        self::assertStringContainsString('late-output', (string) $registry->read($id)['stdout'], 'the detached command really ran, into the registry sinks');
    }
}
