<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * WHERE {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt} GETS ATTACHED,
 * pinned, because a whole routing decision rests on the answer.
 *
 * THE CLAIM THIS GUARDS (E155). That class writes four `sugarcrush:` shapes
 * straight to fd 2 and none of them are routed onto
 * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()}'s
 * transcript seam, even though three are refusals and the seam's own rule sends
 * "the session can no longer DO this" there. The reason they stay is not the
 * rule, it is the MECHANISM: the hazard the seam exists for — a write landing
 * on a frame the renderer believes it owns — needs the alternate screen to be
 * up, and this class is only ever attached on paths that never open it. That is
 * a fact about four call sites, and a fact about call sites goes stale.
 *
 * NOT A STYLE RULE. Attaching the prompt somewhere new is a perfectly good
 * change; it is simply one that invalidates the paragraph in that class's
 * docblock, and the correct response to this test failing is to go and re-make
 * the decision, then move the roster. Bumping the roster to make the suite
 * green is the one wrong answer.
 *
 * TOKENS AND NOT `grep`, for the reason the stderr census gives at length: this
 * application's doc-blocks discuss `$consolePermissionPrompt` more often than
 * they pass it, and {@see \SugarCraft\Crush\Cli\Bootstrap} is where both happen.
 */
final class HeadlessPermissionPromptAttachmentTest extends TestCase
{
    /**
     * The zero-based position of `$consolePermissionPrompt` in each entry
     * point's signature.
     *
     * TWO SIGNATURES AND NOT ONE, which is the whole reason this is a map: the
     * flag is the FOURTH argument of `backend()` and the FIFTH of
     * `backendFor()`, so a single index would read one of the two off by one
     * and silently answer about `$gate`.
     *
     * @var array<string, int>
     */
    private const ENTRY_POINTS = [
        'backend' => 3,
        'backendFor' => 4,
    ];

    /**
     * Call sites that pass the flag as a literal `true`, file => count.
     *
     * ALL FOUR ARE HEADLESS. `NonInteractive` is the `-p`/`run` one-shot and
     * `BackgroundSessionRunner` is the daemon whose fd 0 is `/dev/null`; neither
     * takes the terminal. {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} — the
     * interactive path, and the one that DOES open the alternate screen — is
     * absent from this map by taking the parameter's `false` default, which is
     * what {@see testTheInteractivePathDoesNotAttachIt()} states positively.
     *
     * @var array<string, int>
     */
    private const ATTACHING_SITES = [
        'src/Cli/NonInteractive.php' => 2,
        'src/Sessions/BackgroundSessionRunner.php' => 2,
    ];

    /**
     * Call sites that pass the flag ON from their own parameter, file => count.
     *
     * These decide nothing — they are `Bootstrap` handing its own argument to
     * its own delegate — but they must be classified rather than ignored, or
     * the scanner would have to treat "not a literal" as "not an attachment"
     * and lose the ability to fail on a shape it genuinely cannot read.
     *
     * @var array<string, int>
     */
    private const FORWARDING_SITES = [
        'src/Cli/Bootstrap.php' => 2,
    ];

    public function testOnlyTheHeadlessPathsAttachTheConsolePrompt(): void
    {
        self::assertSame(
            self::ATTACHING_SITES,
            self::census('true'),
            'the set of call sites attaching HeadlessPermissionPrompt moved. If a path that takes the '
                . 'alternate screen now attaches it, the four sugarcrush: shapes in that class need their '
                . 'routing decided again — see its docblock, section "Why none of the four go on the '
                . 'transcript seam". Re-make the decision first, move this roster second.',
        );
    }

    public function testTheForwardingSitesAreStillOnlyBootstrapsOwnDelegation(): void
    {
        self::assertSame(self::FORWARDING_SITES, self::census('forwarded'));
    }

