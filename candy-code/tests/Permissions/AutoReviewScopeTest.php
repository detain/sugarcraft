<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\ExecReviewer;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;

/**
 * C-SEC-7.d: `autoReviewScope` says WHICH pattern-list-passed calls Auto
 * mode sends to the exec reviewer — `shell` (the ISS-013 default, frozen),
 * `writes` (plus the file-writing tools and `WebFetch`) or `all` — and
 * `autoReviewModel` says WHICH `provider/model` reviews them, the title
 * backend being the absence. Both are user-tier because every review is a
 * paid call, and both fail CLOSED: an unknown scope narrows back to
 * `shell`, and a model override that cannot be spelled or built leaves the
 * reviewer OFF with one stderr warning rather than burning money on a
 * half-understood guess.
 */
final class AutoReviewScopeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $base = '';

    private string $home = '';

    private string $root = '';

    protected function setUp(): void
    {
        $base = \sys_get_temp_dir() . '/ars-' . \bin2hex(\random_bytes(6));
        \mkdir($base . '/home/.candy-code', 0o700, true);
        \mkdir($base . '/project', 0o700, true);
        $this->base = (string) \realpath($base);
        $this->home = $this->base . '/home';
        $this->root = $this->base . '/project';
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
        self::resetWarnOnceStore();
    }

    protected function tearDown(): void
    {
        self::resetWarnOnceStore();
        SessionSettings::reset();
        Bootstrap::useProjectRootForSettings(null);
        UiSettings::forget();
        $this->restoreHomeSandbox();
        $this->sweepTree($this->base);
    }

    // ── the scope, at the gate ──────────────────────────────────────────

    public function testTheDefaultScopeReviewsShellCallsOnly(): void
    {
        $seen = [];
        $gate = self::gate(self::backend('{"decision":"deny","risk":"high","rationale":"unused"}', $seen));

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::bash('make -j4')));
        self::assertCount(1, $seen, 'shell is reviewed at the default scope, as ever since ISS-013');
        self::assertSame('unflagged-shell', $gate->autoBreaker()['lastBlockedCategory']);

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Edit', ['file_path' => $this->root . '/notes.md', 'patch' => 'x']), $this->root),
        );
        self::assertCount(1, $seen, 'with no scope named, a passed Edit still pays for no review');
    }

    public function testTheWritesScopeReviewsTheFileToolsWithTheOtherBasis(): void
    {
        $this->userSettings(['autoReviewScope' => 'writes']);
        $seen = [];
        $gate = self::gate(self::backend('{"decision":"deny","risk":"high","rationale":"nothing asked for this"}', $seen));

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Edit', ['file_path' => $this->root . '/notes.md', 'patch' => 'x']), $this->root),
        );
        self::assertCount(1, $seen, 'the writes scope puts a passed Edit in front of the reviewer');
        self::assertStringContainsString(
            'The safety classifier flagged it as: nothing — the pattern list passed this call, and the autoReviewScope includes it',
            $seen[0][1]->content,
            'the reviewer is told the widened basis honestly',
        );
        self::assertSame('unflagged-review', $gate->autoBreaker()['lastBlockedCategory']);

        // A read is neither shell nor one of the named write subjects: the
        // scope's membership list is closed, not a vague "anything risky".
        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Read', ['file_path' => $this->root . '/notes.md']), $this->root),
        );
        self::assertCount(1, $seen);
    }

    public function testTheAllScopeReviewsAPassedReadToo(): void
    {
        $this->userSettings(['autoReviewScope' => 'all']);
        $seen = [];
        $gate = self::gate(self::backend('{"decision":"allow","risk":"low","rationale":"ordinary read"}', $seen));

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Read', ['file_path' => $this->root . '/notes.md']), $this->root),
        );
        self::assertCount(1, $seen, '`all` reviews every call the pattern list passed, not just the named subjects');
        self::assertStringContainsString('the autoReviewScope includes it', $seen[0][1]->content);
    }

    public function testAnUnknownScopeFailsClosedToShellOnly(): void
    {
        // strict enum + the gate's own re-check: whichever layer refuses the
        // value, the effect must be the NARROWEST paid-review set.
        $this->userSettings(['autoReviewScope' => 'everything']);
        $seen = [];
        $gate = self::gate(self::backend('{"decision":"allow","risk":"low","rationale":"fine"}', $seen));

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Edit', ['file_path' => $this->root . '/notes.md', 'patch' => 'x']), $this->root),
        );
        self::assertSame([], $seen, 'a scope nobody recognises must never widen the paid set');

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::bash('make -j4')));
        self::assertCount(1, $seen, 'and shell keeps its review anyway');
    }

    // ── the model override, at the builder ──────────────────────────────

    public function testTheModelOverrideBuildsItsOwnToollessBackend(): void
    {
        $this->userSettings([
            'autoReview' => true,
            'autoReviewModel' => 'probe7d/probe-model-7d',
            'providers' => [
                'probe7d' => ['type' => 'custom', 'name' => 'Probe7d', 'baseUrl' => 'http://127.0.0.1:9'],
            ],
        ]);

        $gate = Bootstrap::permissionGate(false, false);
        $reviewer = $gate->reviewer();
        self::assertNotNull($reviewer, 'the override names a buildable provider/model, so the reviewer is on');

        $backend = (new \ReflectionProperty(ExecReviewer::class, 'backend'))->getValue($reviewer);
        self::assertInstanceOf(\SugarCraft\Code\Backend\EngineBackend::class, $backend);
        self::assertSame(
            'probe-model-7d',
            (new \ReflectionProperty(\SugarCraft\Code\Backend\EngineBackend::class, 'model'))->getValue($backend),
            'the reviewer runs on the NAMED model, not the title one',
        );
        self::assertSame(
            [],
            (new \ReflectionProperty(\SugarCraft\Code\Backend\EngineBackend::class, 'tools'))->getValue($backend),
            'the reviewer runs on a TOOL-LESS backend: routing the reviewer onto a user-chosen provider must not hand that provider the live tool set (audit 15b-12)',
        );
    }

    public function testAnEmptyOverrideIsAbsenceAndFallsBackToTheTitlePath(): void
    {
        $this->userSettings(['autoReview' => true, 'autoReviewModel' => '  ']);

        $gate = Bootstrap::permissionGate(false, false);
        self::assertNull($gate->reviewer(), 'no provider is selected, so the title path has nothing to review with');
        self::assertTrue(LaunchNotices::reported(Lang::t('cli.warn.auto_review_no_provider')), 'said the old way');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badOverrides(): iterable
    {
        yield 'no slash' => ['probe-model-7d', 'cli.warn.auto_review_model_bad_shape'];
        yield 'empty provider half' => ['/probe-model-7d', 'cli.warn.auto_review_model_bad_shape'];
        yield 'empty model half' => ['probe7d/', 'cli.warn.auto_review_model_bad_shape'];
        yield 'not a string' => ['42', 'cli.warn.auto_review_model_not_string'];
        yield 'unknown provider' => ['nosuchprovider7d/probe-model-7d', 'cli.warn.auto_review_model_unusable'];
    }

    #[DataProvider('badOverrides')]
    public function testABrokenOverrideLeavesTheReviewerOffAndWarnsOnce(string $raw, string $warnKey): void
    {
        $this->userSettings(['autoReview' => true, 'autoReviewModel' => $raw === '42' ? 42 : $raw]);

        $gate = Bootstrap::permissionGate(false, false);
        self::assertNull($gate->reviewer(), 'a half-understood paid override is refused, not guessed around');
        self::assertTrue(LaunchNotices::reported(Lang::t($warnKey)), 'and it is said out loud exactly once');

        $before = self::warnOnceStoreSize();
        Bootstrap::permissionGate(false, false);
        self::assertSame($before, self::warnOnceStoreSize(), 'the second build adds no second stderr line');
    }

    public function testWithAutoReviewOffNeitherCompanionIsEvenRead(): void
    {
        $this->userSettings(['autoReviewModel' => 'probe7d/probe-model-7d']);

        $gate = Bootstrap::permissionGate(false, false);
        self::assertNull($gate->reviewer(), 'the reviewer switch is autoReview; the companions only shape it');
        self::assertSame(0, self::warnOnceStoreSize(), 'and nothing is warned about while autoReview is off');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function userSettings(array $data): void
    {
        $path = $this->home . '/.candy-code/' . LayeredSettings::USER_FILE;
        \file_put_contents($path, (string) \json_encode($data));
        \chmod($path, 0o600);
        UiSettings::forget();
    }

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

    /** The warn-once store is process static; each run starts and ends clean. */
    private static function resetWarnOnceStore(): void
    {
        (new \ReflectionProperty(LaunchNotices::class, 'reportedPermissionConfigWarnings'))
            ->setValue(null, []);
    }

    private static function warnOnceStoreSize(): int
    {
        return \count(
            (new \ReflectionProperty(LaunchNotices::class, 'reportedPermissionConfigWarnings'))
                ->getValue(null),
        );
    }

    private function sweepTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                \is_dir($path) && !\is_link($path) ? $this->sweepTree($path) : @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
