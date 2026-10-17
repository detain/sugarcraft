<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\ToolCall;

/**
 * Audit 15b-27: `Message::describeToolCall()` encoded each argument with a
 * bare `json_encode()`, which returns `false` on one invalid UTF-8 byte — and
 * `false` concatenates as '', so `["command" => "caf\xe9"]` was described as
 * `bash(command: )` and the permission modal built from that description asked
 * the user to approve a command it did not show.
 */
final class MessageDescribeToolCallEncodingTest extends TestCase
{
    public function testAnInvalidByteIsSubstitutedNotBlanked(): void
    {
        $described = Message::describeToolCall(new ToolCall('bash', ['command' => "caf\xe9 && rm x"], 'c1'));

        $this->assertSame("bash(command: \"caf\u{FFFD} && rm x\")", $described);
        $this->assertTrue(mb_check_encoding($described, 'UTF-8'));
    }

    public function testANonStringArgumentWithAnInvalidByteIsNotBlankedEither(): void
    {
        $described = Message::describeToolCall(new ToolCall('edit', ['edits' => ['old' => "caf\xe9"]], 'c1'));

        $this->assertStringContainsString("caf\u{FFFD}", $described);
    }

    public function testAZeroValueIsShownNotDropped(): void
    {
        $this->assertSame('read(limit: "0")', Message::describeToolCall(new ToolCall('read', ['limit' => 0], 'c1')));
    }

    public function testUnicodeIsReadableButControlsAndBidiStayEscaped(): void
    {
        $described = Message::describeToolCall(
            new ToolCall('bash', ['command' => "cat 文件 \u{9b}2J \u{202E}hs.txt \u{200B}x"], 'c1'),
        );

        $this->assertStringContainsString('文件', $described);
        $this->assertStringContainsString('\u009b2J', $described);
        $this->assertStringContainsString('\u202ehs.txt', $described);
        $this->assertStringContainsString('\u200bx', $described);
        $this->assertDoesNotMatchRegularExpression('/\xC2[\x80-\x9F]|\xE2\x80[\x8B\xAE]/', $described);
    }

    /**
     * ISS-100: a structural (array/object) argument used to be json_encode()d
     * and then passed through encodeArgument() again, so the label read
     * `todos: "[{\"content\":…}]"` with every inner quote doubled. Structural
     * JSON is now shown once-escaped — brackets bare, invisibles still swept.
     */
    public function testAStructuralArgumentIsNotReEncoded(): void
    {
        $described = Message::describeToolCall(new ToolCall('Compress', ['refs' => ['r3', 'r5']], 'c1'));

        $this->assertSame('Compress(refs: ["r3","r5"])', $described);
        $this->assertStringNotContainsString('\\"', $described, 'no doubled quotes may survive');
    }

    public function testAScalarNonStringArgumentKeepsItsQuotedRendering(): void
    {
        // The zero-pin above owns the numeric case; this pins that the
        // structural exemption did not widen to scalars.
        $described = Message::describeToolCall(new ToolCall('read', ['pages' => [1, 2]], 'c1'));

        $this->assertSame('read(pages: [1,2])', $described);
    }

    /**
     * ISS-100: per-tool summarisers. The tally is what belongs on the card;
     * malformed shapes must fall through to the mechanical dump untouched.
     */
    public function testTodoAndPruneAreSummarised(): void
    {
        $todo = Message::describeToolCall(new ToolCall('Todo', ['todos' => [
            ['content' => 'a', 'status' => 'completed'],
            ['content' => 'b', 'status' => 'in_progress'],
            ['content' => 'c', 'status' => 'pending'],
        ]], 'c1'));
        $this->assertSame('Todo(3 items, 1 in progress)', $todo);

        $single = Message::describeToolCall(new ToolCall('Todo', ['todos' => [
            ['content' => 'b', 'status' => 'pending'],
        ]], 'c1'));
        $this->assertSame('Todo(1 item)', $single);

        $prune = Message::describeToolCall(new ToolCall('Prune', [
            'targets' => [['ref' => 'r3'], ['ref' => 'r5']],
            'reason' => 'noise',
        ], 'c1'));
        $this->assertSame('Prune(pruned r3, r5)', $prune);

        $capped = Message::describeToolCall(new ToolCall('Prune', [
            'targets' => array_map(static fn(int $i): array => ['ref' => "r{$i}"], range(1, 9)),
            'reason' => 'noise',
        ], 'c1'));
        $this->assertSame('Prune(pruned r1, r2, r3, r4, r5, r6, +3 more)', $capped);
    }

    public function testTheSummarisersRefuseForeignShapes(): void
    {
        // Not a list, missing keys, or non-string content → mechanical dump.
        $this->assertStringContainsString(
            'todos:',
            Message::describeToolCall(new ToolCall('Todo', ['todos' => ['content' => 'x', 'status' => 'pending']], 'c1')),
        );
        $this->assertStringContainsString(
            'targets:',
            Message::describeToolCall(new ToolCall('Prune', ['targets' => [['ref' => 17]]], 'c1')),
        );
        // A model-authored description still outranks the tally.
        $this->assertSame(
            'plan the week',
            Message::describeToolCall(new ToolCall('Todo', ['description' => 'plan the week', 'todos' => [['content' => 'a', 'status' => 'pending']]], 'c1')),
        );
    }

    public function testThePermissionModalShowsTheCommand(): void
    {
        $method = new \ReflectionMethod(Renderer::class, 'wrapPermissionText');
        $shown = (string) $method->invoke(
            null,
            Message::describeToolCall(new ToolCall('bash', ['command' => "caf\xe9"], 'c1')),
            60,
        );

        $this->assertStringContainsString("command: \"caf\u{FFFD}\"", $shown);
    }
}
