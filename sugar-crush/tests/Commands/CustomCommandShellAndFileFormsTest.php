<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolCall;

/**
 * The two template forms that LEAVE THE STRING: `` !`cmd` `` runs a shell and
 * `@path` reads a file (crush_code.md Phase 2 item 4, second half).
 *
 * DRIVEN THROUGH `submit()` wherever a claim is about what the model receives,
 * for {@see CustomCommandDispatchTest}'s reason: both halves of this feature
 * were shipped as unreachable code once already, and a test that calls
 * {@see CommandSpec::expandTemplate()} itself cannot see that. The direct-call
 * tests below are the ones whose claim is about a MECHANISM's bound — a
 * timeout's expiry, a byte cap — where driving a Chat would only add noise
 * between the measurement and the assertion.
 *
 * EVERY POSITIVE TEST HAS ITS NEGATIVE. A test that only asserts a refusal
 * NOTICE appears proves nothing about whether the command ran: `echo` writes to
 * a pipe, but a `touch` writes to the filesystem, so the refusal tests below
 * assert on a MARKER FILE that the refused command would have created. That is
 * the difference between pinning the presence of a sentence and pinning its
 * truth.
 */
final class CustomCommandShellAndFileFormsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-cmdforms-' . bin2hex(random_bytes(6));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project . '/.sugar-crush/commands', 0700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    private function projectCommand(string $name, string $body): void
    {
        $path = $this->project . '/.sugar-crush/commands/' . $name . '.md';
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0700, true);
        }
        file_put_contents($path, $body);
    }

    private function userCommand(string $name, string $body): void
    {
        $dir = $this->sandbox . '/home/.sugar-crush/commands';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/' . $name . '.md', $body);
    }

    private function chat(bool $trusted = false, ?HookManager $hooks = null): Chat
    {
        return new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            hooks: $hooks,
            projectRoot: $this->project,
            commandLoader: new CommandLoader(),
            projectCommandsTrusted: $trusted,
        );
    }

    /** The one message $draft appended to the two-message fixture history. */
    private function submit(string $draft, bool $trusted = false, ?HookManager $hooks = null): string
    {
        $chat = $this->chat($trusted, $hooks);
        // The draft is replaced wholesale rather than typed, except where a test
        // deliberately exercises the per-keystroke clone path below.
        [$next] = (new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($chat, ['inputBuf' => $draft])
            ->update(new KeyMsg(KeyType::Enter));

        $added = array_slice($next->history, 2);
        $this->assertCount(1, $added, 'a dispatched custom command sends exactly one user message');

        return $added[0]->content;
    }

    /** A path inside the sandbox that a refused command would have created. */
    private function marker(string $name): string
    {
        return $this->sandbox . '/' . $name;
    }

    // ── !`cmd`: the user tier is the operator's own file ─────────────────

    public function testAUserTierCommandRunsItsShellSubstitution(): void
    {
        $this->userCommand('branch', 'On !`printf %s twig` now.');

        $this->assertSame('On twig now.', $this->submit('/branch'));
    }

    /**
     * The negative control for every user-tier test: the SAME body in the
     * project tier does not run. Without this pair, "the user tier runs" and
     * "the project tier does not" could both be satisfied by an implementation
     * that ran neither or one that ran both.
     */
    public function testTheSameBodyInTheProjectTierIsRefusedWhileUntrusted(): void
    {
        $marker = $this->marker('project-ran');
        // The command PRINTS "twig" without CONTAINING it, because a refusal
        // notice quotes the command it declined — so a command whose text held
        // its own output would make the assertion below unfalsifiable.
        $this->projectCommand('branch', 'On !`touch ' . $marker . ' && printf %s%s tw ig` now.');

        $sent = $this->submit('/branch');

        $this->assertFileDoesNotExist($marker, 'the command must not have run at all');
        $this->assertStringNotContainsString('twig', $sent);
        $this->assertStringContainsString('trustedProjectCommands', $sent, 'the notice must name the opt-in');
        $this->assertStringContainsString('On ', $sent, 'the rest of the template is still sent');
        $this->assertStringContainsString(' now.', $sent);
    }

    public function testTheSameProjectCommandRunsOnceTheProjectIsTrusted(): void
    {
        $marker = $this->marker('project-ran');
        $this->projectCommand('branch', 'On !`touch ' . $marker . ' && printf %s%s tw ig` now.');

        $sent = $this->submit('/branch', trusted: true);

        $this->assertFileExists($marker);
        $this->assertSame('On twig now.', $sent);
    }

    /**
     * The trust grant is READ ON THE SUBMITTING KEYSTROKE, which is always a
     * clone of the Chat the Bootstrap built. Typing the draft one character at a
     * time is what proves `mutate()` carries it: drop the entry and this test
     * reds while every test above stays green.
     */
    public function testTheTrustGrantSurvivesEveryKeystrokeClone(): void
    {
        $this->projectCommand('branch', '!`printf %s twig`');

        $chat = $this->chat(trusted: true);
        foreach (str_split('/branch') as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        $this->assertSame('twig', array_slice($next->history, 2)[0]->content);
    }

    // ── the injection shape: keystrokes may not become a command ─────────

    /**
     * AN ARGUMENT IS NEVER RE-SCANNED, so a `` !`…` `` typed as an argument is
     * prose. This is the property {@see CommandSpec::TEMPLATE_PATTERN}'s single
     * pass exists for, measured at the only place it matters: the shell.
     */
    public function testAnArgumentThatLooksLikeAShellFormIsNotRun(): void
    {
        $marker = $this->marker('argument-ran');
        $this->userCommand('note', '!`printf %s inner` / $ARGUMENTS');

        $sent = $this->submit('/note !`touch ' . $marker . '`');

        $this->assertFileDoesNotExist($marker);
        $this->assertStringStartsWith('inner / !`touch ', $sent);
    }

    /**
     * And the converse: a `$ARGUMENTS` written INSIDE `` !`…` `` is consumed by
     * the shell branch of the same alternation, so it is not substituted and the
     * user's text never reaches the command line. Single-quoted in the template
     * so what is asserted is this class's behaviour and not `bash`'s
     * variable-expansion rules.
     */
    public function testAPlaceholderInsideAShellFormIsNotSubstituted(): void
    {
        $this->userCommand('note', "!`printf %s '\$ARGUMENTS'`");

        $sent = $this->submit('/note SECRET');

        $this->assertSame('$ARGUMENTS', $sent);
        $this->assertStringNotContainsString('SECRET', $sent);
    }

    // ── !`cmd`: exit status and stderr ───────────────────────────────────

    public function testANonZeroExitBringsItsStderrAndItsCodeIntoThePrompt(): void
    {
        $this->userCommand('check', "!`printf %s out; printf %s boom >&2; exit 3`");

        $sent = $this->submit('/check');

        $this->assertStringContainsString('out', $sent);
        $this->assertStringContainsString('boom', $sent, 'a failure is useless in a prompt without the reason');
        $this->assertStringContainsString('exited 3', $sent);
    }

    public function testStderrIsLeftOutWhenTheCommandSucceeded(): void
    {
        $this->userCommand('check', "!`printf %s keep; printf %s noise >&2`");

        $sent = $this->submit('/check');

        $this->assertSame('keep', $sent);
    }

    // ── !`cmd`: the shared time budget ───────────────────────────────────

    /**
     * The bound, measured rather than asserted from the notice: a command that
     * would run for five seconds is killed at the 0.3s it was given, and the
     * call returns in well under the five.
     */
    public function testACommandIsKilledAtTheBudgetItWasGiven(): void
    {
        $spec = CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x', tier: 'user');

        $started = microtime(true);
        $out = $spec->runShellSubstitution('sleep 5', null, 0.3);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(2.0, $elapsed, 'the timeout must bound the call, not merely be reported');
        $this->assertStringContainsString('was killed after 0.3 seconds', $out);
        $this->assertStringContainsString('10-second shell budget', $out, 'the notice names the budget it is a remainder of');
    }

    /**
     * THE BUDGET BOUNDS THE EXIT, NOT ONLY THE OUTPUT. A command that closes or
     * redirects its own stdout and stderr and then keeps running gives the read
     * loop EOF on its first iteration, so nothing is left to wait on — and
     * `proc_close()` waits for the child. MEASURED against the build before the
     * polling wait was added: a 0.3-second budget returned after 6.00 seconds,
     * i.e. the documented bound on how long the single-threaded TUI can freeze
     * held over the wrong half of the call.
     *
     * Asserted on ELAPSED TIME rather than on the notice, because the notice was
     * already correct while the freeze was six seconds long.
     */
    public function testACommandThatClosesItsOwnOutputIsStillBoundedByTheBudget(): void
    {
        $spec = CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x', tier: 'user');

        $started = microtime(true);
        $out = $spec->runShellSubstitution('printf %s early; exec 1>&- 2>&-; sleep 6', null, 0.3);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(2.0, $elapsed, 'the budget must bound the wait for the child to exit too');
        $this->assertStringContainsString('early', $out, 'what it printed before detaching is kept');
        $this->assertStringContainsString('was killed after 0.3 seconds', $out);
    }

    /**
     * A form arriving with the budget already spent does NOT run. Asserted on
     * the filesystem, because "returns a notice" and "returns a notice AND ran
     * the command" are the same string.
     */
    public function testAnExhaustedBudgetRunsNothingAtAll(): void
    {
        $marker = $this->marker('spent-ran');
        $spec = CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x', tier: 'user');

        $out = $spec->runShellSubstitution('touch ' . $marker, null, 0.0);

        $this->assertFileDoesNotExist($marker);
        $this->assertStringContainsString('used all of it', $out);
    }

    /**
     * THE BUDGET IS PER EXPANSION, NOT PER COMMAND: the second `` !`…` `` in one
     * body is offered strictly less time than the first, by at least what the
     * first spent. A per-command timeout would hand both the same number, which
     * is the mutation this kills.
     */
    public function testTheSecondShellFormIsOfferedWhatTheFirstLeft(): void
    {
        $offered = [];
        $spec = CommandSpec::new(
            name: 'x',
            description: 'x',
            category: 'Custom',
            template: '!`a` !`b`',
            tier: 'user',
        );

        $spec->expandTemplate('', [], function (string $kind, string $payload, float $remaining) use (&$offered): string {
            $offered[] = $remaining;
            usleep(250000);

            return '';
        });

        $this->assertCount(2, $offered);
        $this->assertSame((float) CommandSpec::SHELL_BUDGET_SECONDS, $offered[0]);
        $this->assertLessThan($offered[0] - 0.2, $offered[1]);
    }

    // ── !`cmd`: the permission gate ──────────────────────────────────────

    public function testAnExplicitDenyRuleRefusesTheSubstitution(): void
    {
        $marker = $this->marker('denied-ran');
        // Prints "twig" without containing it — see the tier test above.
        $this->userCommand('branch', '!`touch ' . $marker . ' && printf %s%s tw ig`');

        $registry = new HookRegistry();
        $hooks = new HookManager($registry);
        $hooks->register(new PermissionGateHook(new PermissionGate(
            PermissionMode::Default,
            [new PermissionRule('Bash', PermissionAction::Deny)],
        )));

        $sent = $this->submit('/branch', hooks: $hooks);

        $this->assertFileDoesNotExist($marker);
        $this->assertStringNotContainsString('twig', $sent);
        $this->assertStringContainsString('permission mode (default) denies it', $sent);
    }

    /**
     * And the control: the SAME gate without the rule allows it. Without this
     * the test above would also pass against an implementation that refused
     * whenever a gate was present at all.
     */
    public function testTheSameGateWithoutADenyRuleAllowsIt(): void
    {
        $this->userCommand('branch', '!`printf %s twig`');

        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new PermissionGateHook(new PermissionGate(PermissionMode::Default)));

        $this->assertSame('twig', $this->submit('/branch', hooks: $hooks));
    }

    /**
     * NOTHING RUNS DURING RENDER. `view()` is called on every frame and the
     * popup lists a command by name while it is being typed; a substitution
     * reached from there would run the command once per keystroke and would
     * break the Model contract's "side effects go in a Cmd, never in view()".
     * There is one call site for expansion and it is inside `submit()` — this is
     * what keeps that true.
     */
    public function testTypingAndRenderingACommandRunsNothing(): void
    {
        $marker = $this->marker('render-ran');
        $this->userCommand('branch', '!`touch ' . $marker . '`');

        $chat = $this->chat(trusted: true);
        foreach (str_split('/bra') as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
            $chat->view();
        }

        $this->assertFileDoesNotExist($marker);
    }

    // ── @path ────────────────────────────────────────────────────────────

    public function testAnIncludeBringsTheRepositoryFileIntoThePrompt(): void
    {
        file_put_contents($this->project . '/NOTES.md', 'the note body');
        $this->projectCommand('recap', 'Recap: @NOTES.md');

        $this->assertSame('Recap: the note body', $this->submit('/recap'));
    }

    /**
     * `@path` IS NOT GATED BY THE PROJECT TRUST GRANT and `` !`cmd` `` IS — the
     * asymmetry this feature is built on. The include above ran with
     * `projectCommandsTrusted: false`; this pins that the two answers are
     * genuinely different rather than accidentally equal.
     */
    public function testAnIncludeWorksUntrustedWhileAShellFormInTheSameBodyDoesNot(): void
    {
        file_put_contents($this->project . '/NOTES.md', 'body');
        $marker = $this->marker('mixed-ran');
        $this->projectCommand('recap', '@NOTES.md / !`touch ' . $marker . '`');

        $sent = $this->submit('/recap');

        $this->assertStringStartsWith('body / ', $sent);
        $this->assertFileDoesNotExist($marker);
    }

    /**
     * AN INCLUDED FILE'S OWN TEMPLATE FORMS ARE NOT RESOLVED. Substitution is
     * one pass over the COMMAND FILE's body, so text an `@path` splices in is
     * invisible to the matcher — which is what stops the include from being a
     * second, ungated way into the shell: `@NOTES.md` is not gated by the trust
     * grant, so if its contents were re-scanned an untrusted repository would
     * run a command by putting it in an ordinary markdown file.
     *
     * Run with the project TRUSTED on purpose. Untrusted, a re-scan would be
     * refused and the marker would be absent for the wrong reason, and the test
     * would pass against the implementation it is meant to catch.
     */
    public function testAnIncludedFilesOwnTemplateFormsAreNotResolved(): void
    {
        $marker = $this->marker('included-ran');
        file_put_contents(
            $this->project . '/NOTES.md',
            '!`touch ' . $marker . '` and $ARGUMENTS',
        );
        $this->projectCommand('recap', '@NOTES.md');

        $sent = $this->submit('/recap SECRET', trusted: true);

        $this->assertFileDoesNotExist($marker, 'a command inside an included file must not run');
        $this->assertSame('!`touch ' . $marker . '` and $ARGUMENTS', $sent);
        $this->assertStringNotContainsString('SECRET', $sent, 'nor is a placeholder inside it expanded');
    }

    public function testAnIncludeThatEscapesTheCheckoutIsRefusedAndItsBytesNeverArrive(): void
    {
        file_put_contents($this->sandbox . '/secret.txt', 'PRIVATE-KEY-MATERIAL');
        $this->projectCommand('leak', 'Look: @../secret.txt');

        $sent = $this->submit('/leak');

        $this->assertStringNotContainsString('PRIVATE-KEY-MATERIAL', $sent);
        $this->assertStringContainsString('resolves outside', $sent);
    }

    public function testASymlinkUnderTheCheckoutPointingOutsideIsRefusedToo(): void
    {
        file_put_contents($this->sandbox . '/secret.txt', 'PRIVATE-KEY-MATERIAL');
        symlink($this->sandbox . '/secret.txt', $this->project . '/inbox.txt');
        $this->projectCommand('leak', 'Look: @inbox.txt');

        $sent = $this->submit('/leak');

        $this->assertStringNotContainsString('PRIVATE-KEY-MATERIAL', $sent);
        $this->assertStringContainsString('resolves outside', $sent);
    }

    /**
     * A MISSING FILE IS REPORTED AS MISSING, not as an escape. Two different
     * facts, and reporting the containment answer for a path that simply is not
     * there is the measured-on-A-stated-of-B defect this repository keeps
     * finding.
     */
    public function testAMissingIncludeIsReportedAsMissingRatherThanAsAnEscape(): void
    {
        $this->projectCommand('recap', '@absent.md');

        $sent = $this->submit('/recap');

        $this->assertStringContainsString('does not name an existing file', $sent);
        $this->assertStringNotContainsString('resolves outside', $sent);
    }

    /**
     * And the notice does not claim to have looked UNDER the checkout, because
     * for an escaping reference it did not: `is_file()` is tested before the
     * containment compare (which is what the test above pins), so
     * `@../secret.txt` with no such file lands in the MISSING branch and the
     * composed path it stat'd was outside the root. "No such file under <root>"
     * was measured on the root and stated of a path not in it.
     */
    public function testTheMissingNoticeDoesNotClaimToHaveLookedInsideTheCheckout(): void
    {
        $this->projectCommand('recap', 'Read @../secret.txt now');

        $sent = $this->submit('/recap');

        $this->assertStringContainsString('was not included', $sent);
        $this->assertStringNotContainsString('under ' . $this->project, $sent);
        $this->assertStringNotContainsString('no such file under', $sent);
    }

    /**
     * An ABSOLUTE reference is not an include at all — it does not match the
     * pattern, so it stays literal and no read is attempted. Pinned because the
     * alternative implementation (match it, then refuse it) would stat a path of
     * the repository's choosing anywhere on the disk.
     */
    public function testAnAbsoluteReferenceStaysLiteral(): void
    {
        // A real, readable file outside the checkout, so "stays literal" is
        // falsifiable: if the pattern accepted a leading `/` this body's bytes
        // would be in the prompt instead of the reference.
        file_put_contents($this->sandbox . '/secret.txt', 'PRIVATE-KEY-MATERIAL');
        $reference = '@' . $this->sandbox . '/secret.txt';
        $this->projectCommand('recap', 'see ' . $reference . ' for it');

        $sent = $this->submit('/recap');

        $this->assertSame('see ' . $reference . ' for it', $sent);
        $this->assertStringNotContainsString('PRIVATE-KEY-MATERIAL', $sent);
    }

    public function testAnEmailAddressIsNotAnInclude(): void
    {
        $this->projectCommand('mail', 'ask joe@example.com about it');

        $this->assertSame('ask joe@example.com about it', $this->submit('/mail'));
    }

    public function testAnIncludeIsClippedToOneSubstitutionsShare(): void
    {
        $big = str_repeat('x', CommandSpec::MAX_SUBSTITUTION_BYTES + 500);
        file_put_contents($this->project . '/big.md', $big);
        $spec = CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x', tier: 'project');

        $out = $spec->includeFile('big.md', $this->project);

        $this->assertStringContainsString('truncated', $out);
        $this->assertLessThan(\strlen($big), \strlen($out));
        $this->assertStringStartsWith(str_repeat('x', 100), $out);
    }

    /**
     * A USER-tier command's `@path` is resolved against the CHECKOUT, not
     * against the operator's home — the asymmetry
     * {@see CommandSpec::includeFile()} argues for. Pinned because the
     * "symmetrical" alternative (user tier -> `$HOME`) would give the weaker of
     * the two checks the wider reach.
     */
    public function testAUserTierIncludeIsStillResolvedAgainstTheCheckout(): void
    {
        file_put_contents($this->project . '/NOTES.md', 'project body');
        file_put_contents($this->sandbox . '/home/NOTES.md', 'home body');
        $this->userCommand('recap', '@NOTES.md');

        $this->assertSame('project body', $this->submit('/recap'));
    }

    /**
     * A refusal quotes the command it declined, so the quote is bounded: an
     * absurdly long one-liner must not cost more context refused than it would
     * have cost run.
     */
    public function testARefusalDoesNotQuoteAnUnboundedCommandBackIntoThePrompt(): void
    {
        $long = 'printf %s ' . str_repeat('z', 4000);
        $this->projectCommand('big', '!`' . $long . '`');

        $sent = $this->submit('/big');

        $this->assertStringContainsString('…(clipped)', $sent);
        $this->assertLessThan(1000, \strlen($sent), 'the whole notice, quote included, stays small');
    }

    public function testAbbreviateFormLeavesAShortCommandExactlyAsWritten(): void
    {
        $this->assertSame('git status', CommandSpec::abbreviateForm('git status'));
        $this->assertSame(
            str_repeat('a', CommandSpec::MAX_QUOTED_FORM_BYTES),
            CommandSpec::abbreviateForm(str_repeat('a', CommandSpec::MAX_QUOTED_FORM_BYTES)),
            'the bound is inclusive, so a command exactly at it is not clipped',
        );
        $this->assertSame(
            str_repeat('a', CommandSpec::MAX_QUOTED_FORM_BYTES) . '…(clipped)',
            CommandSpec::abbreviateForm(str_repeat('a', CommandSpec::MAX_QUOTED_FORM_BYTES + 1)),
        );
    }

    // ── no resolver at all ───────────────────────────────────────────────

    /**
     * {@see CommandSpec::expandTemplate()} called WITHOUT a directive resolver —
     * every embedder and unit test that predates this feature — refuses both
     * forms rather than leaving them literal, and runs nothing.
     */
    public function testWithoutAResolverBothFormsAreRefusedAndNothingRuns(): void
    {
        $marker = $this->marker('resolverless-ran');
        $spec = CommandSpec::new(
            name: 'x',
            description: 'x',
            category: 'Custom',
            template: '!`touch ' . $marker . '` @NOTES.md',
            tier: 'user',
        );

        $out = (string) $spec->expandTemplate('');

        $this->assertFileDoesNotExist($marker);
        $this->assertStringContainsString('was not run', $out);
        $this->assertStringContainsString('was not included', $out);
    }

    // ── the tier stamp itself ────────────────────────────────────────────

    public function testTheLoaderStampsEachTierOntoItsRows(): void
    {
        $this->userCommand('mine', 'body');
        $this->projectCommand('theirs', 'body');
        $loader = new CommandLoader();

        $this->assertSame('user', $loader->loadUserCommands()['mine']->tier);
        $this->assertSame('project', $loader->loadProjectCommands($this->project)['theirs']->tier);
    }

    /**
     * A built-in row carries no tier, and must not: {@see CommandSpec::$tier} is
     * read to decide whether a shell may run, and a built-in has no template to
     * run one from.
     */
    public function testABuiltInRowCarriesNoTier(): void
    {
        foreach (\SugarCraft\Crush\Commands\CommandRegistry::all() as $spec) {
            $this->assertNull($spec->tier, "/{$spec->name} is a built-in and has no disk tier");
        }
    }

    /**
     * An UNTIERED spec — one an in-process caller built with
     * {@see CommandSpec::new()} — is treated as the caller's own, not as a
     * project's. Documented in {@see Chat::refuseCommandShell()}; pinned here
     * because the alternative reading (null is not 'user', so refuse) would
     * silently break every embedder that injects `customCommands`.
     */
    public function testAnUntieredInjectedSpecMayStillRunItsShellForm(): void
    {
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            projectRoot: $this->project,
            customCommands: ['branch' => CommandSpec::new(
                name: 'branch',
                description: 'x',
                category: 'Custom',
                template: '!`printf %s twig`',
            )],
        );

        [$next] = (new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($chat, ['inputBuf' => '/branch'])
            ->update(new KeyMsg(KeyType::Enter));

        $this->assertSame('twig', array_slice($next->history, 2)[0]->content);
    }

    // ── The scan itself: a body large enough to defeat PCRE ──────────────

    /**
     * THE BODY LENGTH IS THE ONE INPUT A HOSTILE REPOSITORY CONTROLS DIRECTLY,
     * and the first spelling of {@see CommandSpec::TEMPLATE_PATTERN} had a
     * nested quantifier — `(?:[\w.\-]+\/)*` — so a long enough body exhausted
     * PCRE's JIT stack, `preg_replace_callback()` answered null, and the fallback
     * `?? $this->template` delivered the UNSCANNED body: `` !`…` `` and `@…`
     * forms reaching a tool-using model as literal instructions, which is the
     * outcome {@see CommandSpec::expandTemplate()}'s doc-block rejects.
     *
     * MEASURED, at the length the old pattern died on (50018 bytes of body): the
     * flat pattern scans it, so every form in it is resolved or refused rather
     * than passed through. Driven through `submit()` because the claim is about
     * what the model receives, and asserted on the MARKER as well as on the text
     * — the project tier is untrusted here, so the shell form must be refused,
     * not merely reworded.
     */
    public function testABodyLongEnoughToHaveDefeatedTheScannerIsStillScanned(): void
    {
        $marker = $this->marker('longbody-ran');
        $this->projectCommand('recap', '@' . str_repeat('a/', 25000) . "b\n"
            . 'Now run !`touch ' . $marker . '` and read @../outside.txt for $ARGUMENTS.');

        $sent = $this->submit('/recap arg-one');

        // Every form was SEEN by the scanner: the `$` one expanded, and the two
        // that leave the string were replaced by their refusals. (Both refusals
        // quote the form back, so "the literal is absent" is not the assertable
        // property here — "the refusal is present, and the command did not run"
        // is.)
        $this->assertFileDoesNotExist($marker);
        $this->assertStringContainsString('was not run', $sent);
        $this->assertStringContainsString('was not included', $sent);
        $this->assertStringContainsString('arg-one', $sent, '$ARGUMENTS must still have expanded');
        $this->assertStringNotContainsString('$ARGUMENTS', $sent, 'nothing may be left unscanned');
    }

    /**
     * And when PCRE gives up anyway, the body is DISCARDED rather than sent.
     * `pcre.backtrack_limit` is squeezed for the one call so the failure is real
     * (a returned null with a non-zero `preg_last_error()`) rather than
     * simulated; with the flat pattern no ordinary body reaches it, which is why
     * the ini and not a long string is the lever.
     *
     * The RESOLVER IS NEVER CALLED — asserted, because "returns a notice" and
     * "returns a notice after running the command" are different facts and only
     * the second is a defect.
     */
    public function testAScanThatFailsDiscardsTheBodyInsteadOfSendingItLiterally(): void
    {
        $spec = CommandSpec::new(
            name: 'recap',
            description: 'x',
            category: 'Custom',
            template: '@' . str_repeat('a.', 150000) . " and !`echo hi` for \$ARGUMENTS",
            tier: 'user',
        );

        $calls = [];
        $resolver = function (string $kind, string $payload, float $left) use (&$calls): string {
            $calls[] = [$kind, $payload];

            return 'RESOLVED';
        };

        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $out = $spec->expandTemplate('given-args', [], $resolver);
        } finally {
            ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
        }

        $this->assertSame([], $calls, 'an unscanned body must not have its forms resolved');
        $this->assertIsString($out);
        $this->assertStringNotContainsString('!`echo hi`', $out);
        $this->assertStringNotContainsString('given-args', $out);
        $this->assertStringNotContainsString('a.a.a.', $out);
        $this->assertStringContainsString('was not sent', $out);
        // The byte count is the TEMPLATE's, so it is derived from the template
        // rather than written out: a literal here would be a figure measured on
        // one body stated of whatever the next edit makes this one.
        $this->assertStringContainsString((string) \strlen((string) $spec->template) . '-byte template', $out);
    }

    // ── The budget: a refused form must not cost a permission strike ─────

    /**
     * THE SPENT-BUDGET REFUSAL HAPPENS WITHOUT CONSULTING THE RESOLVER, which is
     * a security property and not an optimisation:
     * {@see Chat::refuseCommandShell()} reaches
     * {@see PermissionGate::evaluate()}, which mutates Auto mode's
     * circuit-breaker counters, and a strike banked for a command that never ran
     * is what that method's own doc-block forbids. MEASURED before the check
     * existed: four forms, first one spending the budget, four resolver calls.
     */
    public function testAFormArrivingWithNoBudgetLeftNeverReachesTheResolver(): void
    {
        $spec = CommandSpec::new(
            name: 'burn',
            description: 'x',
            category: 'Custom',
            template: '!`burn` !`b` !`c` !`d`',
            tier: 'user',
        );

        $calls = [];
        // COSTS THE WHOLE BUDGET IN WALL CLOCK, and there is no cheaper honest
        // way to reach the state: the budget is charged on elapsed time and is
        // deliberately not settable from anywhere a caller could shrink it, so
        // "the budget is spent" is a real ~10 seconds. The alternative — a
        // resolver that sleeps a millisecond and an assertion on
        // `array_slice($calls, 0, 1)` — passes whether or not the check exists,
        // which is the presence-not-truth trap this file's header warns about.
        $out = $spec->expandTemplate('', [], function (string $kind, string $payload, float $left) use (&$calls): string {
            $calls[] = $payload;
            if ($payload === 'burn') {
                usleep((int) (CommandSpec::SHELL_BUDGET_SECONDS * 1000000) + 100000);

                return 'burned';
            }

            return 'ran ' . $payload;
        });

        $this->assertSame(['burn'], $calls, 'only the form that spent the budget reached the resolver');
        $this->assertIsString($out);
        $this->assertStringContainsString('burned', $out);
        $this->assertStringNotContainsString('ran b', $out);
        // Three refusals, one per form that arrived with nothing left, each
        // naming the budget rather than being silently dropped.
        $this->assertSame(3, substr_count($out, 'used all of it'));
    }

    /**
     * The same claim where the consequence lives, driven through a real gate: a
     * PROJECT-tier body whose forms are all refused by the TIER rule must leave
     * the gate's Auto circuit breaker exactly as it found it. Auto answers `Deny`
     * for a dangerous command until three CONSECUTIVE strikes flip it to `Ask`,
     * so a probe made after the refusals reads `Deny` if no strike was banked and
     * `Ask` if three were.
     *
     * `fly deploy` is used because {@see SafetyClassifier} classifies it and
     * because the tier refuses it before anything could run it — no `fly` binary
     * is involved.
     */
    public function testATierRefusedFormDoesNotMoveTheGatesCircuitBreaker(): void
    {
        $this->projectCommand('deploy', '!`fly deploy` !`fly deploy` !`fly deploy`');

        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new PermissionGateHook($gate));

        $sent = $this->submit('/deploy', hooks: $hooks);
        $this->assertStringContainsString('trustedProjectCommands', $sent, 'the tier rule is what refused it');

        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'fly deploy'])),
            'a first dangerous call still reads Deny — three refused forms banked no strikes',
        );
    }

    // ── The "bytes dropped" figure names the substitution, not a buffer ──

    /**
     * THE DROP COUNT IS MEASURED ON THE DELIVERED TEXT. Overflow is tracked per
     * FD, and stderr's overflow counts only when stderr is part of the answer —
     * on a zero exit the whole stderr buffer is discarded, for a different
     * reason. MEASURED with one shared counter:
     * `head -c 40000 /dev/zero | tr "\0" y 1>&2; echo ok` reported
     * "[23616 bytes dropped: one substitution may contribute 16384]" beside a
     * three-byte substitution from which nothing had been dropped.
     */
    public function testASucceedingCommandsDiscardedStderrIsNotReportedAsADrop(): void
    {
        $spec = CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x');

        $out = $spec->runShellSubstitution('head -c 40000 /dev/zero | tr "\0" y 1>&2; echo ok', null, 5.0);

        $this->assertSame('ok', $out);
        $this->assertStringNotContainsString('dropped', $out);
    }

    /**
     * And the positive half: stdout that really did exceed one substitution's
     * share reports the EXACT overflow. The expected figure is derived from the
     * bytes written and {@see CommandSpec::MAX_SUBSTITUTION_BYTES} rather than
     * written out, so it cannot outlive an edit to either.
     */
    public function testStdoutOverflowIsReportedAsTheExactNumberOfBytesDropped(): void
    {
        $written = CommandSpec::MAX_SUBSTITUTION_BYTES + 3616;
        $spec = CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x');

        $out = $spec->runShellSubstitution(
            sprintf('head -c %d /dev/zero | tr "\0" y', $written),
            null,
            10.0,
        );

        $expected = sprintf(
            "\n[%d bytes dropped: one substitution may contribute %d]",
            $written - CommandSpec::MAX_SUBSTITUTION_BYTES,
            CommandSpec::MAX_SUBSTITUTION_BYTES,
        );
        $this->assertStringEndsWith($expected, $out);
        $this->assertSame(CommandSpec::MAX_SUBSTITUTION_BYTES, \strlen($out) - \strlen($expected));
    }

    /**
     * The include cap is asserted on the BOUNDARY, not on the word "truncated":
     * the clipped prefix is exactly {@see CommandSpec::MAX_SUBSTITUTION_BYTES}
     * bytes, so a cap that grew by 400 fails here.
     */
    public function testAnIncludeIsClippedAtExactlyOneSubstitutionsShare(): void
    {
        $big = str_repeat('z', CommandSpec::MAX_SUBSTITUTION_BYTES + 500);
        file_put_contents($this->project . '/big.txt', $big);
        $spec = CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x');

        $out = $spec->includeFile('big.txt', $this->project);

        $notice = sprintf(
            "\n[@big.txt truncated: one substitution may contribute %d bytes]",
            CommandSpec::MAX_SUBSTITUTION_BYTES,
        );
        $this->assertStringEndsWith($notice, $out);
        $this->assertSame(
            CommandSpec::MAX_SUBSTITUTION_BYTES,
            \strlen($out) - \strlen($notice),
            'the clip is the constant, not the constant plus something',
        );
    }

    // ── The tier is the loader's answer, never the file's ────────────────

    /**
     * A `tier:` LINE IN FRONTMATTER CANNOT PROMOTE A FILE.
     * {@see CommandSpec::fromFile()}'s doc-block names this attack — "a one-line
     * self-promotion" — and it had no test: the tier is the parameter the loader
     * passes, and the frontmatter is written by the party being gated.
     */
    public function testAFrontmatterTierLineCannotPromoteAProjectFile(): void
    {
        $path = $this->project . '/.sugar-crush/commands/review.md';
        file_put_contents($path, "---\ntier: user\n---\nbody\n");

        $spec = CommandSpec::fromFile($path, 'review', 'project');

        $this->assertSame('project', $spec->tier);
    }

    /**
     * And the same claim as behaviour, through `submit()`: the file says
     * `tier: user`, the directory says project, the project is untrusted, so the
     * marker must not exist.
     */
    public function testAFrontmatterTierLineDoesNotGetAProjectFileAShell(): void
    {
        $marker = $this->marker('promoted-ran');
        $this->projectCommand(
            'review',
            "---\ntier: user\n---\n!`touch " . $marker . ' && printf %s%s tw ig`',
        );

        $sent = $this->submit('/review');

        $this->assertFileDoesNotExist($marker);
        $this->assertStringNotContainsString('twig', $sent);
        $this->assertStringContainsString('trustedProjectCommands', $sent);
    }

    // ── The launch wiring, not just the Chat parameter ───────────────────

    /**
     * THE WHOLE SECURITY PROPERTY IS A LAUNCH DECISION, so it is asserted on the
     * launch. Every test above passes `projectCommandsTrusted` to the
     * constructor; none of them can see
     * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} hard-coding it to true.
     * Both directions, from ONE config file, because "refuses" alone would also
     * pass against a launch that refused unconditionally.
     */
    public function testBootstrapAnswersTheTrustQuestionFromTheUsersConfig(): void
    {
        $marker = $this->marker('bootstrap-ran');
        $this->projectCommand('deploy', '!`touch ' . $marker . ' && printf %s%s tw ig`');
        mkdir($this->sandbox . '/home/.sugar-crush', 0700, true);

        $this->assertFalse(
            \SugarCraft\Crush\Cli\Bootstrap::projectCommandShellIsTrusted($this->project),
            'an empty config trusts nothing',
        );
        $this->assertStringContainsString('trustedProjectCommands', $this->submitThroughBootstrap('/deploy'));
        $this->assertFileDoesNotExist($marker);

        // A SECOND home, because the answer is frozen per config path for the
        // life of the process (Bootstrap's own freeze, so a session cannot make a
        // line it just wrote take effect) — reusing this one would read the cache
        // and prove nothing about the grant.
        $trusted = $this->sandbox . '/home-trusted';
        mkdir($trusted . '/.sugar-crush', 0700, true);
        file_put_contents(
            $trusted . '/.sugar-crush/config.json',
            json_encode(['trustedProjectCommands' => [$this->project]]),
        );
        $this->useHomeSandbox($trusted);

        $this->assertTrue(
            \SugarCraft\Crush\Cli\Bootstrap::projectCommandShellIsTrusted($this->project),
            'the listed root is trusted',
        );
        $this->assertSame('twig', $this->submitThroughBootstrap('/deploy'));
        $this->assertFileExists($marker);
    }

    /**
     * `submit()` on a Chat the BOOTSTRAP built, rather than one this test built.
     *
     * @return string the one message the dispatched command appended
     */
    private function submitThroughBootstrap(string $draft): string
    {
        $chat = \SugarCraft\Crush\Cli\Bootstrap::chat($this->project);
        $before = \count($chat->history);

        [$next] = (new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($chat, ['inputBuf' => $draft])
            ->update(new KeyMsg(KeyType::Enter));

        $added = array_slice($next->history, $before);
        $this->assertCount(1, $added, 'a dispatched custom command sends exactly one user message');

        return $added[0]->content;
    }
}
