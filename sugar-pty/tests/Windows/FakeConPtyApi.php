<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Windows\ConPtyApi;

/**
 * A test double for {@see ConPtyApi} that runs on a machine with no kernel32.
 *
 * SUPPORT CLASS, NOT A TEST — it carries no `Test` suffix on purpose: it holds no
 * assertions, and naming it `FakeConPtyApiTest` would put it in the test suite and
 * in the shard plan for a file with zero tests in it.
 *
 * ## Why the double is the point of the interface
 *
 * The ConPTY surface is twelve-ish Win32 calls whose interesting behaviours are
 * all about ORDERING and OWNERSHIP: a pseudo-console closed before its pipes, an
 * attribute list deleted while `CreateProcessW` still holds it, a job never
 * assigned so the tree outlives the session. None of those are expressible as
 * "does this call return the right bytes", and all of them are expressible as a
 * transcript — which is what this class produces. It is the reason
 * {@see ConPtyApi} exists as an interface rather than as a class whose FFI calls
 * are simply unreachable off-Windows.
 *
 * ## What it models, and what it refuses to
 *
 * It models handle lifetimes: every handle and id it issues is tracked, and the
 * close/delete calls fail loudly on an id that was never issued or already
 * retired. That is the failure a real host answers with a use-after-free or a
 * silent `ERROR_INVALID_HANDLE`, so the double answers it with an exception
 * instead — a test that closes something twice should not be green.
 *
 * It does NOT model byte-level pipe semantics. {@see readFile()} returns whatever
 * the test queued, because the interesting question off-Windows is never whether
 * kernel32 copies bytes.
 *
 * @internal
 */
final class FakeConPtyApi implements ConPtyApi
{
    /**
     * Everything this double did, in call order.
     *
     * @var list<string>
     */
    public array $transcript = [];

    /**
     * Bytes handed back by {@see readFile()}, consumed front-first.
     *
     * @var list<string>
     */
    public array $readQueue = [];

    /** Live pseudo-console handles. */
    private array $pseudoConsoles = [];

    /** Live pipe handles, both ends. */
    private array $pipes = [];

    /** Live attribute-list ids. */
    private array $attributeLists = [];

    /** Live job ids => process handles assigned to them. */
    private array $jobs = [];

    /** Live process handles. */
    private array $processes = [];

    /** Exit codes per process handle. */
    private array $exitCodes = [];

    /** The next handle/id this double will issue. */
    private int $next = 100;

    /**
     * A scope-only `\FFI` object, so the return type is honestly satisfied.
     *
     * A declaration block with no function prototypes needs no library and
     * resolves nothing — the same trick `Kernel32ConPtyTest` uses to parse types
     * off-Windows. Returning null would be a lie about the signature, and
     * throwing would make any caller of `ffi()` untestable.
     */
    public function ffi(): \FFI
    {
        return \FFI::cdef('typedef int sugarPtyFakeMarker;');
    }

    public static function self(): ConPtyApi
    {
        return new self();
    }

    public static function supported(): ?string
    {
        // The double is always "available" — that is the whole reason the probe
        // is a method on the contract instead of a hard-coded platform check at
        // every call site.
        return null;
    }

    // ─── Test-side controls ─────────────────────────────────────────────────

    /**
     * Seed an exit code a later {@see getExitCodeProcess()} will report.
     */
    public function setExitCode(int $process, int $code): void
    {
        $this->exitCodes[$process] = $code;
    }

    /**
     * True when nothing this double issued is still open.
     */
    public function everythingIsClosed(): bool
    {
        return $this->pseudoConsoles === []
            && $this->pipes === []
            && $this->attributeLists === []
            && $this->jobs === []
            && $this->processes === [];
    }

