<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Message;
use PHPUnit\Framework\TestCase;

final class CommandBackendTest extends TestCase
{
    public function testCommandReceivesHistoryAsJsonOnStdin(): void
    {
        // `cat` echoes whatever it gets on stdin, which is the
        // JSON-encoded history. The reply will therefore be the
        // JSON itself — letting us assert the wire format.
        $backend = new CommandBackend(['cat']);
        $reply = $backend->complete([
            Message::user('hi'),
            Message::assistant('hello back'),
        ]);
        $decoded = json_decode($reply->content, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('user',       $decoded[0]['role']);
        $this->assertSame('hi',         $decoded[0]['content']);
        $this->assertSame('assistant',  $decoded[1]['role']);
        $this->assertSame('hello back', $decoded[1]['content']);
    }

    public function testNonZeroExitReportedAsErrorMessage(): void
    {
        $backend = new CommandBackend(['false']);
        $reply = $backend->complete([Message::user('hi')]);
        $this->assertStringContainsString('exited 1', $reply->content);
    }

    public function testMissingCommandReportedGracefully(): void
    {
        $backend = new CommandBackend(['/nonexistent/command/path']);
        $reply = $backend->complete([Message::user('hi')]);
        $this->assertStringContainsString('error', strtolower($reply->content),
            'a non-existent command should produce an "[error: ...]" message, not crash');
    }

    /**
     * A reply that is the single character `0` is an answer, not an absence.
     *
     * `stream_get_contents()` returns `string|false`, and the read used to be
     * `stream_get_contents($pipes[1]) ?: ''` — under which `"0"` is falsy and
     * the whole reply became the empty string. `printf` rather than `echo`
     * deliberately: with a trailing newline the string is `"0\n"`, which is
     * truthy, and the bug hides. One character of data loss, on the path whose
     * docblock promises stdout back with one `trim()` and nothing else.
     */
    public function testAReplyOfExactlyZeroIsNotSwallowed(): void
    {
        $backend = new CommandBackend(['printf', '0']);

        $this->assertSame('0', $backend->complete([Message::user('hi')])->content);
    }

    /**
     * The same for stderr on the failure path: a stderr tail of `"0"` used to
     * be `?:`-flattened away, so the fenced hint the message promises was
     * silently omitted for it.
     */
    public function testAStderrTailOfExactlyZeroStillReachesTheErrorHint(): void
    {
        $backend = new CommandBackend('printf 0 >&2; exit 3');
        $content = $backend->complete([Message::user('hi')])->content;

        $this->assertStringContainsString('exited 3', $content);
        $this->assertStringContainsString("```\n0\n```", $content, 'the stderr tail was dropped for being "0"');
    }

    /**
     * The documented exception to "stdout comes back as-is": `trim()` at the
     * ends, which is deliberate (a wrapper's `echo` adds a newline nobody wants
     * rendered) but does reach INTO the reply's first line — a four-space
     * indent that made line one a code block is gone, while interior newlines,
     * blank lines and indents survive. Pinned so the docblock's claim and its
     * stated exception are both measured rather than asserted.
     */
    public function testStdoutSurvivesInsideAndIsTrimmedOnlyAtTheEnds(): void
    {
        $backend = new CommandBackend(['printf', "    indented\nline two\n\nline four\n"]);

        $this->assertSame("indented\nline two\n\nline four", $backend->complete([])->content);
    }

    // =========================================================================
    // completeAsync() Tests
    // =========================================================================

    public function testCompleteAsyncReturnsPromise(): void
    {
        $backend = new CommandBackend(['cat']);
        $promise = $backend->completeAsync([Message::user('hello')]);

        $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
    }

    public function testCompleteAsyncResolvesToMessage(): void
    {
        $backend = new CommandBackend(['cat']);
        $promise = $backend->completeAsync([Message::user('test message')]);

        $resolved = null;
        $promise->then(function ($message) use (&$resolved): void {
            $resolved = $message;
        });

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertSame(\SugarCraft\Crush\Role::Assistant, $resolved->role);
    }

    public function testCompleteAsyncRejectsOnFailure(): void
    {
        $backend = new CommandBackend(['false']); // exits with code 1
        $promise = $backend->completeAsync([Message::user('hi')]);

        $resolved = null;
        $promise->then(function ($message) use (&$resolved): void {
            $resolved = $message;
        });

        // completeAsync resolves, even on non-zero exit - the error is in the message content
        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertStringContainsString('error', strtolower($resolved->content));
    }

    public function testCompleteAsyncWithArrayCommand(): void
    {
        $backend = new CommandBackend(['cat']);
        $promise = $backend->completeAsync([Message::user('array command test')]);

        $resolved = null;
        $promise->then(function ($message) use (&$resolved): void {
            $resolved = $message;
        });

        $this->assertInstanceOf(Message::class, $resolved);
    }
}
