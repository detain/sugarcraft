<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Format\FormatRunner;
use SugarCraft\Code\Hooks\BoundedHookInterface;
use SugarCraft\Code\Hooks\BuiltIn\PostEditFormatHook;
use SugarCraft\Code\Hooks\BuiltIn\PostEditLintHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Tools\ReadLedger;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * EDT-1: the post-edit format hook runs the user's `formatCommands` formatter
 * over each edited file, RECORDS every kept rewrite into the launch's shared
 * read ledger (so the formatter's change never reads as an external edit),
 * and NEVER refuses. Plus the wiring seams: the launch hands one ledger to the
 * tool build and to this hook, and `Bootstrap::hooks()` registers the hook only
 * when the map names a command.
 *
 * @see PostEditFormatHook
 * @see FormatRunner
 */
final class PostEditFormatHookTest extends TestCase
{
    use HomeSandboxTrait;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/sc-fmt-hook-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        // Restore HOME BEFORE any cleanup: the per-test sandboxes under
        // $this->root are unlinked by the tests' own finally blocks, and a
        // leaked `HOME` pointing at a deleted directory makes every later
        // `~`-reader in the process fall back to the passwd home — the real
        // `~/.claude/settings.json` of whoever ran the suite. That poison
        // reddened HOME-order-sensitive doors tree-wide once LPT co-sharded
        // them with this file; the trait's whole contract is that the restore
        // happens here, not that each test remembers it.
        $this->restoreHomeSandbox();
        foreach (glob($this->root . '/src/' . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/src');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testItIsABoundedPostToolUseHookOnTheThreeEditingTools(): void
    {
        $hook = new PostEditFormatHook(FormatRunner::new());

        $this->assertSame(PostEditFormatHook::NAME, $hook->name());
        $this->assertSame('post-edit-format', $hook->name());
        $this->assertSame(HookEvent::PostToolUse, $hook->event());
        $this->assertInstanceOf(BoundedHookInterface::class, $hook);
        $this->assertSame('^(Write|Edit|ApplyPatch)$', $hook->matcher());
        foreach (['Write' => 1, 'Edit' => 1, 'ApplyPatch' => 1, 'Read' => 0, 'Bash' => 0] as $tool => $matches) {
            $this->assertSame($matches, preg_match('/' . $hook->matcher() . '/', $tool), "matcher vs {$tool}");
        }
    }

    public function testTheTimeoutLadderKeepsTheLedgerItWasGiven(): void
    {
        $ledger = ReadLedger::new();
        $hook = new PostEditFormatHook(FormatRunner::new()->withTimeout(10.0), $ledger);

        $narrowed = $hook->withTimeoutSeconds(4.0);

        $this->assertSame(4.0, $narrowed->timeoutSeconds());
        $this->assertSame(10.0, $hook->timeoutSeconds(), 'the ladder narrows copies, never the original');
        $this->assertSame($ledger, $narrowed->ledger(), 'a copy that dropped the ledger would silently stop recording');
    }

    public function testAnUnconfiguredMapAndAJailedMissAreSilentAllows(): void
    {
        $this->put('src/a.php', "one\n");
        $cases = [
            'no map at all' => [FormatRunner::new(), 'src/a.php'],
            'no command for the extension' => [FormatRunner::new()->withCommands(['ts' => 'prettier']), 'src/a.php'],
            'the file is not there' => [FormatRunner::new()->withCommands(['php' => 'printf X >> {file}']), 'src/Missing.php'],
        ];

        foreach ($cases as $case => [$runner, $path]) {
            $result = (new PostEditFormatHook($runner))->execute($this->context('Write', $path));
            $this->assertTrue($result->isAllowed(), $case);
            $this->assertSame(HookResult::ALLOW, $result->action, $case . ' verdict');
            $this->assertSame('', $result->additionalContext, $case);
        }

        $this->assertSame("one\n", file_get_contents($this->root . '/src/a.php'));
    }

    public function testAKeeperOfARewriteIsRecordedIntoTheLedger(): void
    {
        // The ledger is the whole point: with the hook holding it, a formatter
        // rewrite is NOT an external edit on the next call's staleness check.
        $this->put('src/a.php', "one\n");
        $absolute = $this->root . '/src/a.php';
        $ledger = ReadLedger::new();
        $ledger->record($absolute);
        $this->assertSame([], $ledger->changedSinceRead());

        $hook = new PostEditFormatHook(FormatRunner::new()->withCommands(['php' => 'printf "two\\n" >> {file}']), $ledger);
        $result = $hook->execute($this->context('Edit', 'src/a.php'));

        $this->assertTrue($result->isAllowed());
        $this->assertStringContainsString('rewrote src/a.php', $result->additionalContext);
        $this->assertStringContainsString('re-read before editing against line numbers', $result->additionalContext);
        $this->assertSame("one\ntwo\n", file_get_contents($absolute));
        $this->assertSame([], $ledger->changedSinceRead(), 'the recorded formatter rewrite read as an external edit');
    }

    public function testWithoutALedgerTheSameRewriteWouldReadAsExternallyEdited(): void
    {
        // The control half of the polarity above: a null-tolerant embedder
        // still formats, but the ledger still believes the pre-format bytes.
        $this->put('src/a.php', "one\n");
        $absolute = $this->root . '/src/a.php';
        $ledger = ReadLedger::new();
        $ledger->record($absolute);

        $hook = new PostEditFormatHook(FormatRunner::new()->withCommands(['php' => 'printf "two\\n" >> {file}']));
        $result = $hook->execute($this->context('Edit', 'src/a.php'));

        $this->assertTrue($result->isAllowed());
        $this->assertStringContainsString('rewrote src/a.php', $result->additionalContext);
        $this->assertSame([$absolute => 'modified'], $ledger->changedSinceRead(), 'without a ledger the note is all the hook can give');
    }

    public function testARestoredFileNeedsNoRecordingAndStillSaysSo(): void
    {
        // The bytes come back to exactly what the ledger holds, so there is
        // nothing to record — but the model must still hear that the formatter
        // misbehaved, with the formatter's own output as evidence.
        $this->put('src/a.php', "one\n");
        $absolute = $this->root . '/src/a.php';
        $ledger = ReadLedger::new();
        $ledger->record($absolute);
        $hook = new PostEditFormatHook(
            FormatRunner::new()->withCommands(['php' => 'printf X >> {file}; echo cried >&2; exit 3']),
            $ledger,
        );

        $result = $hook->execute($this->context('Write', 'src/a.php'));

        $this->assertTrue($result->isAllowed());
        $this->assertStringContainsString('was put back unchanged', $result->additionalContext);
        $this->assertStringContainsString('cried', $result->additionalContext);
        $this->assertSame("one\n", file_get_contents($absolute));
        $this->assertSame([], $ledger->changedSinceRead());
    }

    public function testACleanRunThatChangedNothingIsSilent(): void
    {
        $this->put('src/a.php', "one\n");
        $hook = new PostEditFormatHook(FormatRunner::new()->withCommands(['php' => 'cat {file} > /dev/null']));

        $result = $hook->execute($this->context('Write', 'src/a.php'));

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->additionalContext);
    }

    // ── C-TOOL-1: the two guards (failed edits, stale blesses) ──────────

    public function testAFailedEditIsNeitherFormattedNorRecorded(): void
    {
        // A refused Write/Edit leaves the USER's bytes on disk — the tool
        // never wrote. Formatting then rewrites the user's file as a side
        // effect of a call that did nothing, and the record() blesses that
        // rewrite, so the model's retry silently overwrites the user's
        // edits. The lint keeps linting failed edits (it only reads); the
        // formatter stands down entirely.
        $this->put('src/a.php', "one\n");
        $absolute = $this->root . '/src/a.php';
        $ledger = ReadLedger::new();
        $ledger->record($absolute);
        $hook = new PostEditFormatHook(FormatRunner::new()->withCommands(['php' => 'printf "two\\n" >> {file}']), $ledger);

        $result = $hook->execute($this->context('Edit', 'src/a.php')->withToolFailed(true));

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->additionalContext, 'a failed call earns no format note');
        $this->assertSame("one\n", file_get_contents($absolute), 'the formatter rewrote the file of a call that failed');
        $this->assertSame([], $ledger->changedSinceRead());
    }

    public function testAStaleFileIsNotBlessedByTheFormatter(): void
    {
        // The second, independent guard: even on a SUCCESSFUL call, a file
        // the ledger already considers stale holds somebody else's work —
        // the tool succeeded earlier and the user edited since, say. The
        // formatter may rewrite it (its run is guarded and noted), but the
        // kept rewrite must NOT be recorded: re-hashing would launder the
        // user's change into the model's own baseline and the next Edit
        // would clobber it with no refusal at all.
        $this->put('src/a.php', "one\n");
        $absolute = $this->root . '/src/a.php';
        $ledger = ReadLedger::new();
        $ledger->record($absolute);          // the ledger believes "one\n"
        file_put_contents($absolute, "the user's own edit\n"); // external change: already stale

        $hook = new PostEditFormatHook(FormatRunner::new()->withCommands(['php' => 'printf "two\\n" >> {file}']), $ledger);
        $result = $hook->execute($this->context('Write', 'src/a.php'));

        $this->assertTrue($result->isAllowed());
        $this->assertStringContainsString('rewrote src/a.php', $result->additionalContext, 'the format itself still runs on a successful call');
        $this->assertSame("the user's own edit\ntwo\n", file_get_contents($absolute));
        $this->assertSame(
            [$absolute => 'modified'],
            $ledger->changedSinceRead(),
            'the formatter blessed an already-stale file — the next Edit would clobber the user with no refusal',
        );
    }

    public function testAFreshFileIsStillBlessedByTheRecordingHalfOfTheGuard(): void
    {
        // The polarity partner: bytes the ledger agrees with are formatted
        // AND recorded exactly as before — the guard must not silently stop
        // the recording that makes the hook exist.
        $this->put('src/a.php', "one\n");
        $absolute = $this->root . '/src/a.php';
        $ledger = ReadLedger::new();
        $ledger->record($absolute);

        $hook = new PostEditFormatHook(FormatRunner::new()->withCommands(['php' => 'printf "two\\n" >> {file}']), $ledger);
        $result = $hook->execute($this->context('Edit', 'src/a.php'));

        $this->assertStringContainsString('rewrote src/a.php', $result->additionalContext);
        $this->assertSame("one\ntwo\n", file_get_contents($absolute));
        $this->assertSame([], $ledger->changedSinceRead(), 'a rewrite of a file the ledger agreed with was not recorded');
    }

    // ── the launch wiring (THE GAP this item closes) ───────────────────

    public function testTheLaunchHandsOneLedgerToTheToolsAndToTheHook(): void
    {
        $ledger = Bootstrap::readLedger($this->root);

        $this->assertSame($ledger, Bootstrap::readLedger($this->root), 'the memo is per root, not per call');
        $this->assertSame($ledger, ReadLedger::in(Bootstrap::unfilteredTools($this->root)), 'the tool build got a different ledger — the shared picture is broken');
        $this->assertNotSame($ledger, Bootstrap::readLedger($this->root . '/src'), 'a second root is a second session picture');

        $this->useHomeSandbox($this->root . '/home');
        mkdir($this->root . '/home/.candy-code', 0o700, true);
        file_put_contents(
            $this->root . '/home/.candy-code/config.json',
            (string) json_encode(['formatCommands' => ['php' => 'printf X >> {file}']]),
        );
        try {
            $registered = Bootstrap::hooks(null, $this->root)->hook(HookEvent::PostToolUse->value, PostEditFormatHook::NAME);

            $this->assertInstanceOf(PostEditFormatHook::class, $registered);
            $this->assertSame($ledger, $registered->ledger(), 'the hook formats into one picture and the tools read another');
            $this->assertSame(['php' => 'printf X >> {file}'], $registered->runner()->commands());
            // Ahead of the lint in the chain means registered ahead of it;
            // DocFigure pins the textual order, this pins the live chain has
            // both under their reserved names.
            $this->assertNotNull(Bootstrap::hooks(null, $this->root)->hook(HookEvent::PostToolUse->value, PostEditLintHook::NAME));
        } finally {
            @unlink($this->root . '/home/.candy-code/config.json');
            @rmdir($this->root . '/home/.candy-code');
            @rmdir($this->root . '/home');
        }
    }

    public function testTheHookStaysOutOfTheChainWhenNoCommandIsConfigured(): void
    {
        $this->useHomeSandbox($this->root . '/home2');
        mkdir($this->root . '/home2/.candy-code', 0o700, true);
        file_put_contents($this->root . '/home2/.candy-code/config.json', '{}');
        $chain = Bootstrap::hooks(null, $this->root);

        try {
            $this->assertNull($chain->hook(HookEvent::PostToolUse->value, PostEditFormatHook::NAME), 'an empty map must not register a hook with nothing to run');
            $this->assertNotNull($chain->hook(HookEvent::PostToolUse->value, PostEditLintHook::NAME), 'the lint is still unconditional');
        } finally {
            @unlink($this->root . '/home2/.candy-code/config.json');
            @rmdir($this->root . '/home2/.candy-code');
            @rmdir($this->root . '/home2');
        }
    }

    public function testTheLaunchArmsTheTableOnlyForATrustedProject(): void
    {
        // C-TOOL-5.b: formatAuto registers the hook with an EMPTY map — the
        // armed table is the something-to-run — but only when the same root
        // is in trustedProjectHooks, the gate the project hook files need.
        $this->useHomeSandbox($this->root . '/home3');
        mkdir($this->root . '/home3/.candy-code', 0o700, true);
        file_put_contents(
            $this->root . '/home3/.candy-code/config.json',
            (string) json_encode([
                'formatAuto' => true,
                'trustedProjectHooks' => [$this->root],
            ]),
        );
        try {
            $registered = Bootstrap::hooks(null, $this->root)->hook(HookEvent::PostToolUse->value, PostEditFormatHook::NAME);

            $this->assertInstanceOf(PostEditFormatHook::class, $registered);
            $this->assertSame([], $registered->runner()->commands(), 'the arm registers with the map still empty, not with a synthesized entry');
            $this->assertTrue($registered->runner()->autoArmed());
        } finally {
            @unlink($this->root . '/home3/.candy-code/config.json');
            @rmdir($this->root . '/home3/.candy-code');
            @rmdir($this->root . '/home3');
        }
    }

    public function testTheLaunchRefusesTheArmedTableForAnUntrustedProject(): void
    {
        $this->useHomeSandbox($this->root . '/home4');
        mkdir($this->root . '/home4/.candy-code', 0o700, true);
        file_put_contents(
            $this->root . '/home4/.candy-code/config.json',
            (string) json_encode(['formatAuto' => true]),
        );
        try {
            $chain = Bootstrap::hooks(null, $this->root);

            $this->assertNull($chain->hook(HookEvent::PostToolUse->value, PostEditFormatHook::NAME), 'the settings bit alone must not arm the table in an untrusted checkout');
            $this->assertNotNull($chain->hook(HookEvent::PostToolUse->value, PostEditLintHook::NAME), 'the lint is still unconditional');
        } finally {
            @unlink($this->root . '/home4/.candy-code/config.json');
            @rmdir($this->root . '/home4/.candy-code');
            @rmdir($this->root . '/home4');
        }
    }

    // ── harness ────────────────────────────────────────────────────────

    private function context(string $tool, string $path): HookContext
    {
        $args = ['file_path' => $path];

        return new HookContext('s', $tool, $args, (string) json_encode($args), '', 'm', 'p', $this->root);
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o700, true);
        file_put_contents($path, $contents);
    }
}
