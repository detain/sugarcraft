<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * Cross-process mutual exclusion for one {@see LspConnection}, plus the state
 * that must outlive whichever process held it last (audit B7).
 *
 * WHY: sugar-crush runs every turn in a pcntl_fork()ed child and sub-agents in
 * further forks, and every fork inherits the language server's stdio pipes.
 * Unique request ids ({@see \SugarCraft\Mcp\RequestIdSequence}) keep two
 * processes from answering to the same id, but two processes exchanging at
 * once still interleave their frames on stdin and race on stdout — and the
 * loser of a read consumes and throws away a frame meant for its sibling.
 * Serialising each WHOLE exchange (write the request, read through its
 * response) is what makes one shared server safe.
 *
 * This is the LSP twin of {@see \SugarCraft\Mcp\ExchangeLock} (audit B1/AG-1)
 * rather than a reuse of it, because `Content-Length` framing needs state that
 * newline framing does not: NDJSON recovers from a half-written line with a
 * leading "\n", but a half-written LSP frame has promised the server bytes that
 * never came, and the only repair is to send exactly the bytes still owed —
 * so the frame being written, and how much of it went out, must be recorded
 * where the next holder can find them. It also keeps the shared journal of
 * server notifications ({@see appendNote()}).
 *
 * THE SAME LAWS AS THE MCP LOCK:
 *  - flock() belongs to the open file DESCRIPTION, and a handle inherited
 *    across fork shares the parent's — so each process opens the lock path
 *    afresh ({@see handle()}), never using an inherited handle.
 *  - only the owner (the process that created the lock, i.e. ran
 *    {@see LspConnection::connect()}) may create or remove the files; a
 *    non-owner finding the lock file gone fails rather than resurrecting it.
 *  - the wait polls LOCK_NB so it honours the caller's deadline and gives up
 *    as soon as the server is gone.
 *
 * FILES. `<path>` carries only the flock. `<path>.state` holds the encoded
 * {@see LspExchangeState}; `<path>.frame` the frame being written;
 * `<path>.notes` the notification journal. The three data files are replaced
 * by write-to-temp + rename(), so a holder SIGKILLed mid-store leaves the old
 * contents or the new ones, never a torn mix — a guarantee an in-place
 * rewrite of the locked file itself could not give (and the lock file cannot
 * be renamed over, or the flock would move to a different inode).
 */
final class LspExchangeLock
{
    /** Poll slice while another process holds the lock. */
    private const POLL_MICROSECONDS = 2000;

    /**
     * Notification journal bounds. Diagnostics are last-writer-wins per URI, so
     * a process that falls further behind than this loses only stale
     * intermediate states; the bound keeps a chatty server from making every
     * journal rewrite expensive.
     */
    private const NOTE_LIMIT = 64;

    private const NOTE_BYTES = 262144;

    /** @var resource|null this process' own handle, never an inherited one */
    private $handle = null;

    /** The pid {@see $handle} was opened in. */
    private int $handlePid = 0;

    private function __construct(
        public readonly string $path,
        private readonly int $ownerPid,
    ) {
    }

    /**
     * Create the lock for a connection the CURRENT process owns.
     *
     * @throws \RuntimeException when no temp file can be created — a shared
     *         connection without exclusion is the defect this class closes, so
     *         it is refused rather than silently run unlocked
     */
    public static function create(string $label): self
    {
        $path = @tempnam(sys_get_temp_dir(), 'sugar-crush-lsp-lock-');
        if ($path === false) {
            throw new \RuntimeException(
                "LSP server {$label}: cannot create the exchange lock file in " . sys_get_temp_dir()
            );
        }

        $lock = new self($path, (int) getmypid());
        if (!$lock->replace($lock->statePath(), LspExchangeState::new()->encode())) {
            @unlink($path);

            throw new \RuntimeException(
                "LSP server {$label}: cannot create the exchange state file in " . sys_get_temp_dir()
            );
        }

        return $lock;
    }

