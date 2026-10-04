<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Hooks\BuiltIn\AutoTestEditHook;
use SugarCraft\Crush\Hooks\BuiltIn\AutoTestHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Lint\TestRunner;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Step 3.H end to end: a turn that wrote a file runs `testCommand` when the
 * model answers; a failure goes back to the model as Aider's `run_output`
 * prompt through 3.D-2's Stop continuation and the turn carries on, at most
 * {@see AutoTestHook::MAX_REFLECTIONS} times; a turn that wrote nothing ends
 * exactly as before.
 */
final class AutoTestReflectionTest extends TestCase
{
    /** Passes only once `answer.txt` holds `42`. */
    private const TEST_COMMAND = 'test "$(cat answer.txt)" = 42 || { echo "expected 42, got $(cat answer.txt)"; exit 1; }';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/sc-reflect-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/' . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testAFailingSuiteIsReflectedOnUntilTheEditMakesItPass(): void
    {
        $provider = new ScriptedProvider([
            self::write('c1', '41'),
            new CompleteResponse(content: 'done'),
            self::write('c2', '42'),
            new CompleteResponse(content: 'fixed'),
            new CompleteResponse(content: 'never sent'),
        ]);

        $reply = $this->engine($provider)->complete([Message::user('write the answer')]);

        self::assertSame('fixed', $reply->content);
        self::assertCount(4, $provider->requests, 'one reflection: the failing run, an edit, a passing run');
        self::assertSame('42', file_get_contents($this->root . '/answer.txt'));

        $rows = TurnContextBlock::strip($provider->requests[2]->messages);
        $last = $rows[array_key_last($rows)];
        self::assertInstanceOf(UserMessage::class, $last, 'the run output is the newest row of the next request');
        self::assertSame(
            'Stop hook "auto-test" did not let you finish yet: I ran this command:'
                . "\n\n" . self::TEST_COMMAND . "\n\nAnd got this output:\n\nexpected 42, got 41",
            $last->content(),
        );
        self::assertSame('done', $rows[array_key_last($rows) - 1]->content(), 'the answer it refused stays ahead of it');
    }

    public function testATurnThatWroteNothingEndsExactlyAsBefore(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'just an answer'), new CompleteResponse(content: 'never sent')]);

        $reply = $this->engine($provider)->complete([Message::user('explain it')]);

        self::assertSame('just an answer', $reply->content);
        self::assertCount(1, $provider->requests);
    }

    public function testStillFailingAfterThreeReflectionsEndsTheTurnWithTheStopReason(): void
    {
        $script = [];
        for ($i = 0; $i <= AutoTestHook::MAX_REFLECTIONS; $i++) {
            $script[] = self::write('c' . $i, (string) $i);
            $script[] = new CompleteResponse(content: 'attempt ' . $i);
        }
        $script[] = new CompleteResponse(content: 'never sent');
        $provider = new ScriptedProvider($script);

        $reply = $this->engine($provider)->complete([Message::user('write the answer')]);

        self::assertCount(2 * (AutoTestHook::MAX_REFLECTIONS + 1), $provider->requests);
        self::assertSame(
            'attempt 3' . "\n\n" . '[turn stopped by hook "auto-test": the test command `' . self::TEST_COMMAND . '` still fails (exit 1) after 3 attempts to fix it]',
            $reply->content,
        );
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        $edits = AutoTestEditHook::new();
        $registry = new HookRegistry();
        $hooks = new HookManager($registry);
        $hooks->register($edits);
        $hooks->register(new AutoTestHook(TestRunner::new()->withCommand(self::TEST_COMMAND), $edits));

        return EngineBackend::new($provider, 'm')
            ->withRoot($this->root)
            ->withTools([new Write($this->root)])
            ->withHooks($hooks);
    }

    private static function write(string $id, string $content): CompleteResponse
    {
        return new CompleteResponse(content: '', toolCalls: [new ToolCall($id, 'Write', ['file_path' => 'answer.txt', 'content' => $content, 'overwrite' => true])]);
    }
}
