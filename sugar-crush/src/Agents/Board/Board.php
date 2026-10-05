<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Board;

use SugarCraft\Crush\Support\ToolIpcFiles;

/**
 * The shared board one parallel `Task` batch posts to (roadmap 4.5, Kilo's
 * "shared agent board", competitor notes Appendix E §3.4).
 *
 * WHAT IT IS FOR. Sub-agents a model fans out in one message run side by
 * side, each in its own forked child, and until now could not tell each other
 * anything: two `coder`s split one refactor could both rename the same
 * helper, and a `reviewer` could not hand its finding to the `tester` running
 * next to it. A member posts an INFO, ASK, RESULT, HOLD or VETO
 * ({@see BoardKind}) with `BoardPost` and reads the board with `BoardRead`;
 * a peer that has not read yet learns there is something new from a notice
 * on its next tool result ({@see \SugarCraft\Crush\Hooks\BuiltIn\BoardNoticeHook}).
 * Nothing wakes, interrupts or blocks a member: posts are text, and HOLD and
 * VETO are advice.
 *
 * LIFECYCLE: ONE BOARD PER BATCH, the {@see \SugarCraft\Crush\Support\SiblingSpendLedger}
 * lifecycle. {@see \SugarCraft\Crush\Runtime::executeConcurrently()} creates it
 * in the parent, before any member is forked, when the batch has at least two
 * members that can join ({@see \SugarCraft\Crush\Tools\SharesBoard}); each
 * member's `Task` copy carries its own view ({@see forMember()}). The file is
 * removed when the batch is over ({@see BoardLease}).
 *
 * STORAGE. One append-only JSON-lines file in the system temp dir, created
 * 0600 and exclusively under {@see ToolIpcFiles::RUNTIME_PREFIX} (so a process
 * killed mid-batch leaves it to the same sweep as the batch's payloads).
 * Posts are appended under an exclusive `flock`, reads take a shared one. A
 * member never creates the file: a post after the batch ended finds it gone
 * and is refused, rather than resurrecting a board nobody will read. A line
 * that does not decode (a SIGKILL tore it) is skipped.
 *
 * LIMITS are Kilo's (`kilocode/board/store.ts`): a post is at most
 * {@see MAX_BODY_BYTES}, a board holds at most {@see MAX_ENTRIES} posts and
 * {@see MAX_FILE_BYTES}, and one read returns at most {@see MAX_READ_BYTES} of
 * entries.
 */
