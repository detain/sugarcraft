<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\ExecReviewer;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\ToolCall;

/**
 * ISS-013, the fix's second bullet: "Send every non-read-only shell call
 * through `ExecReviewer` when `autoReview` is on."
 *
 * The pattern list is a list of SPELLINGS, and the spellings this issue
 * reproduced — `bash <(curl -s …)`, `curl … | python3`, a credential read
 * riding inside a `curl` URL's command substitution — are download-and-execute
 * shapes it never flagged, so they ran outright under `auto`. With a reviewer
 * configured, a shell command the classifier passed is now put to the
 * reviewer instead of waved through: the same three verdicts, the same
 * breaker, the honest basis line ("nothing — the pattern list passed this
 * call"). A reviewer that cannot answer ASKS, per its own doctrine; a gate
 * without `autoReview` keeps the old fast path exactly, and read-only auto-
 * allowed lines never pay for a review they need no part of.
 */
final class UnflaggedShellReviewTest extends TestCase
{
    /** A command the pattern list passes and the read-only set does not cover. */
    private const MAKE = 'make -j4';

    public function testAnUnflaggedShellCallGoesToTheReviewerAndItsAllowRunsIt(): void
    {
        $seen = [];
        $gate = self::gate(self::backend('{"decision":"allow","risk":"low","rationale":"ordinary build"}', $seen));

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::bash(self::MAKE)));
        self::assertCount(1, $seen, 'the pattern list passed it and the reviewer still saw it');
        self::assertStringContainsString(
            'The safety classifier flagged it as: nothing — the pattern list passed this call',
            $seen[0][1]->content,
            'the reviewer is told honestly that nothing was flagged',
        );
        self::assertSame(0, $gate->autoBreaker()['totalBlocks'], 'a reviewer allow is a safe call');
    }

    public function testAReadOnlyAutoAllowedLineNeverReachesTheReviewer(): void
    {
        $seen = [];
        $gate = self::gate(self::backend('{"decision":"deny","risk":"high","rationale":"unused"}', $seen))
            ->withReadOnlyAutoAllow(true);

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::bash('ls -la')));
        self::assertSame([], $seen, 'the read-only fast path pays for no review');
    }

    public function testAReviewerQuestionOnAPassedCallAsksAndIsNotAStrike(): void
    {
        $gate = self::gate(self::backend('{"decision":"ask","risk":"unknown","rationale":"cannot tell what make builds here"}'));

        self::assertSame(PermissionDecision::Ask, $gate->evaluate(self::bash(self::MAKE)));
        self::assertSame(
            'not flagged by the pattern list; auto reviewer asked it (unknown risk): cannot tell what make builds here',
            $gate->lastAutoReason(),
        );
        self::assertSame(0, $gate->autoBreaker()['totalBlocks'], 'a question is not a strike');
    }

    public function testThreeReviewerDenialsOfPassedCallsEscalateThroughTheBreaker(): void
    {
        $gate = self::gate(self::backend('{"decision":"deny","risk":"high","rationale":"nothing asked for this"}'));

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::bash('make')));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::bash('npm run build')));
        self::assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(self::bash('python3 -m build')),
            'three reviewer denials in a row still reach a person',
        );
        self::assertSame(3, $gate->autoBreaker()['consecutiveBlocks']);
        self::assertSame('unflagged-shell', $gate->autoBreaker()['lastBlockedCategory']);
    }

    public function testNonShellCallsThePatternListPassedStillSkipTheReviewer(): void
    {
        $seen = [];
        $root = \sys_get_temp_dir() . '/gapperm-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0o700);
        try {
            $gate = self::gate(self::backend('{"decision":"deny","risk":"high","rationale":"unused"}', $seen));

            self::assertSame(
                PermissionDecision::Allow,
                $gate->evaluate(new ToolCall('Edit', ['file_path' => $root . '/notes.md', 'patch' => 'x']), $root),
            );
            self::assertSame([], $seen, 'the issue asks for every shell call, not a paid review per Edit');
        } finally {
            @\rmdir($root);
        }
    }

    public function testAPtyRunCallWithoutKeystrokesIsReviewedLikeBash(): void
    {
        $seen = [];
        $gate = self::gate(self::backend('{"decision":"allow","risk":"low","rationale":"fine"}', $seen));

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('PtyRun', ['command' => self::MAKE])));
        self::assertCount(1, $seen);
    }

    public function testWithoutAReviewerThePatternListVerdictStandsAlone(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        self::assertNull($gate->reviewer());
        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::bash(self::MAKE)));
        self::assertSame(0, $gate->autoBreaker()['totalBlocks']);
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private static function bash(string $command): ToolCall
    {
        return new ToolCall('Bash', ['command' => $command]);
    }

    private static function gate(Backend $backend): PermissionGate
    {
        return new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier(), null, ExecReviewer::new($backend));
    }

    /**
     * A backend answering $reply, recording every request into $seen.
     *
     * @param list<list<Message>> $seen
     */
    private static function backend(string $reply, array &$seen = []): Backend
    {
        return new class ($reply, $seen) implements Backend {
            /** @param list<list<Message>> $seen */
            public function __construct(private readonly string $reply, private array &$seen)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen[] = $history;

                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history));
            }
        };
    }
}