    /**
     * Wait for the exclusive lock. Polls LOCK_NB rather than blocking so the
     * wait honours $deadline (`microtime(true)` seconds, null = none) and gives
     * up the moment the server is gone.
     *
     * @param \Closure(): bool $serverAlive
     */
    public function acquire(?float $deadline, \Closure $serverAlive): bool
    {
        $handle = $this->handle();
        if ($handle === null) {
            return false;
        }

        while (true) {
            $wouldBlock = 0;
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return true;
            }

            if ($wouldBlock !== 1) {
                return false;
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }

            if (!$serverAlive()) {
                return false;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Unlock AND close. The handle lives only for the length of one exchange:
     * a descriptor kept open between exchanges would be inherited by every
     * process this one later spawns (a Bash tool, a setsid'd grandchild), and
     * a flock is only released when the LAST descriptor on its description
     * closes — so a holder SIGKILLed mid-exchange would leave the lock held
     * for as long as any such grandchild lived.
     */
    public function release(): void
    {
        if ($this->handle !== null && $this->handlePid === (int) getmypid()) {
            flock($this->handle, LOCK_UN);
        }

        $this->close();
    }

    /**
     * The state the previous holder left. A missing file reads as a fresh
     * connection. Also safe WITHOUT the lock (a predicate reading only the
     * {@see LspExchangeState::$broken} latch), because stores are atomic
     * renames: an unlocked reader sees one whole state or another.
     */
    public function load(): LspExchangeState
    {
        $raw = @file_get_contents($this->statePath());

        return $raw === false ? LspExchangeState::new() : LspExchangeState::decode($raw);
    }

    public function store(LspExchangeState $state): void
    {
        $this->replace($this->statePath(), $state->encode());
    }

    /** Record the bytes of the frame about to be written (see {@see LspExchangeState}). */
    public function storeFrame(string $frame): void
    {
        $this->replace($this->framePath(), $frame);
    }

    public function loadFrame(): string
    {
        return (string) @file_get_contents($this->framePath());
    }

    /**
     * Journal one server notification and return its sequence number.
     *
     * The journal's own counter is authoritative; the copy in
     * {@see LspExchangeState::$noteSeq} is only the hint that lets a process
     * skip reading this file when nothing is new.
     *
     * @param array<mixed>|null $params
     */
    public function appendNote(string $method, ?array $params): int
    {
        [$seq, $notes] = $this->journal();
        $seq++;
        $notes[] = ['seq' => $seq, 'method' => $method, 'params' => $params];

        $notes = array_slice($notes, -self::NOTE_LIMIT);
        $encoded = serialize(['seq' => $seq, 'notes' => $notes]);
        while (count($notes) > 1 && strlen($encoded) > self::NOTE_BYTES) {
            array_shift($notes);
            $encoded = serialize(['seq' => $seq, 'notes' => $notes]);
        }

        $this->replace($this->notesPath(), $encoded);

        return $seq;
    }

    /**
     * Journal entries newer than $seen, oldest first.
     *
     * @return list<array{seq: int, method: string, params: array<mixed>|null}>
     */
    public function notesAfter(int $seen): array
    {
        [, $notes] = $this->journal();

        return array_values(array_filter($notes, static fn (array $note): bool => $note['seq'] > $seen));
    }

    /** Close THIS process' handle; the files and every other process' handle stay. */
    public function close(): void
    {
        if (is_resource($this->handle) && $this->handlePid === (int) getmypid()) {
            fclose($this->handle);
        }

        // An inherited handle is dropped, never unlocked: LOCK_UN on a shared
        // description would release a lock the parent may be holding.
        $this->handle = null;
        $this->handlePid = 0;
    }

    /** Close, and — in the owner only — remove every file. */
    public function destroy(): void
    {
        $this->close();

        if ((int) getmypid() === $this->ownerPid) {
            // The glob also takes any `<file>.<pid>.<rand>` temp a holder was
            // killed between writing and renaming.
            foreach ([...(glob($this->path . '.*') ?: []), $this->path] as $file) {
                @unlink($file);
            }
        }
    }

    private function statePath(): string
    {
        return $this->path . '.state';
    }

    private function framePath(): string
    {
        return $this->path . '.frame';
    }

    private function notesPath(): string
    {
        return $this->path . '.notes';
    }

    /** @return array{0: int, 1: list<array{seq: int, method: string, params: array<mixed>|null}>} */
    private function journal(): array
    {
        $raw = @file_get_contents($this->notesPath());
        $data = $raw === false || $raw === '' ? false : @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($data) || !is_int($data['seq'] ?? null) || !is_array($data['notes'] ?? null)) {
            return [0, []];
        }

        $notes = [];
        foreach ($data['notes'] as $note) {
            if (is_array($note) && is_int($note['seq'] ?? null) && is_string($note['method'] ?? null)) {
                $notes[] = [
                    'seq' => $note['seq'],
                    'method' => $note['method'],
                    'params' => is_array($note['params'] ?? null) ? $note['params'] : null,
                ];
            }
        }

        return [$data['seq'], $notes];
    }

    /**
     * Atomically replace $target. A non-owner whose owner has already removed
     * the lock file writes nothing: the connection is gone, and a file written
     * now would be an orphan in the temp dir.
     */
    private function replace(string $target, string $contents): bool
    {
        if ((int) getmypid() !== $this->ownerPid && !is_file($this->path)) {
            return false;
        }

        $temp = $target . '.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $contents) !== strlen($contents) || !@rename($temp, $target)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /** @return resource|null */
    private function handle()
    {
        $pid = (int) getmypid();

        if ($this->handle !== null && $this->handlePid === $pid && is_resource($this->handle)) {
            return $this->handle;
        }

        // A handle from another pid is the parent's description (see the class
        // doc-block): forget it without touching it.
        $this->handle = null;

        // Only the owner may (re)create the file; a non-owner meeting a missing
        // file means the owner already stopped the server.
        $handle = @fopen($this->path, $pid === $this->ownerPid ? 'c+' : 'r+');
        if ($handle === false) {
            return null;
        }

        $this->handle = $handle;
        $this->handlePid = $pid;

        return $handle;
    }
}