final readonly class Board
{
    /** The address that reaches every member of the board. */
    public const ALL = 'ALL';

    public const MAX_BODY_BYTES = 4096;

    public const MAX_ENTRIES = 1000;

    public const MAX_FILE_BYTES = 2 * 1024 * 1024;

    public const MAX_READ_BYTES = 32 * 1024;

    /** Entries one read returns when the caller names no limit. */
    public const DEFAULT_READ_LIMIT = 50;

    /** The file extension a board carries under {@see ToolIpcFiles::RUNTIME_PREFIX}. */
    public const EXTENSION = 'board';

    /**
     * @param list<BoardMember> $roster
     */
    private function __construct(
        private string $path,
        private array $roster,
        private string $member,
        private BoardLease $lease,
    ) {}

    /**
     * Create an empty board for one batch — the parent's half. Each member is
     * given its id here (`<agent>-<n>`, see {@see BoardMember}). Null when the
     * file cannot be made: the batch then runs exactly as it did before boards
     * existed, its members unable to talk, which is a weaker batch and never a
     * broken one.
     *
     * @param list<BoardMember> $members in batch order
     */
    public static function create(array $members): ?self
    {
        $roster = [];
        foreach (array_values($members) as $n => $member) {
            $agent = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $member->agent) ?? '';
            $agent = trim($agent, '-');
            $roster[] = $member->withId(($agent === '' ? 'agent' : $agent) . '-' . ($n + 1));
        }

        $path = ToolIpcFiles::reserve(ToolIpcFiles::RUNTIME_PREFIX, self::EXTENSION);

        $previous = umask(0o077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previous);
        }

        if ($handle === false) {
            return null;
        }
        fclose($handle);

        return new self($path, $roster, '', new BoardLease($path));
    }

    /**
     * The same board as the member $id sees it: its posts are signed $id, and
     * what is "new" is judged against it.
     *
     * @throws \InvalidArgumentException for an id that is not on the roster
     */
    public function forMember(string $id): self
    {
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a member of this board', $id));
        }

        return new self($this->path, $this->roster, $id, $this->lease);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The member this view posts as, or '' for the creating parent's view. */
    public function member(): string
    {
        return $this->member;
    }

    /**
     * @return list<BoardMember>
     */
    public function roster(): array
    {
        return $this->roster;
    }

    /** The roster entry for $id, or null. */
    public function find(string $id): ?BoardMember
    {
        foreach ($this->roster as $member) {
            if ($member->id === $id) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Append one post, signed by this view's member.
     *
     * @throws \InvalidArgumentException for a post the board refuses as
     *         written (empty or oversized body, an unknown or own address, a
     *         `reply_to` naming no post); the message is model-facing
     * @throws \RuntimeException when the board is gone (its batch ended) or full
     */
    public function post(string $to, BoardKind $kind, string $body, ?int $replyTo = null): BoardEntry
    {
        if ($this->member === '') {
            throw new \LogicException('only a member of the board can post to it');
        }

        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('the post has no body: say what it is about');
        }
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'the body is %d bytes; a post is at most %d — post the gist and keep the rest in your report',
                \strlen($body),
                self::MAX_BODY_BYTES,
            ));
        }
        if ($to === $this->member) {
            throw new \InvalidArgumentException(sprintf('"%s" is you: address a peer, or %s', $to, self::ALL));
        }
        if ($to !== self::ALL && $this->find($to) === null) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not on this board: address one of %s, or %s',
                $to,
                implode(', ', $this->peerIds()),
                self::ALL,
            ));
        }

        // r+, never a/c: a post after the batch ended must not resurrect the file.
        $handle = @fopen($this->path, 'r+');
        if ($handle === false) {
            throw new \RuntimeException('the board is gone: the batch it belonged to has ended');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('the board could not be locked for writing');
            }

            $raw = (string) stream_get_contents($handle, -1, 0);
            $entries = self::parse($raw);
            if (\count($entries) >= self::MAX_ENTRIES) {
                throw new \RuntimeException(sprintf('the board is full (%d posts)', self::MAX_ENTRIES));
            }

            $last = $entries === [] ? 0 : $entries[\count($entries) - 1]->id;
            if ($replyTo !== null && ($replyTo < 1 || $replyTo > $last)) {
                throw new \InvalidArgumentException(sprintf(
                    '`reply_to` %d names no post: the board holds posts #1 to #%d',
                    $replyTo,
                    $last,
                ));
            }

            $entry = new BoardEntry($last + 1, $this->member, $to, $kind, $body, $replyTo, microtime(true));
            $line = json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) {
                throw new \InvalidArgumentException('the post could not be encoded');
            }
            if (\strlen($raw) + \strlen($line) + 2 > self::MAX_FILE_BYTES) {
                throw new \RuntimeException(sprintf('the board is full (%d bytes)', self::MAX_FILE_BYTES));
            }

            fseek($handle, 0, SEEK_END);
            // Led by a newline too, so a line a SIGKILL tore before its own
            // newline is closed off here instead of swallowing this one.
            fwrite($handle, "\n" . $line . "\n");
            fflush($handle);

            return $entry;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Every post on the board, oldest first. Empty when the board is gone.
     *
     * @return list<BoardEntry>
     */
    public function entries(): array
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }

            return self::parse((string) stream_get_contents($handle));
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The posts after the cursor $since, oldest first: at most $limit of them
     * and at most {@see MAX_READ_BYTES} of rendered text, but never none when
     * there is one to give.
     *
     * @return list<BoardEntry>
     */
    public function read(int $since = 0, int $limit = self::DEFAULT_READ_LIMIT): array
    {
        $limit = max(1, $limit);
        $out = [];
        $bytes = 0;
        foreach ($this->entries() as $entry) {
            if ($entry->id <= $since) {
                continue;
            }

            $bytes += \strlen($entry->render()) + 1;
            if ($out !== [] && $bytes > self::MAX_READ_BYTES) {
                break;
            }

            $out[] = $entry;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** The id of the newest post, 0 for an empty (or vanished) board. */
    public function head(): int
    {
        $entries = $this->entries();

        return $entries === [] ? 0 : $entries[\count($entries) - 1]->id;
    }

    /**
     * Posts after $after that are this member's news: written by a peer and
     * addressed to this member or to everyone.
     *
     * @return list<BoardEntry>
     */
    public function newsFor(int $after): array
    {
        return array_values(array_filter(
            $this->entries(),
            fn (BoardEntry $e): bool => $e->id > $after && $e->from !== $this->member && $e->isFor($this->member),
        ));
    }

    /**
     * Every member's id except this view's own.
     *
     * @return list<string>
     */
    public function peerIds(): array
    {
        $ids = [];
        foreach ($this->roster as $member) {
            if ($member->id !== $this->member) {
                $ids[] = $member->id;
            }
        }

        return $ids;
    }

    /**
     * Remove the board now rather than when its last view goes — for an owner
     * that knows the batch is over. Only the creating process can.
     */
    public function discard(): void
    {
        $this->lease->release();
    }

    /**
     * @return list<BoardEntry>
     */
    private static function parse(string $raw): array
    {
        $entries = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }

            $entry = BoardEntry::fromArray(json_decode($line, true));
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