    /**
     * The interactive path takes the `false` default, stated positively.
     *
     * The roster above is an absence for `Bootstrap::chat()` and an absence
     * proves nothing on its own. This reads the parameter's declared default
     * off the reflection of the real method, so "chat() does not pass it"
     * means "chat() gets false" rather than "the scanner did not look there".
     */
    public function testTheInteractivePathDoesNotAttachIt(): void
    {
        foreach (array_keys(self::ENTRY_POINTS) as $entryPoint) {
            $parameters = (new \ReflectionMethod(\SugarCraft\Crush\Cli\Bootstrap::class, $entryPoint))
                ->getParameters();

            $flag = null;
            foreach ($parameters as $parameter) {
                if ($parameter->getName() === 'consolePermissionPrompt') {
                    $flag = $parameter;
                }
            }

            self::assertNotNull($flag, "Bootstrap::{$entryPoint}() no longer has a consolePermissionPrompt "
                . 'parameter; this whole file is answering about something that moved');
            self::assertSame(
                self::ENTRY_POINTS[$entryPoint],
                $flag->getPosition(),
                "Bootstrap::{$entryPoint}()'s consolePermissionPrompt moved position, so the scanner below "
                    . 'is reading a different argument than it thinks',
            );
            self::assertTrue($flag->isDefaultValueAvailable());
            self::assertFalse(
                $flag->getDefaultValue(),
                "Bootstrap::{$entryPoint}() now attaches the console prompt by DEFAULT, which makes the TUI "
                    . 'path attach it — see this file\'s docblock',
            );
        }
    }

