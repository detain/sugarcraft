<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\Contract\PtySystem;

/**
 * The Windows {@see PtySystem}: pseudo-console plumbing over the
 * {@see ConPtyApi} primitives.
 *
 * This is the adapter layer the interface file promised: it turns
 * CreatePseudoConsole + the two anon pipes into a master/slave pair that
 * satisfies the same contract the POSIX implementation does, so call sites
 * written against {@see \SugarCraft\Pty\PtySystemFactory} never branch on
 * the host OS.
 *
 * ## Construction is dependency-free; the binding loads at open()
 *
 * The constructor takes an optional {@see ConPtyApi} only — with none
 * injected, the host's real binding ({@see Kernel32ConPty::self()}) is
 * resolved lazily inside {@see open()}. That ordering is a law, not a
 * nicety: {@see \SugarCraft\Pty\PtySystemFactory::forPlatform()} returns a
 * fresh instance for the platform string "Windows" on ANY host, and the
 * availability refusal (Windows 10 1809 floor, PHP 8.4 floor, ext-ffi) must
 * surface when the caller actually opens a console, naming the reason.
 * Constructing a backend must not be the thing that fails.
 *
 * ## What ConPTY is not
 *
 * {@see capabilities()} reports the gaps the pseudo console cannot paper
 * over: there is no controlling terminal (no TIOCSCTTY concept), no signal
 * delivery semantics (a child is killed wholesale, see
 * {@see ConPtyChild::kill()}), and no file descriptor
 * ({@see ConPtyMasterPty::fd()} throws). Callers that need those stay on
 * the POSIX implementation.
 *
 * Mirrors charmbracelet/x/xpty's conpty-backed PtySystem on Windows
 * (creack/pty StartWithSize parity arrives through the slave's spawn).
 *
 * @see ConPtyApi — the primitives this adapts
 * @see portable-pty.conpty::ConPty — the Rust design followed here
 */
final class ConPtySystem implements PtySystem
{
    /**
     * Null until {@see open()} resolves the host's real binding; a
     * non-null value is an injected fake, which is how the adapter's
     * control flow is pinned on non-Windows hosts.
     */
    private ?ConPtyApi $api;

    public function __construct(?ConPtyApi $api = null)
    {
        $this->api = $api;
    }

    /**
     * Open a pseudo console of the requested size.
     *
     * The pipe/pseudo-console bracket is deliberately explicit: any step
     * that fails after a pipe exists closes what got that far, because a
     * backend that forgets leaks one handle pair per attempt (the law the
     * primitives' docblocks already state — the adapter is where it is
     * actually kept).
     */
    public function open(int $cols = 80, int $rows = 24): ConPtyPair
    {
        $api = $this->api ?? Kernel32ConPty::self();

        $input = $api->createPipe();
        try {
            $output = $api->createPipe();
        } catch (\Throwable $e) {
            self::closePipe($api, $input);

            throw $e;
        }

        try {
            // The pseudo console takes the WRITE end of the parent→child
            // pipe and the READ end of the child→parent pipe; the master
            // keeps the other ends. closePseudoConsole does NOT close the
            // pipes, so ownership of all four handles moves to the master.
            $pc = $api->createPseudoConsole($cols, $rows, $input['write'], $output['read']);
        } catch (\Throwable $e) {
            self::closePipe($api, $input);
            self::closePipe($api, $output);

            throw $e;
        }

        return new ConPtyPair(new ConPtyMasterPty($api, $pc, $input, $output, $cols, $rows));
    }

    /**
     * The honest capability map: true is limited to what this pair can
     * actually do, so feature-detection at the call site is meaningful.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            'conpty' => true,
            'controllingTerminal' => false,
            'signals' => false,
            'fd' => false,
        ];
    }

    /**
     * @param array{read: int, write: int} $pipe
     */
    private static function closePipe(ConPtyApi $api, array $pipe): void
    {
        $api->closeHandle($pipe['read']);
        $api->closeHandle($pipe['write']);
    }
}
