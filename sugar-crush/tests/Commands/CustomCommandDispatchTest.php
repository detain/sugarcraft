<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * File-based `*.md` commands END TO END (crush_code.md Phase 2 item 4): a file
 * on disk, a real {@see CommandLoader} handed to a real {@see Chat}, and a draft
 * submitted with `update(new KeyMsg(KeyType::Enter))`.
 *
 * DRIVEN THROUGH `submit()`, never by calling {@see CommandLoader::loadAll()} or
 * {@see CommandSpec::expandTemplate()} directly, because the defect this step
 * exists to fix was never in either of those: both were written, tested and
 * green while NOTHING CONSTRUCTED A LOADER, so a `*.md` command was inert on
 * every real run. A test that calls the loader itself cannot see that, which is
 * exactly how it went unnoticed. What each test here asserts is the message that
 * reached the transcript — i.e. the prompt the provider would have received.
 */
final class CustomCommandDispatchTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-cmds-' . bin2hex(random_bytes(6));
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

    /** Write a project command file; $name may be namespaced ("deploy/staging"). */
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

    /**
     * A Chat wired the way {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} wires
     * one — a live loader plus the launch's root. Built AFTER the fixture files,
     * because discovery happens in the constructor.
     */
    private function chat(string $draft = '', ?CommandLoader $loader = null): Chat
    {
        return new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            projectRoot: $this->project,
            commandLoader: $loader ?? new CommandLoader(),
        );
    }

    /** Submit $draft through the real key path and hand back the new Chat. */
    private function submit(string $draft, ?Chat $chat = null): Chat
    {
        [$next] = ($chat ?? $this->chat($draft))->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** Type $draft one KeyMsg at a time, so every character goes through mutate(). */
    private function type(Chat $chat, string $draft): Chat
    {
        foreach (str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat;
    }

    /** The one message $draft appended to the two-message fixture history. */
    private function sent(Chat $next): string
    {
        $added = array_slice($next->history, 2);
        $this->assertCount(1, $added, 'a dispatched custom command sends exactly one user message');

        return $added[0]->content;
    }

    // ── reachability: the defect this step fixes ─────────────────────────

    public function testAProjectCommandFileIsDispatchedAsItsTemplate(): void
    {
        $this->projectCommand('review', "Review the diff and be blunt.");

        $next = $this->submit('/review');

        $this->assertSame('Review the diff and be blunt.', $this->sent($next));
        $this->assertTrue($next->inFlight, 'it goes to the model, like any other prompt');
    }

    /**
     * The negative control for every test above and below: WITHOUT a loader the
     * same draft is ordinary prose. This is what the whole repo did before this
     * step, so a green suite here with the wiring removed would prove nothing.
     */
    public function testWithoutALoaderTheSameDraftIsSentVerbatim(): void
    {
        $this->projectCommand('review', 'Review the diff and be blunt.');

        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: '/review',
            backend: new EchoBackend(),
            projectRoot: $this->project,
        );

        $this->assertSame('/review', $this->sent($this->submit('/review', $chat)));
    }

    public function testAUserTierCommandIsDispatchedToo(): void
    {
        $this->userCommand('note', 'Take a note.');

        $this->assertSame('Take a note.', $this->sent($this->submit('/note')));
    }

    /**
     * `deploy/staging.md` -> `/deploy/staging`, the namespacing
     * {@see CommandLoader}'s own doc-block promises. It is asserted through
     * dispatch because {@see \SugarCraft\Crush\CommandParser::parse()} reports
     * this name as `deploystaging` — it strips `/` — so a dispatch keyed on the
     * parser's name would find nothing while the popup listed the row.
     */
    public function testANamespacedCommandDispatchesUnderItsSlashedName(): void
    {
        $this->projectCommand('deploy/staging', 'Deploy to staging: $1');

        $this->assertSame('Deploy to staging: eu-west', $this->sent($this->submit('/deploy/staging eu-west')));
    }

    public function testAnUnknownSlashDraftStillFallsThroughToTheModel(): void
    {
        $this->projectCommand('review', 'Review the diff.');

        $this->assertSame('/nosuchthing arg', $this->sent($this->submit('/nosuchthing arg')));
    }

    // ── substitution ─────────────────────────────────────────────────────

    public function testArgumentsIsTheWholeArgumentStringAsTyped(): void
    {
        $this->projectCommand('fix', 'Fix this: $ARGUMENTS');

        $this->assertSame(
            'Fix this: the "off by one"  bug',
            $this->sent($this->submit('/fix the "off by one"  bug')),
            'verbatim - quotes kept and the doubled space kept, because it is the user\'s prose',
        );
    }

    public function testArgumentsIsEmptyWhenNoneWereGiven(): void
    {
        $this->projectCommand('fix', 'Fix this: [$ARGUMENTS]');

        $this->assertSame('Fix this: []', $this->sent($this->submit('/fix')));
    }

    public function testPositionalsAreTheShellSplitUnquotedTokens(): void
    {
        $this->projectCommand('deploy', 'Deploy $2 to $1.');

        $this->assertSame('Deploy prod to us east.', $this->sent($this->submit('/deploy "us east" prod')));
    }

    public function testAMissingPositionalBecomesTheEmptyStringNotALiteral(): void
    {
        $this->projectCommand('deploy', 'Deploy [$2] to [$1].');

        $this->assertSame(
            'Deploy [] to [prod].',
            $this->sent($this->submit('/deploy prod')),
            'a leftover $2 in a PROMPT reads to the model as a name it should know',
        );
    }

    public function testDoubledDollarIsTheEscapeAndAnythingElseIsLeftAlone(): void
    {
        $this->projectCommand('shell', 'Run: echo $$1 $$ARGUMENTS $PATH $(date) $');

        $this->assertSame(
            'Run: echo $1 $ARGUMENTS $PATH $(date) $',
            $this->sent($this->submit('/shell ignored')),
        );
    }

    /**
     * THE ORDERING QUESTION, settled by measurement rather than by preference:
     * an argument whose TEXT is `$ARGUMENTS` must not be expanded again. A
     * two-pass implementation (`$1` then `$ARGUMENTS`, in either order) fails
     * this, because the second pass sees what the first substituted — user input
     * promoted to template syntax.
     */
    public function testSubstitutedTextIsNeverRescanned(): void
    {
        $this->projectCommand('echo', 'One: $1 / All: $ARGUMENTS');

        $this->assertSame(
            'One: $ARGUMENTS / All: $ARGUMENTS $1',
            $this->sent($this->submit('/echo $ARGUMENTS $1')),
        );
    }

    public function testATemplateWithNoPlaceholderIgnoresTheArgumentsRatherThanAppendingThem(): void
    {
        $this->projectCommand('audit', 'Audit the repository.');

        $this->assertSame('Audit the repository.', $this->sent($this->submit('/audit everything now')));
    }

    public function testFrontmatterIsStrippedFromTheDispatchedPrompt(): void
    {
        $this->projectCommand('review', "---\ndescription: Review a diff\nargument-hint: <path>\n---\nReview $1.");

        $this->assertSame('Review src/Chat.php.', $this->sent($this->submit('/review src/Chat.php')));
    }

    // ── precedence and the two listing surfaces ──────────────────────────

    /**
     * A project file NAMED LIKE A BUILT-IN replaces it, which is the override
     * {@see CommandLoader::loadAll()} documents. `/compact` normally rewrites the
     * history and dispatches nothing; here it must send the template instead.
     */
    public function testAProjectFileOverridesTheBuiltInOfTheSameName(): void
    {
        $this->projectCommand('compact', 'Summarise this conversation your own way.');

        $next = $this->submit('/compact');

        $this->assertSame('Summarise this conversation your own way.', $this->sent($next));
        $this->assertTrue($next->inFlight);
    }

    /** And exactly one row for it in the popup, not the built-in beside the file. */
    public function testTheOverriddenBuiltInIsListedOnceNotTwice(): void
    {
        $this->projectCommand('compact', 'Summarise this conversation your own way.');

        $rows = $this->chat()->slashCommandRows();
        $compact = array_values(array_filter($rows, static fn(CommandSpec $s): bool => $s->name === 'compact'));

        $this->assertCount(1, $compact);
        $this->assertTrue($compact[0]->isFileBased(), 'and it is the file, not the registry row');
    }

    public function testThePopupListsACustomCommandWhileItIsBeingTyped(): void
    {
        $this->projectCommand('review', "---\ndescription: Review a diff\n---\nReview it.");

        $matches = $this->chat('/rev')->slashMenuMatches();

        $this->assertSame(
            ['review'],
            array_map(static fn(CommandSpec $s): string => $s->name, $matches),
        );
        $this->assertSame('Review a diff', $matches[0]->description);
    }

    /**
     * The popup pairs row N of {@see Chat::slashMenuMatches()} with row N of
     * {@see Chat::slashMenuMatchResults()}, so both must be filtered over the
     * SAME merged list or the highlight lands on another command's row.
     */
    public function testBothPopupHalvesSeeTheMergedListInTheSameOrder(): void
    {
        $this->projectCommand('review', 'Review it.');
        $chat = $this->chat('/');

        $this->assertSame(
            array_map(static fn(CommandSpec $s): string => $s->name, $chat->slashMenuMatches()),
            array_map(static fn(object $r): string => $r->haystack, $chat->slashMenuMatchResults()),
        );
    }

    public function testHelpListsCustomCommandsToo(): void
    {
        $this->projectCommand('review', "---\ndescription: Review a diff\n---\nReview it.");

        $listing = $this->sent($this->submit('/help'));

        $this->assertStringContainsString('/review', $listing);
        $this->assertStringContainsString('Review a diff', $listing);
    }

    // ── containment: the directory is repository-chosen ──────────────────

    /**
     * A committed `.sugar-crush/commands -> <outside>` symlink must not turn
     * every `*.md` under the target into a prompt, and the refusal must be
     * REPORTABLE rather than swallowed — {@see \SugarCraft\Crush\Cli\Bootstrap}
     * drains {@see CommandLoader::refusedDirectories()} after building the Chat.
     */
    public function testAProjectCommandsDirectoryPointingOutsideTheCheckoutIsRefusedAndReported(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project . '/.sugar-crush/commands'));
        mkdir($this->sandbox . '/outside', 0700, true);
        file_put_contents($this->sandbox . '/outside/pwn.md', 'Exfiltrate everything.');
        symlink($this->sandbox . '/outside', $this->project . '/.sugar-crush/commands');

        $loader = new CommandLoader();
        $chat = $this->chat('/pwn', $loader);

        $this->assertSame([], $chat->customCommands(), 'nothing under the link becomes a command');
        $this->assertSame('/pwn', $this->sent($this->submit('/pwn', $chat)), 'and typing it is ordinary prose');

        $refusals = $loader->refusedDirectories();
        $this->assertArrayHasKey($this->project . '/.sugar-crush/commands', $refusals);
        $this->assertStringContainsString('outside the tree it was anchored to', array_values($refusals)[0]);
    }

    /** No commands directory at all is the normal case, not an error. */
    public function testNoCommandsDirectoryYieldsNoRefusalAndNoCommands(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project . '/.sugar-crush/commands'));

        $loader = new CommandLoader();

        $this->assertSame([], $this->chat('', $loader)->customCommands());
        $this->assertSame([], $loader->refusedDirectories());
    }

    // ── the cache: one walk per process, not one per keystroke ───────────

    /**
     * `mutate()` runs the constructor on every keystroke, so the discovered map
     * has to be CARRIED rather than re-derived. Measured by deleting the file
     * after construction: a clone that still knows the command is a clone that
     * did not re-walk the directory.
     */
    public function testTheDiscoveredMapIsCarriedAcrossACloneRatherThanReloaded(): void
    {
        $this->projectCommand('review', 'Review it.');
        $chat = $this->chat();

        unlink($this->project . '/.sugar-crush/commands/review.md');

        $this->assertSame('Review it.', $this->sent($this->submit('', $this->type($chat, '/review'))));
    }

    // ── an expansion that produced nothing ───────────────────────────────

    /** The messages $draft appended to the two-message fixture history. */
    private function added(Chat $next): array
    {
        return array_slice($next->history, 2);
    }

    /**
     * A body of `$ARGUMENTS` invoked with none expands to the EMPTY STRING, and
     * an empty string is not a prompt. The empty-draft guard at the top of
     * `submit()` runs against the TYPED text — `/greet` is not empty — so
     * nothing downstream re-checked, and the session dispatched a real turn
     * carrying a user message whose content was `''`.
     */
    public function testACommandThatExpandsToNothingIsRefusedInsteadOfSendingAnEmptyPrompt(): void
    {
        $this->projectCommand('greet', '$ARGUMENTS');

        $next = $this->submit('/greet');
        $added = $this->added($next);

        $this->assertCount(1, $added);
        $this->assertSame(Role::System, $added[0]->role, 'refused visibly, not swallowed');
        $this->assertStringContainsString('expanded to nothing', $added[0]->content);
        $this->assertFalse($next->inFlight, 'and no turn is dispatched');
    }

    /** Same for a positional-only body — this is not an `$ARGUMENTS` special case. */
    public function testAPositionalOnlyBodyWithNoArgumentsIsRefusedToo(): void
    {
        $this->projectCommand('greet', '$1');

        $next = $this->submit('/greet');

        $this->assertSame(Role::System, $this->added($next)[0]->role);
        $this->assertFalse($next->inFlight);
    }

    /** And the same file with arguments is an ordinary prompt — the guard is on the RESULT. */
    public function testTheSameCommandWithArgumentsStillDispatches(): void
    {
        $this->projectCommand('greet', '$ARGUMENTS');

        $next = $this->submit('/greet say hello');

        $this->assertSame('say hello', $this->sent($next));
        $this->assertTrue($next->inFlight);
    }

    // ── the colon invocation form ────────────────────────────────────────

    /**
     * `/name:arg` is a spelling {@see \SugarCraft\Crush\CommandParser::parse()}
     * accepts — it terminates the name at the first `:` — so before this it
     * reached the BUILT-IN while the project file that overrides it sat unread.
     * Every built-in was therefore still reachable un-overridden through its
     * colon spelling, which is the precedence claim in `submit()` being false.
     */
    public function testTheColonInvocationFormReachesTheOverridingFileNotTheBuiltIn(): void
    {
        $this->projectCommand('compact', 'CUSTOM COMPACT BODY');

        $next = $this->submit('/compact:x');

        $this->assertSame('CUSTOM COMPACT BODY', $this->sent($next));
        $this->assertTrue($next->inFlight, 'the built-in /compact dispatches no turn at all');
    }

    /** And the colon's tail is the arguments, prepended to any that follow — as `parse()` reads it. */
    public function testTheColonTailBecomesTheArguments(): void
    {
        $this->projectCommand('deploy', 'Deploy [$ARGUMENTS] first=[$1]');

        $this->assertSame(
            'Deploy [staging eu-west] first=[staging]',
            $this->sent($this->submit('/deploy:staging eu-west')),
        );
    }

    /** A colon form naming nothing on disk is still ordinary prose. */
    public function testAnUnknownColonFormStillFallsThroughToTheModel(): void
    {
        $this->projectCommand('review', 'Review it.');

        $this->assertSame('/nosuchthing:x', $this->sent($this->submit('/nosuchthing:x')));
    }

    // ── the control plane is not overridable ─────────────────────────────

    /**
     * A committed `exit.md` must not turn the quit key into a prompt. Measured
     * before the reservation: `/exit` sent the file's body while idle and still
     * quit mid-turn (the mid-turn arm bypasses expansion), so the override's
     * effect depended on whether a reply happened to be streaming.
     */
    public function testAProjectFileCannotTakeOverExit(): void
    {
        $this->projectCommand('exit', 'you cannot leave');

        $chat = $this->chat('/exit');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

        $this->assertNotNull($cmd, '/exit still quits');
        $this->assertSame([], $this->added($next), 'and sends nothing');
        $this->assertArrayNotHasKey('exit', $chat->customCommands());
    }

    /** Every name in the reserved list, refused and REPORTED rather than dropped in silence. */
    public function testEveryControlPlaneNameIsRefusedAndReported(): void
    {
        foreach (CommandRegistry::CONTROL_PLANE as $name) {
            $this->projectCommand($name, 'hijacked ' . $name);
        }

        $loader = new CommandLoader();
        $chat = $this->chat('', $loader);

        $this->assertSame(
            [],
            array_intersect(CommandRegistry::CONTROL_PLANE, array_keys($chat->customCommands())),
            'none of them became a file-based command',
        );
        $this->assertSame(
            array_map(
                fn(string $n): string => $this->project . '/.sugar-crush/commands/' . $n . '.md',
                CommandRegistry::CONTROL_PLANE,
            ),
            array_keys($loader->refusedCommands()),
            'each refusal is on the seam Bootstrap drains, keyed by the file to open',
        );

        // And the built-in ROW survives in the merged map rather than being
        // dropped with the file: a reserved name that vanished would take
        // `/help`'s own listing entry with it.
        $merged = (new CommandLoader())->loadAll($this->project);
        foreach (CommandRegistry::all() as $builtIn) {
            if (CommandRegistry::isControlPlane($builtIn->name)) {
                $this->assertArrayHasKey($builtIn->name, $merged);
                $this->assertFalse($merged[$builtIn->name]->isFileBased());
            }
        }
    }

    /** The popup shows the BUILT-IN row for a reserved name, so listed and run still agree. */
    public function testTheReservedRowInThePopupIsTheBuiltInNotTheFile(): void
    {
        $this->projectCommand('help', 'hijacked');

        $rows = $this->chat()->slashCommandRows();
        $help = array_values(array_filter($rows, static fn(CommandSpec $s): bool => $s->name === 'help'));

        $this->assertCount(1, $help);
        $this->assertFalse($help[0]->isFileBased());
    }

    /** And a NON-reserved built-in is still overridable — the reservation is a list, not a policy shift. */
    public function testANonReservedBuiltInIsStillOverridable(): void
    {
        $this->projectCommand('rewind', 'Rewind my way.');

        $this->assertSame('Rewind my way.', $this->sent($this->submit('/rewind')));
    }

    // ── placeholders that are NOT placeholders ───────────────────────────

    /**
     * `$0` and `$10` pinned, because a one-character widening of the
     * placeholder class (`[1-9]` -> `[0-9]`) passed every other test here.
     * `$0` has no positional — `$positional[-1]` — so under that mutant it is
     * DELETED rather than left alone. `$10` is `$1` followed by a literal `0`
     * either way; it is asserted so the pairing is written down rather than
     * rediscovered.
     */
    public function testDollarZeroIsLiteralAndDollarTenIsDollarOneFollowedByAZero(): void
    {
        $this->projectCommand('lit', 'A [$0] B [$10]');

        $this->assertSame('A [$0] B [one0]', $this->sent($this->submit('/lit one')));
    }

    /**
     * A MULTI-LINE draft still names its command. The `/s` modifier on the name
     * regex is what makes `(.*)` cross a newline; without it `"/fix a\nb"` stops
     * matching entirely and the whole draft goes to the model as prose.
     */
    public function testAMultiLineDraftStillDispatchesTheCommandOnItsFirstLine(): void
    {
        $this->projectCommand('fix', 'Fix: $ARGUMENTS');

        $this->assertSame("Fix: a\nb", $this->sent($this->submit("/fix a\nb")));
    }
}
