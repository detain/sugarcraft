<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\ObservesReasoning;

/**
 * **E527 — the mechanism that stops {@see Backend} growing a parameter, MEASURED
 * rather than asserted in prose.**
 *
 * `src/Backend.php` and {@see ObservesReasoning} both turn on one claim about
 * PHP: that widening an interface method is a LOAD-TIME FATAL for every
 * existing implementation that declares the narrower signature, optional extra
 * parameter or not. That claim is the entire reason reasoning arrived as a
 * capability interface instead of a fifth parameter, and until this file
 * existed it was a sentence in two doc-blocks with nothing behind it. Rule 46:
 * prose claiming a mechanism is not the mechanism.
 *
 * ## The claim is easy to get backwards, and it was got backwards
 *
 * The round that commissioned this test stated the rule INVERTED — "PHP lets an
 * implementation declare fewer parameters than its interface, so a new one
 * added to the interface is silently absent in every implementation that does
 * not take it". MEASURED on PHP 8.3.6, that is false in both halves, and the
 * two directions are not symmetric:
 *
 *   - FEWER parameters than the interface -> `Declaration of X::m() must be
 *     compatible with I::m()`, a fatal at load time. Not a warning, not a
 *     deprecation, and not deferred to the call.
 *   - MORE parameters than the interface, all of them optional -> accepted.
 *
 * The silent-surplus behaviour the inverted claim describes is real, but it
 * belongs to the CALL and not to the declaration: PHP drops surplus positional
 * arguments handed to a userland method without a murmur, which is why
 * {@see ObservesReasoning} redeclares `completeAsync()` instead of being a bare
 * marker. Both halves are pinned below, because a guard that only showed the
 * fatal would leave the reader free to keep the wrong model of the other side.
 *
 * ## Why a subprocess
 *
 * An incompatible declaration is a fatal error raised by the COMPILER. It is
 * not a `\Throwable`, `eval()` cannot contain it, and a `set_error_handler()`
 * does not see it — so a same-process test could only prove it by dying. Each
 * case is therefore compiled by a fresh interpreter, and the verdict is that
 * interpreter's exit status. `PHP_BINARY` and never `php` off `PATH`: the two
 * have been different builds on this box's CI before, and a probe compiled by
 * an interpreter other than the one running the suite answers about the wrong
 * PHP (round 44's child-process census got exactly this wrong).
 *
 * ## Scope of the version claim
 *
 * Everything here is measured on the interpreter running the suite and reports
 * its own version in every failure message. CI also runs 8.4, which this box
 * does not have; nothing below is a claim about 8.4 beyond the fact that the
 * assertions are written to re-measure rather than to restate, so the 8.4 leg
 * answers for itself.
 */
final class BackendContractWideningTest extends TestCase
{
    /**
     * Sentinel the probe prints once it has compiled AND executed. Checked
     * rather than trusting exit status alone: a probe that fatals before
     * reaching the echo also exits non-zero, and a probe whose file failed to
     * write would exit non-zero too. The sentinel separates "loaded" from
     * "never got that far" (rule 25 — an exit code of 0 is also what an empty
     * file returns).
     */
    private const LOADED = 'PROBE-LOADED-OK';

    /**
     * Ungated (rule 37): this test's whole instrument is the subprocess, so a
     * host without `proc_open` must go RED rather than skip quietly into a
     * green suite that measured nothing.
     */
    public function testTheProbeMechanismIsAvailableAtAll(): void
    {
        $this->assertTrue(
            \function_exists('proc_open'),
            'every assertion in this file compiles a probe in a subprocess; without proc_open '
                . 'they would all be vacuous, so this reds instead of skipping',
        );

        // The harness itself, on a case whose answer is known before it is run
        // (rule 13): a probe that does nothing but print the sentinel must come
        // back rc 0 with the sentinel, or nothing below can be believed.
        [$rc, $output] = $this->compileInFreshInterpreter('');
        $this->assertSame(0, $rc, "the empty control probe did not load: {$output}");
        $this->assertStringContainsString(self::LOADED, $output);
    }