    /**
     * @return list<string>
     */
    public function callsMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->transcript,
            static fn (string $line): bool => \str_contains($line, $needle),
        ));
    }

    // ─── ConPtyApi ──────────────────────────────────────────────────────────

    public function createPseudoConsole(int $width, int $height, int $input, int $output): int
    {
        $this->guardLive($input, 'pipe');
        $this->guardLive($output, 'pipe');
        $handle = $this->issue('createPseudoConsole', \sprintf('%dx%d', $width, $height));
        $this->pseudoConsoles[$handle] = ['width' => $width, 'height' => $height];

        return $handle;
    }

    public function resizePseudoConsole(int $pseudoConsole, int $width, int $height): void
    {
        $this->guard($this->pseudoConsoles, $pseudoConsole, 'pseudo-console', 'resizePseudoConsole');
        $this->pseudoConsoles[$pseudoConsole] = ['width' => $width, 'height' => $height];
    }

    public function closePseudoConsole(int $pseudoConsole): void
    {
        $this->guard($this->pseudoConsoles, $pseudoConsole, 'pseudo-console', 'closePseudoConsole');
        unset($this->pseudoConsoles[$pseudoConsole]);
    }

    public function createPipe(int $bufferSize = 0): array
    {
        $read = $this->issue('createPipe', \sprintf('size=%d', $bufferSize) . ' read');
        $write = $this->issue('createPipe', \sprintf('size=%d', $bufferSize) . ' write');
        $this->pipes[$read] = true;
        $this->pipes[$write] = true;

        return ['read' => $read, 'write' => $write];
    }

    public function peekNamedPipe(int $handle): ?array
    {
        $this->transcript[] = \sprintf('peekNamedPipe(%d)', $handle);

        if (!isset($this->pipes[$handle])) {
            return null; // Broken pipe: the model's only "gone" signal.
        }

        $next = $this->readQueue[0] ?? null;

        return [
            'available' => $next === null ? 0 : \strlen($next),
            'leftThisMessage' => 0,
        ];
    }

    public function readFile(int $handle, int $bytes): string|false
    {
        $this->transcript[] = \sprintf('readFile(%d,%d)', $handle, $bytes);

        if (!isset($this->pipes[$handle])) {
            return false;
        }

        $queued = \array_shift($this->readQueue);

        if ($queued === null) {
            return '';
        }

        return \strlen($queued) > $bytes ? \substr($queued, 0, $bytes) : $queued;
    }

    public function writeFile(int $handle, string $bytes): int|false
    {
        $this->transcript[] = \sprintf('writeFile(%d,%d bytes)', $handle, \strlen($bytes));

        if (!isset($this->pipes[$handle])) {
            return false;
        }

        return \strlen($bytes);
    }

    public function initializeAttributeList(int $attributeCount): int
    {
        $id = $this->issue('initializeAttributeList', \sprintf('count=%d', $attributeCount));
        $this->attributeLists[$id] = [];

        return $id;
    }

    public function setPseudoConsoleAttribute(int $attributeList, int $pseudoConsole): void
    {
        $this->guard($this->attributeLists, $attributeList, 'attribute list', 'updateProcThreadAttribute');

        if (!isset($this->pseudoConsoles[$pseudoConsole])) {
            throw new PtyException(\sprintf(
                'fake: pseudo-console %d is not live, so the attribute would name a closed console',
                $pseudoConsole,
            ));
        }

        $this->attributeLists[$attributeList]['pseudoConsole'] = $pseudoConsole;
        $this->transcript[] = \sprintf('setPseudoConsoleAttribute(%d,%d)', $attributeList, $pseudoConsole);
    }

    public function deleteAttributeList(int $attributeList): void
    {
        if (!isset($this->attributeLists[$attributeList])) {
            $this->transcript[] = \sprintf('deleteAttributeList(%d) already-gone', $attributeList);

            return;
        }

        unset($this->attributeLists[$attributeList]);
        $this->transcript[] = \sprintf('deleteAttributeList(%d)', $attributeList);
    }

    public function createProcess(
        string $applicationName,
        string $commandLine,
        int $attributeList,
        array $environment,
        string $workingDirectory = '',
        int $creationFlags = 0,
        ?int $job = null,
    ): array {
        // The invariant the real binding enforces by construction: a process
        // created with a dead attribute list has no pseudo-console, and shows up
        // as a hung session instead of an error. Failing here is what keeps that
        // class of bug testable.
        if (!isset($this->attributeLists[$attributeList])) {
            throw new PtyException(\sprintf(
                'fake: attribute list %d is not live at CreateProcessW time',
                $attributeList,
            ));
        }

        if (($this->attributeLists[$attributeList]['pseudoConsole'] ?? null) === null) {
            throw new PtyException(
                'fake: the attribute list carries no pseudo-console, so the child would start with no console',
            );
        }

        if (($creationFlags & self::EXTENDED_STARTUPINFO_PRESENT) === 0) {
            // Mirrors the real binding, which ADDS this flag rather than trusting
            // the caller: without it CreateProcessW reads the startup struct as a
            // plain STARTUPINFO and the attribute list is silently ignored.
            $creationFlags |= self::EXTENDED_STARTUPINFO_PRESENT;
        }

        $this->transcript[] = \sprintf(
            'createProcess(%s,%s,env=%d,flags=0x%X,job=%s)',
            $applicationName,
            $commandLine,
            \count($environment),
            $creationFlags,
            $job === null ? 'none' : (string) $job,
        );

        $process = $this->next++;
        $this->processes[$process] = true;
        $this->exitCodes[$process] = self::STILL_ACTIVE;

        if ($job !== null) {
            $this->assignProcessToJob($job, $process);
        }

        return ['process' => $process, 'processId' => 4000 + $process];
    }

    public function waitForSingleObject(int $handle, int $timeoutMs): int
    {
        $this->transcript[] = \sprintf('waitForSingleObject(%d,%d)', $handle, $timeoutMs);

        if (!isset($this->processes[$handle])) {
            return self::WAIT_FAILED;
        }

        return ($this->exitCodes[$handle] ?? self::STILL_ACTIVE) === self::STILL_ACTIVE
            ? self::WAIT_TIMEOUT
            : self::WAIT_OBJECT_0;
    }

    public function getExitCodeProcess(int $handle): int
    {
        $this->transcript[] = \sprintf('getExitCodeProcess(%d)', $handle);

        if (!isset($this->processes[$handle])) {
            throw new PtyException(\sprintf('fake: no such process handle %d', $handle));
        }

        return $this->exitCodes[$handle] ?? self::STILL_ACTIVE;
    }

    public function closeHandle(int $handle): bool
    {
        $this->transcript[] = \sprintf('closeHandle(%d)', $handle);

        $wasLive = isset($this->pipes[$handle]) || isset($this->processes[$handle]);

        unset($this->pipes[$handle], $this->processes[$handle]);

        return $wasLive;
    }

    public function createJob(): int
    {
        $id = $this->issue('createJob', 'kill-on-close');
        $this->jobs[$id] = [];

        return $id;
    }

    public function assignProcessToJob(int $job, int $process): void
    {
        $this->guard($this->jobs, $job, 'job', 'assignProcessToJob');
        $this->jobs[$job][$process] = true;
        $this->transcript[] = \sprintf('assignProcessToJob(%d,%d)', $job, $process);
    }

    public function terminateJob(int $job, int $exitCode = 0): void
    {
        $this->guard($this->jobs, $job, 'job', 'terminateJob');

        foreach (\array_keys($this->jobs[$job]) as $process) {
            $this->exitCodes[$process] = $exitCode;
        }

        $this->transcript[] = \sprintf('terminateJob(%d,%d)', $job, $exitCode);
    }

    public function closeJob(int $job): bool
    {
        if (!isset($this->jobs[$job])) {
            $this->transcript[] = \sprintf('closeJob(%d) already-gone', $job);

            return false;
        }

        // KILL_ON_JOB_CLOSE: closing the job handle takes the tree with it. The
        // model has to do the same, or a test asserting "the child died" would
        // pass against a double that leaked it.
        foreach (\array_keys($this->jobs[$job]) as $process) {
            if (isset($this->processes[$process])) {
                // Terminated with the job's exit code, and no longer waitable —
                // which is what a test asserting "the tree died" reads.
                $this->exitCodes[$process] = 1;
                unset($this->processes[$process]);
            }
        }

        unset($this->jobs[$job]);
        $this->transcript[] = \sprintf('closeJob(%d)', $job);

        return true;
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    /**
     * @param array<string|int, mixed> $bag
     */
    private function guard(array $bag, int $id, string $what, string $call): void
    {
        if (!isset($bag[$id])) {
            throw new PtyException(\sprintf(
                'fake: %s() names %s %d, which is not live',
                $call,
                $what,
                $id,
            ));
        }
    }

    private function guardLive(int $handle, string $what): void
    {
        if (!isset($this->pipes[$handle])) {
            throw new PtyException(\sprintf('fake: %s %d is not live', $what, $handle));
        }
    }

    /**
     * @param non-empty-string $call
     *
     * @return int the issued handle/id
     */
    private function issue(string $call, string $detail): int
    {
        $id = $this->next++;
        $this->transcript[] = \sprintf('%s(%s) -> %d', $call, $detail, $id);

        return $id;
    }
}
