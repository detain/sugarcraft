<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Support\LineAppends;

/**
 * Append-only JSON-lines mailbox for inter-teammate message delivery.
 *
 * Each teammate's inbox is stored as a single append-only file at
 * $basePath/{teammateId}/inbox.jsonl.
 *
 * Messages are stored one JSON object per line. Reading validates every
 * line independently — a malformed line is skipped rather than aborting
 * the read, so one bad message cannot prevent delivery of subsequent ones.
 */
final class Mailbox
{
    /**
     * A teammate/agent id names a directory under the base path, so it stays one
     * safe path segment (no separators, no NUL, no traversal). Deliberately the
     * superset every live caller already enforces ({@see Live\AgentInbox},
     * {@see Live\SessionMailbox}); TeamTool layers its stricter team-id shape on top.
     */
    public const ID_PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Send a message from one teammate to another.
     *
     * Appends the message as a JSON line to the recipient's inbox.
     */
    public function send(string $fromTeammateId, string $toTeammateId, TeamMessage $message): void
    {
        $this->ensureInboxDirectory($toTeammateId);
        $inboxPath = $this->inboxPath($toTeammateId);

        $line = json_encode([
            'id' => $message->id,
            'fromTeammateId' => $message->fromTeammateId,
            'toTeammateId' => $message->toTeammateId,
            'type' => $message->type,
            'payload' => $message->payload,
            'sentAt' => $message->sentAt->format(\DateTimeImmutable::ATOM),
            'read' => $message->read,
        ], JSON_THROW_ON_ERROR) . "\n";

        LineAppends::append($inboxPath, $line);
    }

    /**
     * The size in bytes of a teammate's inbox file, 0 when it has none — a
     * stat, not a read, so a caller that polls between steps
     * ({@see \SugarCraft\Code\Backend\MailboxTurnInbox::pending()}) pays
     * for a scan only when something was appended since its last one.
     */
    public function inboxSize(string $teammateId): int
    {
        $path = $this->inboxPath($teammateId);
        clearstatcache(true, $path);
        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }

