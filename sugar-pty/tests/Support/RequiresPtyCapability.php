<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\SignalForwarder;

/**
 * The ONE canonical capability-gate family for this lib's suites.
 *
 * Before this trait existed, ~57 test files hand-copied a private
 * requirePtySyscalls() helper, and the copies had silently drifted into
 * four dialects (some missing the /dev/ptmx leg, five gates checking the
 * bare `extension_loaded('ffi')` bit — which stays TRUE under
 * ffi.enable=0, so those suites ERRORed on hosts where every sibling
 * correctly SKIPped). Adding a capability leg meant editing 57 files and
 * hoping they stayed in sync; they did not.
 *
 * Use this trait and call one of:
 *  - requireFfiOnlyCapability()  POSIX host + working ext-ffi; for tests
 *    that poke libc through FFI without ever opening a PTY.
 *  - requirePtySyscalls()        the full base gate: POSIX + working FFI
 *    + usable /dev/ptmx. This is what nearly every suite wants.
 *  - requirePcntl()              ext-pcntl actually functional
 *    (SignalForwarder::pcntlReady()) plus SIGWINCH defined, for the
 *    signal-forwarding suites.
 *
 * Tests needing a leg beyond the base (a specific /bin/bash, the
 * extension-loaded-but-forking message per child program, …) call
 * requireBasePtyCapability() first and keep ONLY their extra arms.
 */
trait RequiresPtyCapability
{
    /**
     * OS gate + working ext-ffi, WITHOUT the /dev/ptmx leg. Skip reason
     * spells the OS and ext-ffi arms separately so a triaged run still
     * says which leg broke.
     */
    protected function requireFfiOnlyCapability(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!PtyCapability::ffiUsable()) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
    }

    /**
     * The full base gate: POSIX host, working ext-ffi (not merely the
     * loaded bit — see {@see PtyCapability}), usable /dev/ptmx.
     */
    protected function requireBasePtyCapability(): void
    {
        $this->requireFfiOnlyCapability();
        if (!PtyCapability::devPtmxUsable()) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    /** Canonical name most call sites already use: the base gate. */
    protected function requirePtySyscalls(): void
    {
        $this->requireBasePtyCapability();
    }

    /**
     * ext-pcntl functional (pcntl_signal + pcntl_signal_dispatch really
     * exist, not just the extension bit) plus SIGWINCH defined.
     */
    protected function requirePcntl(): void
    {
        /** @var TestCase $this */
        if (!SignalForwarder::pcntlReady()) {
            $this->markTestSkipped('ext-pcntl is required for SignalForwarder.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH is not defined on this host.');
        }
    }
}
