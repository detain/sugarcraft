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

    public function testNewIsRootedInTheSystemTempDirectory(): void
    {
        $store = SuspendedDelegations::new();
        $id = $store->save('coder', [new UserMessage('x')], 0);

        $this->assertFileExists(sys_get_temp_dir() . '/sugarcrush-suspended-delegations/' . $id . '.run');
        $store->forget($id);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/sugarcrush-suspended-delegations/' . $id . '.run');
    }
}