    /**
     * Receive messages from a teammate's inbox.
     *
     * Yields each message from the inbox. Malformed lines are skipped
     * silently so one bad message cannot prevent delivery of subsequent ones.
     * Read status is determined by the inline `read` field OR by the presence
     * of the messageId in the append-only read-markers companion file.
     *
     * @return \Generator<int, TeamMessage, mixed, mixed>
     */
    public function receive(string $teammateId): \Generator
    {
        $inboxPath = $this->inboxPath($teammateId);

        if (!file_exists($inboxPath)) {
            return;
        }

        $readMarkers = $this->loadReadMarkers($teammateId);

        $handle = fopen($inboxPath, 'r');
        if ($handle === false) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                // Audit M1: valid JSON is not a message. `123`, `"abc"`, `null`
                // and `[]` all decode cleanly, and constructing from them threw
                // a TypeError — which the old `catch (\Exception)` never saw,
                // so one hand-corrupted line killed every teammate's message
                // processing for the rest of the session, breaking the
                // documented skip-malformed guarantee. Parse-don't-validate:
                // shape-check here, trust the array below.
                if (!is_array($data)
                    || !isset(
                        $data['id'],
                        $data['fromTeammateId'],
                        $data['toTeammateId'],
                        $data['type'],
                        $data['payload'],
                        $data['sentAt'],
                    )
                    || !is_string($data['id'])
                    || !is_string($data['fromTeammateId'])
                    || !is_string($data['toTeammateId'])
                    || !is_string($data['type'])
                    || !is_array($data['payload'])
                ) {
                    continue;
                }

                // sentAt must be a string a DateTimeImmutable will accept; the
                // constructor throws DateMalformedStringException otherwise,
                // which the Throwable arm below turns into the same skip as
                // every other malformed shape (audit M1's guarantee is that NO
                // line can escape as a crash).
                if (!is_string($data['sentAt'])) {
                    continue;
                }
                $sentAt = new \DateTimeImmutable($data['sentAt']);

                // Message is read if flagged inline OR if its Id appears in the
                // append-only read-markers file (which markRead appends to).
                $isRead = ($data['read'] ?? false) || isset($readMarkers[$data['id']]);
                yield new TeamMessage(
                    id: $data['id'],
                    fromTeammateId: $data['fromTeammateId'],
                    toTeammateId: $data['toTeammateId'],
                    type: $data['type'],
                    payload: $data['payload'],
                    sentAt: $sentAt,
                    read: $isRead,
                );
            } catch (\Throwable) {
                // Malformed line — skip and continue. Throwable, not Exception:
                // a TypeError escaping here is exactly the M1 crash (audit).
                continue;
            }
        }

        fclose($handle);
    }

    /**
     * Return all messages in a teammate's inbox without marking them read.
     *
     * @return TeamMessage[]
     */
    public function peek(string $teammateId): array
    {
        return iterator_to_array($this->receive($teammateId));
    }

    /**
     * Mark a specific message as read.
     *
     * Uses an append-only companion file (inbox.jsonl.read_markers) so that
     * markRead never rewrites the inbox — preserving the append-only durability
     * guarantee and eliminating the race condition where a concurrent send()
     * could have its message silently lost during a read-then-truncate cycle.
     */
    public function markRead(string $teammateId, string $messageId): void
    {
        // Append the messageId to the read-markers file (oneId per line).
        // receive() checks this file to determine read status without ever
        // needing to modify the inbox itself.
        if ($messageId === '' || preg_match('/[\r\n\0]/', $messageId) === 1) {
            throw new \InvalidArgumentException('Mailbox message id must be non-empty and single-line.');
        }
        LineAppends::append($this->readMarkersPath($teammateId), $messageId . "\n");
    }

    /**
     * Return the count of unread messages in a teammate's inbox.
     *
     * Unread status is determined by the inline `read` field OR by the
     * presence of the messageId in the append-only read-markers companion file.
     */
    public function unreadCount(string $teammateId): int
    {
        $count = 0;
        foreach ($this->receive($teammateId) as $message) {
            if (!$message->read) {
                $count++;
            }
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Gate every path built from an id: send/receive/markRead/inboxSize all route through the three path helpers. */
    private static function assertId(string $teammateId): void
    {
        if (preg_match(self::ID_PATTERN, $teammateId) !== 1 || $teammateId === '.' || $teammateId === '..') {
            throw new \InvalidArgumentException('Invalid mailbox teammate id: must be 1-128 chars of [A-Za-z0-9._-] and not "." or "..".');
        }
    }

    private function inboxPath(string $teammateId): string
    {
        self::assertId($teammateId);

        return $this->basePath . '/' . $teammateId . '/inbox.jsonl';
    }

    private function ensureInboxDirectory(string $teammateId): void
    {
        $dir = dirname($this->inboxPath($teammateId));
        // mkdir race (audit L2): two concurrent senders to one recipient — the
        // loser of the mkdir sees `false` even though the directory now EXISTS,
        // because another process created it a microsecond earlier. The second
        // is_dir() is what distinguishes "someone else won the race" (success)
        // from "the filesystem really refused" (the RuntimeException below).
        //
        // Owner-only (0700, files 0600 under the umask send() sets): an inbox
        // holds what one agent told another, and roadmap P-D1's agent
        // mailboxes hold what the USER told a sub-agent.
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create inbox directory: {$dir}");
        }
    }

    private function readMarkersPath(string $teammateId): string
    {
        self::assertId($teammateId);

        return $this->basePath . '/' . $teammateId . '/inbox.jsonl.read_markers';
    }

    /**
     * Load the set of read messageIds from the append-only read-markers file.
     *
     * @return array<string, true>  messageId => true for O(1) lookups
     */
    private function loadReadMarkers(string $teammateId): array
    {
        $path = $this->readMarkersPath($teammateId);
        if (!file_exists($path)) {
            return [];
        }

        $markers = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $id = trim($line);
            if ($id !== '') {
                $markers[$id] = true;
            }
        }
        fclose($handle);

        return $markers;
    }
}
