<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Static TTY detection for the whole SugarCraft tree.
 *
 * Provides a single call-site for every lib that needs "is this stream a
 * TTY?" without taking a direct candy-pty dependency.
 *
 * WHAT THE CONSUMER LIST USED TO SAY, AND WHY IT CHANGED.  It read
 * "(candy-mosaic, sugar-bits, sugar-prompt, candy-log, …) all route
 * through here, so the answer is defined in exactly one place".  Both
 * halves are wrong.  The list had been carried forward from an older
 * revision without being re-measured, while the sentence around it was
 * strengthened to "all".
 *
 * MEASURED by grepping this class name across every lib's `src/`: the
 * consumers outside candy-core are candy-mosaic
 * ({@see \SugarCraft\Mosaic\Detect::isInteractiveTty()}), sugar-glow
 * ({@see \SugarCraft\Glow\RenderCommand::loadInput()}) and sugar-prompt
 * ({@see \SugarCraft\Prompt\Spinner::run()}).  `sugar-bits` and
 * `candy-log` do not reference this class at all, and sugar-glow — a
 * real consumer — was missing from the list entirely.  Re-derive that
 * set with a grep rather than trusting this paragraph: it is a snapshot,
 * and the previous one rotted silently precisely because it read as a
 * standing fact.
 *
 * AND THE ANSWER IS NOT "DEFINED IN EXACTLY ONE PLACE".  Inside
 * candy-core, {@see ColorProfile::detect()},
 * {@see Tty\PosixBackend::isTty()}, {@see Tty\WindowsBackend::isTty()}
 * and {@see Tty\EnvDetect::isConsoleStdin()} each call `stream_isatty()`
 * directly.  Every one of them guards with `is_resource()` first, so
 * none is a defect — but this class is the INTENDED entry point for a
 * caller that already holds a stream, not the sole implementation of the
 * question, and a reader who believes the stronger claim will never go
 * looking for them.
 *
 * ## WHAT THIS DOC-BLOCK USED TO SAY, AND WHY IT CHANGED
 *
 * WHAT IT SAID: "Static TTY-detection helpers that delegate to candy-pty
 * … wraps `TermiosFactory::open($fd)->isAtty()` so that the fd→Termios→
 * isAtty dance is centralized in one place."
 *
 * WHAT IS TRUE NOW: there is no fd→Termios dance here, because THE DANCE
 * WAS THE BUG.  `isAtty()` obtained its `$fd` with `(int) $stream`, and an
 * `(int)` cast of a PHP stream yields its RESOURCE ID — a completely
 * different number from the file descriptor, which it coincides with only
 * by accident of allocation order.  MEASURED, PHP 8.3.6, fresh CLI
 * process, three takes: the resource ids of the three standard streams are
 * 1, 2 and 3 while the descriptors behind them are 0, 1 and 2 — every one
 * of the three off by one, and none of them lining up.  The answers had
 * nevertheless been right whenever all three named the same terminal,
 * because asking about descriptor 1 when you meant descriptor 0 then
 * returns the same verdict; that is why nothing in the tree was visibly
 * wrong, and it is also why the defect survived.  It stops being an
 * accident that works the moment a process opens streams before asking:
 * MEASURED on the same box, a handle that really is descriptor 0
 * (`/proc/self/fd/0` reads back its target) reports `5` under the cast.
 *
 * WHY THE DELEGATION DOES NOT EARN ITS PLACE BACK: `stream_isatty()` is
 * `isatty(fileno($stream))` — it asks about the STREAM, so no descriptor
 * number has to be derived, and there is no portable userland call that
 * would derive one correctly for an arbitrary stream.  Routing through it
 * loses nothing measurable: the two candy-pty implementations of this
 * predicate — {@see \SugarCraft\Pty\Posix\PosixTermios::isAtty()} and
 * {@see \SugarCraft\Pty\Posix\SttyTermios::isAtty()} — cannot disagree.
 * (Neither is "the same one line", as an earlier revision of this
 * paragraph claimed: each is a three-line body wrapping
 * `posix_isatty($this->fd)` in a `function_exists('posix_isatty')` gate,
 * one early-returning false and the other inverting the branch.  That
 * textual claim was simply wrong.  The load-bearing one — that the two
 * are semantically identical — is right, and it is what the rest of this
 * paragraph rests on.)  So the `SUGARCRAFT_TERMIOS=stty` seam that
 * {@see \SugarCraft\Pty\TermiosFactory} exists to offer was selecting
 * between two bodies that cannot give different answers here; and both
 * answer `false` outright when ext-posix is absent, where
 * `stream_isatty()` still answers.
 *
 * ## WHAT THIS DID TO candy-pty'S REACHABILITY — CORRECTED
 *
 * An earlier revision of this block said candy-pty "is still reached
 * from {@see RawMode} and {@see Tty\PosixBackend}".  HALF OF THAT WAS
 * INVERTED, and it was inverted in the direction that flattered the
 * change.
 *
 * {@see Tty\PosixBackend} is right, and candy-pty remains a genuine
 * candy-core dependency because of it: that class imports
 * `Contract\Termios`, `SizeIoctl` and `TermiosFactory`, and calls them.
 *
 * {@see RawMode} is WRONG.  MEASURED with `token_get_all()` over its
 * source with `T_COMMENT` and `T_DOC_COMMENT` dropped: it contains ZERO
 * candy-pty tokens in executable code.  Its only mention of the package
 * is an `@see` tag contrasting the two approaches, its own class
 * doc-block gives "don't want the candy-pty dependency" as its REASON TO
 * EXIST, and its two methods call {@see isAtty()} and `stty` via
 * `shell_exec()` and nothing else.  This predicate WAS RawMode's only
 * route into candy-pty, so the change above SEVERED it.  RawMode is now
 * the strongest evidence in the tree that candy-pty is LESS reached, and
 * the old sentence cited it as evidence of the opposite.
 *
 * ## THE CLOSED-DESCRIPTOR-0 FAMILY — read this before touching a member
 *
 * candy-core and candy-mosaic were written when descriptor 0 was always a
 * live terminal.  It is not any more.  `sugar-crush`'s `tests/bootstrap.php`
 * closes the standard input constant on every non-tty run, and a daemon, a
 * git hook, or a shell invocation that detaches its own input reaches the
 * same state in production.  The trap is that `defined('STDIN')` STAYS TRUE
 * after the handle is closed while `is_resource()` goes false — so a
 * `?? \STDIN` still reads like a fallback and is not one, and a guard whose
 * fallback is that constant is guarding with the very thing it doubted.
 *
 * MEASURED, PHP 8.3.6, each against a closed standard-input handle:
 *
 *   - `stream_isatty()`  throws `TypeError: … not a valid stream resource`
 *   - `proc_open()`      throws the same `TypeError` for a descriptor-array
 *                        entry holding that handle
 *   - `stream_select()`  drops the handle from the read array and then
 *                        throws `ValueError: No stream arrays were passed`
 *
 * and the `@` operator suppresses NONE of them, because all three are
 * thrown rather than raised.  A silent-degradation reading of any of these
 * three call shapes is wrong.
 *
 * The members, and the shape each one uses now:
 *
 *   - {@see isAtty()} — `is_resource()` first, then `stream_isatty()`.
 *   - {@see Tty\EnvDetect::isConsoleStdin()} — the same guard, added so
 *     that wiring the dormant Windows console probe later cannot
 *     reintroduce the throw.
 *   - `SugarCraft\Core\Program::runExec()` — resolves each child
 *     descriptor to the program's own handle, then the constant, then a
 *     `/dev/null` file spec; never to a constant alone.
 *   - `SugarCraft\Mosaic\Detect::stdinFd()` — answers null for a dead
 *     handle, and its readers treat null as their existing no-answer case.
 *
 * ONE guard pins the family rather than four pinning symptoms:
 * {@see \SugarCraft\Core\Tests\Util\ClosedDescriptorZeroFamilyTest} drives
 * every member above inside a child process that has closed its own
 * descriptor 0, and carries a probe that is MEANT to throw, so that a run
 * in which nothing was exercised cannot be mistaken for a run in which
 * nothing threw.
 */
final class TtyDetect
{
    private function __construct()
    {
    }

    /**
     * True when the given stream refers to a terminal device.
     *
     * Returns false for anything that is not a live stream (null, a closed
     * handle, a non-resource) rather than throwing, so callers can use it
     * in guard clauses without wrapping in try/catch.
     *
     * `resource|null` is the real contract across a package boundary, not
     * defensive padding: `SugarCraft\Mosaic\Detect::isInteractiveTty()`
     * calls this as `TtyDetect::isAtty(self::stdinFd())`, and `stdinFd()`
     * answers null when descriptor 0 is dead.  The `is_resource()` guard
     * below already gave that call the right answer — false, which is the
     * same answer the old closed-handle argument produced — so nothing
     * about the behaviour changed; the `@param` was simply narrower than
     * the call site.
     *
     * @param resource|null $stream standard input/output/error or equivalent
     */
    public static function isAtty($stream): bool
    {
        if (!\is_resource($stream)) {
            return false;
        }

        // Deliberately NOT an `(int)` cast plus a descriptor lookup: that
        // cast yields the resource id, which is not the file descriptor.
        // The class doc-block carries the measurement.
        return \stream_isatty($stream);
    }
}
