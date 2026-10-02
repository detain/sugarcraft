<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * The on-disk store behind resumable Task runs. What it must never do is hand
 * back a HALF conversation — a transcript that lost its tool calls, a foreign
 * class, a path someone smuggled in as an id — because the resumed model would
 * act on it as though it were whole.
 */
final class SuspendedDelegationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sc_suspended_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testATranscriptRoundTripsWithItsToolCallsIntact(): void
    {
        $store = new SuspendedDelegations($this->dir);
        $transcript = [
            new SystemMessage('You are coder.'),
            new UserMessage('audit it'),
            new AssistantMessage('', [new ToolCall('call_1', 'Read', ['file_path' => 'a.php'])]),
            new ToolResultMessage('call_1', 'contents', false),
        ];

        $id = $store->save('coder', $transcript, 2);
        $loaded = $store->load($id);

        $this->assertMatchesRegularExpression(SuspendedDelegations::ID_PATTERN, $id);
        $this->assertSame('coder', $loaded['agent'] ?? null);
        $this->assertSame(2, $loaded['resumes'] ?? null);
        $this->assertEquals($transcript, $loaded['transcript'] ?? null);
        $this->assertSame('Read', $loaded['transcript'][2]->toolCalls()[0]->name());
    }

    public function testSavingUnderAnExistingIdKeepsIt(): void
    {
        $store = new SuspendedDelegations($this->dir);
        $id = $store->save('coder', [new UserMessage('one')], 0);

        $this->assertSame($id, $store->save('coder', [new UserMessage('two')], 1, $id));
        $this->assertSame('two', $store->load($id)['transcript'][0]->content());
    }

    public function testFilesAndTheDirectoryAreOwnerOnly(): void
    {
        $store = new SuspendedDelegations($this->dir);
        $id = $store->save('coder', [new UserMessage('secret file contents')], 0);

        clearstatcache();
        $this->assertSame(0o700, fileperms($this->dir) & 0o777);
        $this->assertSame(0o600, fileperms($this->dir . '/' . $id . '.run') & 0o777);
    }

    public function testMalformedUnknownAndForgottenIdsLoadNothing(): void
    {
        $store = new SuspendedDelegations($this->dir);
        $id = $store->save('coder', [new UserMessage('x')], 0);

        $this->assertNull($store->load('../../etc/passwd'));
        $this->assertNull($store->load('ffffffffffffffff'));

        $store->forget($id);
        $this->assertNull($store->load($id));
    }

    public function testAPayloadCarryingAForeignClassIsRefusedNotHalfRestored(): void
    {
        $store = new SuspendedDelegations($this->dir);
        $id = $store->save('coder', [new UserMessage('x')], 0);
        file_put_contents($this->dir . '/' . $id . '.run', serialize([
            'agent' => 'coder',
            'transcript' => [new \ArrayObject([])],
            'resumes' => 0,
            'savedAt' => time(),
        ]));

        $this->assertNull($store->load($id));
    }

    public function testAnExpiredSuspensionIsGone(): void
    {
        $store = new SuspendedDelegations($this->dir);
        $id = $store->save('coder', [new UserMessage('x')], 0);
        file_put_contents($this->dir . '/' . $id . '.run', serialize([
            'agent' => 'coder',
            'transcript' => [new UserMessage('x')],
            'resumes' => 0,
            'savedAt' => time() - SuspendedDelegations::MAX_AGE_SECONDS - 1,
        ]));

        $this->assertNull($store->load($id));
    }

    public function testNewIsRootedInThisUsersDirectoryUnderTheSystemTempDirectory(): void
    {
        $store = SuspendedDelegations::new();
        $id = $store->save('coder', [new UserMessage('x')], 0);
        $own = sys_get_temp_dir() . '/sugarcrush-suspended-delegations-' . posix_geteuid();

        $this->assertFileExists($own . '/' . $id . '.run');
        $store->forget($id);
        $this->assertFileDoesNotExist($own . '/' . $id . '.run');
    }

    /**
     * Driven through the private seam because no build this suite runs on lacks
     * `posix_geteuid()`, so the shared `-noposix` arm is otherwise unreachable.
     */
    public function testTheStoreNameIsScopedToTheEffectiveUid(): void
    {
        $for = new \ReflectionMethod(SuspendedDelegations::class, 'directoryFor');

        $this->assertSame(
            sys_get_temp_dir() . '/sugarcrush-suspended-delegations-4242',
            $for->invoke(null, 4242),
            'the store no longer carries the uid it was given, so two users share one directory again',
        );
        $this->assertSame(sys_get_temp_dir() . '/sugarcrush-suspended-delegations-noposix', $for->invoke(null, null));
    }

    /**
     * AUDIT TMP-1. Under one fixed directory name the first user on the box to
     * suspend a Task run owned the store, and every other user's `save()` threw
     * from then on — none of their runs could be resumed until that directory
     * was deleted, and any local user could cause it on purpose.
     *
     * A process without root cannot create a directory another uid owns, so the
     * squatter is a `0755` directory at the old shared name, which the store's
     * verification refuses just the same. It runs in a child whose `TMPDIR` is
     * the sandbox, because `sys_get_temp_dir()` caches its first answer for the
     * life of a process and {@see SuspendedDelegations::new()} must be observed
     * resolving its own path.
     */
    public function testADirectoryAnotherUserHoldsAtTheSharedNameDoesNotBlockSuspending(): void
    {
        $root = sys_get_temp_dir() . '/sc_suspended_tmp1_' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($root, 0o700));
        $squatter = $root . '/sugarcrush-suspended-delegations';
        $this->assertTrue(mkdir($squatter, 0o700));
        $this->assertTrue(chmod($squatter, 0o755));

        // The autoloader is located from the loaded class rather than climbed to
        // from this file, so the child runs exactly the code the parent does.
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        $this->assertIsString($loader);
        $script = $root . '/probe.php';
        file_put_contents($script, sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            require %s;

            $store = SugarCraft\Crush\Agents\SuspendedDelegations::new();
            $id = $store->save('coder', [new SugarCraft\Crush\Messages\UserMessage('resume me')], 0);
            fwrite(STDOUT, json_encode([
                'tempRoot' => sys_get_temp_dir(),
                'id' => $id,
                'agent' => $store->load($id)['agent'] ?? null,
            ]));
            PHP, var_export(\dirname($loader, 2) . '/autoload.php', true)));

        try {
            $process = proc_open(
                [PHP_BINARY, $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['TMPDIR' => $root, 'PATH' => (string) getenv('PATH')],
            );
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $this->assertSame(0, $status, 'save() failed in the child — a squatter at the shared name '
                . 'still disables this user\'s suspended runs: ' . $stderr . $stdout);
            $report = json_decode($stdout, true);
            $this->assertIsArray($report, 'the child reported nothing: ' . $stdout);
            $this->assertSame($root, $report['tempRoot'], 'the child did not run under the sandbox, so this says nothing');
            $this->assertSame('coder', $report['agent'], 'the suspended run did not load back');
            $this->assertFileExists($root . '/sugarcrush-suspended-delegations-' . posix_geteuid() . '/' . $report['id'] . '.run');
            $this->assertSame(['.', '..'], scandir($squatter), 'the run was written into the squatter\'s directory');
        } finally {
            foreach (glob($root . '/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($root . '/*') ?: [] as $entry) {
                is_dir($entry) ? @rmdir($entry) : @unlink($entry);
            }
            @rmdir($root);
        }
    }
}