    /**
     * THE SCANNER, RUN AGAINST SOURCES WHOSE ANSWER IS KNOWN.
     *
     * Rule 15's shape: both assertions above compare against non-empty maps, so
     * a dead scanner reds them on its own — but only these rows say whether a
     * failure means the SCANNER moved or the TREE did, and they are the only
     * thing that exercises the classifications a real source happens not to
     * contain today.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function scannerCases(): iterable
    {
        yield 'a positional true on backend()' => ['true', '<?php Bootstrap::backend($r, null, null, true);', 1];
        yield 'a positional true on backendFor()' => [
            'true',
            '<?php Bootstrap::backendFor($p, $r, null, null, true);',
            1,
        ];
        yield 'backendFor()\'s fourth argument is the gate, not the flag' => [
            'true',
            '<?php Bootstrap::backendFor($p, $r, null, true);',
            0,
        ];
        yield 'a named argument' => ['true', '<?php Bootstrap::backend($r, consolePermissionPrompt: true);', 1];
        yield 'a named argument set false' => [
            'true',
            '<?php Bootstrap::backend($r, consolePermissionPrompt: false);',
            0,
        ];

        // THE SHAPES THE SEARCH USED TO ABANDON. Each of these puts the flag
        // after some OTHER named argument, which is the arrangement PHP forces
        // as soon as one argument is named — and each returned a silent zero
        // until the search stopped giving up at the first unfamiliar name.
        yield 'the flag named, after two other named arguments' => [
            'true',
            '<?php Bootstrap::backend($r, skills: null, gate: null, consolePermissionPrompt: true);',
            1,
        ];
        yield 'the flag named, with the first argument named too' => [
            'true',
            '<?php Bootstrap::backend(root: $r, consolePermissionPrompt: true);',
            1,
        ];
        yield 'the flag named on backendFor(), after another named argument' => [
            'true',
            '<?php Bootstrap::backendFor($p, root: $r, consolePermissionPrompt: true);',
            1,
        ];
        yield 'a forwarded flag named after another named argument' => [
            'forwarded',
            '<?php self::backend($r, skills: $s, consolePermissionPrompt: $flag);',
            1,
        ];
        yield 'another named argument, and no flag at all' => [
            'true',
            '<?php Bootstrap::backend($r, gate: $g);',
            0,
        ];
        // Not reachable through the two real signatures — their last parameter
        // IS the flag — so this states positively what the positional read does
        // when some other name occupies that slot: answers null, rather than
        // handing classify() a `name: value` triple and throwing.
        yield 'some other named argument occupying the flag\'s slot' => [
            'true',
            '<?php $x->backend($a, $b, $c, somethingElse: $d);',
            0,
        ];
        yield 'an omitted flag' => ['true', '<?php Bootstrap::backend($root);', 0];
        yield 'a forwarded flag' => ['forwarded', '<?php self::backendFor($n, $r, $s, $g, $flag);', 1];
        yield 'a forwarded flag is not a literal true' => [
            'true',
            '<?php self::backendFor($n, $r, $s, $g, $flag);',
            0,
        ];
        yield 'a named forwarded flag' => [
            'forwarded',
            '<?php self::backend($r, consolePermissionPrompt: $flag);',
            1,
        ];
        yield 'a nested call does not shift the positions' => [
            'true',
            '<?php Bootstrap::backendFor(name($a, $b), $r, gate($c, $d), null, true);',
            1,
        ];
        yield 'an interpolated argument does not shift them either' => [
            'true',
            '<?php Bootstrap::backendFor("p{$x}", $r, null, null, true);',
            1,
        ];
        yield 'a prose mention is not a call' => [
            'true',
            "<?php // Bootstrap::backend(\$r, null, null, true) attaches it\n\$x = 1;",
            0,
        ];
        yield 'a doc-block mention is not a call' => [
            'true',
            "<?php /** {@see Bootstrap::backend()} with true */\n\$x = 1;",
            0,
        ];
        yield 'the declaration is not a call' => [
            'true',
            '<?php public static function backend($r = null, $c = false) {}',
            0,
        ];
        yield 'a same-named method on something else is still counted' => [
            'true',
            '<?php $x->backend($r, null, null, true);',
            1,
        ];
        yield 'a nullsafe-dispatched call is counted like the plain one' => [
            'true',
            '<?php $x?->backend($r, null, null, true);',
            1,
        ];
        yield 'nothing at all' => ['true', '<?php echo 1;', 0];
        yield 'nothing at all, forwarded' => ['forwarded', '<?php echo 1;', 0];
    }

    /** @dataProvider scannerCases */
    public function testTheScannerAnswersCorrectlyOnASourceWhoseAnswerIsKnown(
        string $kind,
        string $source,
        int $expected,
    ): void {
        self::assertSame($expected, self::scan($kind, $source));
    }

    /**
     * A flag argument the scanner cannot read is a FAILURE, not a zero.
     *
     * The hole this closes is the one every absence census has: a call passing
     * `$this->wants() ? true : false` is neither a literal nor a forward, and
     * silently dropping it would let a NEW attachment site arrive in a shape the
     * roster never mentions. There is no such site in `src/` today, which is
     * exactly why it needs a fixture.
     */
    public function testTheScannerRedsOnAFlagShapeItCannotRead(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consolePermissionPrompt');

        self::scan('true', '<?php Bootstrap::backend($r, null, null, $a ? true : false);');
    }

    /**
     * ...and named, which is the spelling the search used to walk past.
     *
     * Separate from the positional case above because the two reach
     * {@see classify()} down different arms: one through the name loop, one
     * through the positional read. Before the fail-open was closed this source
     * did not throw at all — it answered zero.
     */
    public function testTheScannerRedsOnANamedFlagShapeItCannotRead(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consolePermissionPrompt');

        self::scan('true', '<?php Bootstrap::backend($r, skills: null, consolePermissionPrompt: $a ? true : false);');
    }

    /**
     * @param 'true'|'forwarded' $kind
     * @return array<string, int> file => count, files with zero omitted
     */
    private static function census(string $kind): array
    {
        $root = \dirname(__DIR__, 2);
        $out = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $n = self::scan($kind, (string) file_get_contents($file->getPathname()));
            if ($n > 0) {
                $out[ltrim(str_replace($root, '', $file->getPathname()), '/')] = $n;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * How many `backend()`/`backendFor()` calls in `$source` pass the console
     * flag as `$kind`.
     *
     * @param 'true'|'forwarded' $kind
     */
    private static function scan(string $kind, string $source): int
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $count = 0;
        foreach ($significant as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $position = self::ENTRY_POINTS[$token[1]] ?? null;
            if ($position === null || ($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            // A scope token is what separates a CALL from a declaration:
            // `function backend(` has T_FUNCTION before the name, and
            // `self::backend(` / `$x->backend(` have the operator.
            // T_NULLSAFE_OBJECT_OPERATOR is `?->`. Both real entry points are
            // static, so it cannot reach THEM — but this scanner deliberately
            // counts `$x->backend(…)` on anything (see the fixture saying so),
            // and the nullsafe spelling of that is the same call. Narrow
            // alphabets are how the sibling censuses in this lane went blind.
            $previous = $significant[$i - 1] ?? null;
            if (
                !\is_array($previous)
                || !\in_array(
                    $previous[0],
                    [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR],
                    true,
                )
            ) {
                continue;
            }

            $flag = self::flagArgument($significant, $i + 1, $position);
            if ($flag === null) {
                continue;
            }

            if ($flag === $kind) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * How the console flag is passed by the call whose `(` sits at `$open`:
     * `'true'`, `'false'`, `'forwarded'`, or null when it is not passed at all.
     *
     * NAMED ARGUMENTS FIRST, because a named one may sit at any position and a
     * positional read would answer about whatever else is there.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant
     */
    private static function flagArgument(array $significant, int $open, int $position): ?string
    {
        $arguments = self::topLevelArguments($significant, $open);

        // EVERY argument is examined before the positional read, and the search
        // does not stop at the first name it does not recognise. WHAT THIS DID:
        // meeting any named argument at or before $position, it returned null —
        // "the flag is not passed" — without ever looking at the slots after it.
        // WHY THAT WAS WRONG: PHP requires every argument following a named one
        // to be named too, so `backend($r, skills: null, gate: null,
        // consolePermissionPrompt: true)` puts the flag in exactly the place
        // that early return refused to read. The guard failed OPEN on the one
        // event it exists to catch, and `src/` writes named arguments freely —
        // `Bootstrap::chat()`'s own `new Chat(…)` is named throughout.
        foreach ($arguments as $argument) {
            if (self::argumentName($argument) === 'consolePermissionPrompt') {
                return self::classify(\array_slice($argument, 2));
            }
        }

        $positional = $arguments[$position] ?? null;
        if ($positional === null) {
            return null;
        }

        // A NAMED argument sitting in the flag's positional slot is not the
        // flag: the loop above has already established it is not passed by
        // name, so it is not passed at all. Reading this slot positionally
        // would hand classify() a `name: value` triple and throw on a call
        // that is simply using the parameter's default.
        if (self::argumentName($positional) !== null) {
            return null;
        }

        return self::classify($positional);
    }

    /**
     * The parameter name this argument is passed under, or null when it is
     * positional.
     *
     * A named argument is `T_STRING ':' …`, and nothing else in an argument
     * position lexes that way — `COND ?: $x` puts a `'?'` between the two, and
     * `COND ? $a : $b` starts with the condition rather than with the colon's
     * left neighbour. Checked on PHP 8.3.6, the only version on this box.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     */
    private static function argumentName(array $argument): ?string
    {
        if (\count($argument) < 3 || !\is_array($argument[0])) {
            return null;
        }

        return $argument[0][0] === T_STRING && $argument[1] === ':' ? $argument[0][1] : null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens one argument
     */
    private static function classify(array $tokens): string
    {
        if (\count($tokens) === 1 && \is_array($tokens[0])) {
            if ($tokens[0][0] === T_VARIABLE) {
                return 'forwarded';
            }
            if ($tokens[0][0] === T_STRING && strtolower($tokens[0][1]) === 'true') {
                return 'true';
            }
            if ($tokens[0][0] === T_STRING && strtolower($tokens[0][1]) === 'false') {
                return 'false';
            }
        }

        $rendered = implode('', array_map(
            static fn (array|string $token): string => \is_array($token) ? $token[1] : $token,
            $tokens,
        ));

        throw new \RuntimeException(
            "a call passes consolePermissionPrompt as `{$rendered}`, which is neither a literal nor a "
                . 'forwarded parameter. Classify it — a shape this scanner drops is an attachment site the '
                . 'roster would never mention, which is the hole this census exists to not have.',
        );
    }

    /**
     * The call's top-level arguments, each as its own token list.
     *
     * THE ARRAY-TOKEN OPENERS ARE PART OF THE DEPTH WALK, and they are here
     * because the sibling stderr census shipped this same walk WITHOUT them and
     * mis-counted (E161): PHP 8.3.6 lexes `#[`, and the `{`/`${` of `"{$a}"`
     * and `"${a}"`, as ARRAY tokens whose closers it lexes as plain one-byte
     * strings — so a walk counting only `( [ {` sees a close with no open,
     * reaches a spurious depth 0 and stops early. An interpolated first
     * argument is an everyday shape, and `Bootstrap::backendFor("p{$x}", …)` is
     * exactly the call this file reads.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private static function topLevelArguments(array $significant, int $open): array
    {
        $arrayTokenOpeners = [T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];
        $depth = 0;
        $arguments = [];
        $current = [];

        for ($i = $open; $i < \count($significant); $i++) {
            $token = $significant[$i];

            if (\is_array($token) && \in_array($token[0], $arrayTokenOpeners, true)) {
                $depth++;
                $current[] = $token;

                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;
                if ($depth > 1) {
                    $current[] = $token;
                }

                continue;
            }
            if (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    if ($token !== ')') {
                        throw new \RuntimeException(
                            "the argument list opened at token {$open} balances to zero on '{$token}' rather "
                                . "than on ')': it carries a bracket opener lexed as an array token this walk "
                                . 'does not know.',
                        );
                    }
                    if ($current !== []) {
                        $arguments[] = $current;
                    }

                    return $arguments;
                }
                $current[] = $token;

                continue;
            }
            if ($depth === 1 && $token === ',') {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        throw new \RuntimeException('a call opened at this token never closes; the scan cannot answer for it');
    }
}