    /**
     * The half the two doc-blocks rest on, on a TOY interface first so the
     * failure text names the mechanism and not this package.
     */
    public function testAnImplementationWithFEWERParametersThanItsInterfaceIsALoadTimeFatal(): void
    {
        [$rc, $output] = $this->compileInFreshInterpreter(<<<'PROBE'
            interface WideningProbe { public function m(int $a, ?callable $b = null): void; }
            final class NarrowImpl implements WideningProbe { public function m(int $a): void {} }
            PROBE);

        $this->assertNotSame(
            0,
            $rc,
            'PHP ' . PHP_VERSION . ' ACCEPTED an implementation with fewer parameters than its '
                . 'interface. If that is really true on this interpreter, then Backend COULD have '
                . 'grown a fifth parameter and ObservesReasoning is unnecessary — but check this '
                . "probe before rewriting either docblock. Probe output:\n{$output}",
        );
        $this->assertStringNotContainsString(
            self::LOADED,
            $output,
            'the narrow implementation ran, so whatever the exit status was, it was not this',
        );
        $this->assertStringContainsString(
            'must be compatible with',
            $output,
            'the probe failed for some reason OTHER than signature incompatibility, so it is '
                . 'measuring something else. PHP ' . PHP_VERSION . " said:\n{$output}",
        );
    }

    /**
     * The other polarity (rule 33). Without this, an interpreter that refused
     * to compile ANYTHING would satisfy the test above.
     */
    public function testAnImplementationWithMOREOptionalParametersThanItsInterfaceLoadsFine(): void
    {
        [$rc, $output] = $this->compileInFreshInterpreter(<<<'PROBE'
            interface WideningProbe { public function m(int $a): void; }
            final class WideImpl implements WideningProbe { public function m(int $a, ?callable $b = null): void {} }
            PROBE);

        $this->assertSame(
            0,
            $rc,
            'PHP ' . PHP_VERSION . " refused a widening that adds only OPTIONAL parameters. The "
                . "asymmetry is the whole argument; if it has gone, both docblocks need rereading:\n{$output}",
        );
        $this->assertStringContainsString(self::LOADED, $output);
    }

    /**
     * THE SAME MECHANISM ON THE REAL INTERFACES, which is what actually earns
     * the capability-interface design its place. The two toy cases above prove
     * the language rule; this one proves that {@see ObservesReasoning}'s
     * redeclaration makes the capability a STRUCTURAL fact rather than a
     * promise — a backend that claims to observe reasoning while declaring the
     * four-parameter `completeAsync()` cannot even be loaded.
     */
    public function testAFourParameterBackendCLAIMINGToObserveReasoningCannotLoad(): void
    {
        [$rc, $output] = $this->compileInFreshInterpreter(<<<'PROBE'
            final class LiarBackend implements \SugarCraft\Crush\Backend\ObservesReasoning
            {
                public function complete(array $h, ?callable $t = null, ?callable $e = null, ?callable $r = null): \SugarCraft\Crush\Message
                { throw new \LogicException('unreached'); }
                public function completeAsync(array $h, ?callable $t = null, ?\SugarCraft\Crush\Backend\CancellationToken $c = null, ?callable $e = null): \React\Promise\PromiseInterface
                { throw new \LogicException('unreached'); }
            }
            PROBE, autoload: true);

        $this->assertNotSame(
            0,
            $rc,
            'a backend declaring the FOUR-parameter completeAsync() while implementing '
                . 'ObservesReasoning loaded on PHP ' . PHP_VERSION . '. That interface is a '
                . 'redeclaration precisely so this cannot happen; if it can, the reasoning sink '
                . "would be dropped on the floor with no diagnostic. Probe output:\n{$output}",
        );
        $this->assertStringContainsString('must be compatible with', $output, $output);
    }

    /**
     * And the control for it: the same class body against plain
     * {@see Backend} loads, so the failure above is about the FIFTH parameter
     * and not about the probe being malformed. This is also the fact that makes
     * widening `Backend` unthinkable — four parameters is the shipped norm.
     */
    public function testTheSameFourParameterBodyLoadsFineAgainstBackendItself(): void
    {
        [$rc, $output] = $this->compileInFreshInterpreter(<<<'PROBE'
            final class HonestBackend implements \SugarCraft\Crush\Backend
            {
                public function complete(array $h, ?callable $t = null, ?callable $e = null): \SugarCraft\Crush\Message
                { throw new \LogicException('unreached'); }
                public function completeAsync(array $h, ?callable $t = null, ?\SugarCraft\Crush\Backend\CancellationToken $c = null, ?callable $e = null): \React\Promise\PromiseInterface
                { throw new \LogicException('unreached'); }
            }
            PROBE, autoload: true);

        $this->assertSame(0, $rc, "the four-parameter control did not load against Backend:\n{$output}");
        $this->assertStringContainsString(self::LOADED, $output);
    }

