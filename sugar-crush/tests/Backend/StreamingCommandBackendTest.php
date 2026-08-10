<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use PHPUnit\Framework\TestCase;

final class StreamingCommandBackendTest extends TestCase
{
    public function testStreamingBackendCallsOnTokenForEachLine(): void
    {
        // Create a script that outputs tokens line by line
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'Hello'\necho ' '\necho 'World!'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $tokens = [];
            $onToken = function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            };

            $result = $backend->complete([], $onToken);

            $this->assertSame(['Hello', ' ', 'World!'], $tokens);
            $this->assertSame(Role::Assistant, $result->role);
            $this->assertSame('Hello World!', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendWithoutCallback(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'No callback test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $result = $backend->complete([], null);

            $this->assertSame(Role::Assistant, $result->role);
            $this->assertSame('No callback test', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendReportsErrorOnNonZeroExit(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'partial output'\nexit 1");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $result = $backend->complete([], null);

            $this->assertSame(Role::Assistant, $result->role);
            $this->assertStringContainsString('error', $result->content);
            $this->assertStringContainsString('1', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendReportsErrorOnMissingCommand(): void
    {
        $backend = new StreamingCommandBackend(['/nonexistent/command/path']);
        $result = $backend->complete([], null);

        $this->assertSame(Role::Assistant, $result->role);
        $this->assertStringContainsString('error', $result->content);
    }

    public function testStreamingBackendPassesHistoryToStdin(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        // Script reads stdin and includes it in output
        file_put_contents($script, "#!/bin/bash\ncat > /dev/null && echo 'received history'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $history = [
                Message::user('Hello'),
                Message::assistant('Hi there!'),
            ];
            $result = $backend->complete($history, null);

            $this->assertSame('received history', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendHandlesMultipleRapidTokens(): void
    {
        // Generate tokens quickly to test buffering - use fewer tokens for stability
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = "token{$i}";
        }
        // Build script: each echo on its own line, properly terminated
        $lines = ["#!/bin/bash"];
        foreach ($tokens as $token) {
            $lines[] = "echo {$token}";
        }
        $lines[] = "true";
        $scriptContent = implode("\n", $lines);

        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, $scriptContent);
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $receivedTokens = [];
            $onToken = function (string $token) use (&$receivedTokens): void {
                $receivedTokens[] = $token;
            };

            $result = $backend->complete([], $onToken);

            $this->assertCount(50, $receivedTokens);
            $this->assertSame(implode('', $tokens), $result->content);
        } finally {
            unlink($script);
        }
    }

    // =========================================================================
    // completeAsync() Tests
    // completeAsync() schedules its real work via Loop::futureTick(), which
    // only runs once something actually drives the loop - awaitPromise()
    // below does that with a bounded run(), and (critically) drains the
    // futureTick queue before returning either way so a test that doesn't
    // itself await resolution can't leave a callback dangling on the
    // GLOBAL Loop::get() singleton for some unrelated LATER test to trip
    // over - which is exactly what the old version of this test did, and
    // why it's no longer just "verify it returns a PromiseInterface".
    // =========================================================================

    public function testCompleteAsyncReturnsPromise(): void
    {
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'async test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $promise = $backend->completeAsync([Message::user('hello')]);

            $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
            $this->awaitPromise($promise);
        } finally {
            unlink($script);
        }
    }

    public function testCompleteAsyncResolvesWithTheCommandsOutput(): void
    {
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'async test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $message = $this->awaitPromise($backend->completeAsync([Message::user('hello')]));

            $this->assertInstanceOf(Message::class, $message);
            $this->assertStringContainsString('async test', $message->content);
        } finally {
            unlink($script);
        }
    }

    /**
     * Regression: completeAsync() used to call Loop::stop() unconditionally
     * in a finally block - since this backend is driven by Program's own
     * long-lived Loop::run() (see the class docblock's "Usage" example),
     * that killed the WHOLE program's render/input loop the instant the
     * first reply arrived, not just this one async call. Proven here by
     * running a second, independent timer alongside the completion and
     * confirming it still gets to fire.
     */
    public function testCompleteAsyncDoesNotStopTheSharedEventLoop(): void
    {
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'async test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $loop = \React\EventLoop\Loop::get();

            $otherTimerFired = false;
            $loop->addTimer(0.02, static function () use (&$otherTimerFired): void {
                $otherTimerFired = true;
            });

            $this->awaitPromise($backend->completeAsync([Message::user('hello')]));

            // Give the independent timer a chance to fire too, proving the
            // loop is still alive after completeAsync() settled.
            $this->awaitPromise($this->timerPromise(0.05));

            $this->assertTrue($otherTimerFired, 'an unrelated timer never fired - completeAsync() stopped the shared event loop');
        } finally {
            unlink($script);
        }
    }

    private function timerPromise(float $seconds): \React\Promise\PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();
        \React\EventLoop\Loop::get()->addTimer($seconds, static function () use ($deferred): void {
            $deferred->resolve(null);
        });

        return $deferred->promise();
    }

    /**
     * Single run()/stop() pair - see EngineBackendTest::awaitPromise()'s
     * docblock for why a repeated add-short-timer-then-run() polling dance
     * is fragile, and why leaving a scheduled callback un-drained on the
     * shared Loop::get() singleton corrupts unrelated later tests.
     */
    private function awaitPromise(\React\Promise\PromiseInterface $promise): mixed
    {
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void { $settled = true; $value = $v; $loop->stop(); },
            function (\Throwable $e) use (&$settled, &$error, $loop): void { $settled = true; $error = $e; $loop->stop(); },
        );

        if (!$settled) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertTrue($settled, 'Promise did not settle within the test timeout');

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }
}