    /**
     * The arithmetic the design rests on, DERIVED from the interfaces rather
     * than stated (rules 18/46): `ObservesReasoning` widens `completeAsync()`
     * by exactly one parameter, and that parameter is optional. If either ever
     * stops being true the argument above stops applying to this package even
     * though the language rule it cites is unchanged.
     */
    public function testObservesReasoningWidensCompleteAsyncByExactlyOneOptionalParameter(): void
    {
        $base = new \ReflectionMethod(Backend::class, 'completeAsync');
        $wide = new \ReflectionMethod(ObservesReasoning::class, 'completeAsync');

        $this->assertSame(
            $base->getNumberOfParameters() + 1,
            $wide->getNumberOfParameters(),
            'ObservesReasoning no longer widens completeAsync() by exactly one parameter',
        );

        $extra = $wide->getParameters()[$wide->getNumberOfParameters() - 1];
        $this->assertSame('onReasoning', $extra->getName());
        $this->assertTrue($extra->isOptional(), 'the widening parameter is not optional');
        $this->assertSame(
            0,
            $base->getNumberOfRequiredParameters() - 1,
            'Backend::completeAsync() no longer takes exactly one required parameter, so the '
                . 'shape both docblocks describe has moved',
        );
    }

    /**
     * `ObservesReasoning`'s docblock deliberately declines to write down HOW
     * MANY four-parameter implementations exist ("it moves whenever anyone adds
     * a double"), and says what it actually needs is that the number is not
     * zero. That is the half worth pinning, so it is derived here instead of
     * counted in prose — rule 18, and the exact defect
     * `Context/RepoMapBlock` has paid for twice.
     *
     * Scope: `src/Backend/` only. The doubles under `tests/` are the larger
     * population and are another lane's files this round; a walk that reached
     * them would make this guard red for edits it has no view of.
     */
    public function testAtLeastOneSHIPPEDBackendDeclaresTheNarrowFourParameterForm(): void
    {
        $narrow = [];
        $wide = [];
        foreach ((array) glob(\dirname(__DIR__, 2) . '/src/Backend/*.php') as $file) {
            $class = 'SugarCraft\\Crush\\Backend\\' . basename((string) $file, '.php');
            if (!class_exists($class) || !is_a($class, Backend::class, true)) {
                continue;
            }
            $count = (new \ReflectionMethod($class, 'completeAsync'))->getNumberOfParameters();
            ($count === 4 ? $narrow : $wide)[] = $class . " ({$count})";
        }

        $this->assertNotSame(
            [],
            $narrow,
            'not one backend shipped in src/Backend/ declares the four-parameter completeAsync(). '
                . 'That is the population a fifth parameter on Backend would fatal, and the '
                . 'docblock argument needs it to be non-empty. Wide ones seen: '
                . implode(', ', $wide),
        );
        // The positive half again: the walk must also SEE the five-parameter
        // one, or "some are narrow" is a statement about a walk that found
        // nothing else (rule 15).
        $this->assertNotSame(
            [],
            $wide,
            'the walk found no five-parameter backend either, so it is probably not walking: '
                . implode(', ', $narrow),
        );
    }

    /**
     * Compile `$body` in a fresh interpreter and report `[exitStatus, output]`.
     *
     * The probe file is written under the system temp dir with a name unique to
     * this process and unlinked by EXACT PATH in a `finally`, never by glob —
     * sibling suites run concurrently in the same directory and own files of
     * their own there.
     */
    private function compileInFreshInterpreter(string $body, bool $autoload = false): array
    {
        $bootstrap = $autoload
            ? "require " . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            : '';
        $source = "<?php\n{$bootstrap}{$body}\necho " . var_export(self::LOADED, true) . ";\n";

        $path = sys_get_temp_dir() . '/sc_r58a_widen_' . getmypid() . '_' . bin2hex(random_bytes(8)) . '.php';
        $this->assertNotFalse(file_put_contents($path, $source), "could not write probe to {$path}");

        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', $path],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process, 'proc_open refused to start the probe interpreter');

            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $output];
        } finally {
            @unlink($path);
        }
    }
}
