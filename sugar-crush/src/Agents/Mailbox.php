<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Append-only JSON-lines mailbox for inter-teammate message delivery.
 *
 * Each teammate's inbox is stored as a single append-only file at
 * $basePath/{teammateId}/inbox.jsonl. A companion wake marker
 * ($basePath/{teammateId}/.wake) is rewritten with a fresh generation
 * token on every send.
 *
 * Messages are stored one JSON object per line. Reading validates every
 * line independently — a malformed line is skipped rather than aborting
 * the read, so one bad message cannot prevent delivery of subsequent ones.
 *
 * There is no OS-level wakeup across this plain-file transport (no inotify,
 * no signal), so waitForMessage() is a bounded poll-with-backoff loop
 * against the wake marker — it does NOT resume the instant a message
 * arrives, it notices within one poll interval of it.
 */
final class Mailbox
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Send a message from one teammate to another.
     *
     * Appends the message as a JSON line to the recipient's inbox and
     * rewrites the wake marker so a concurrent waitForMessage() notices
     * within its next poll interval.
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

        file_put_contents($inboxPath, $line, FILE_APPEND | LOCK_EX);
        $this->touchWakeMarker($toTeammateId);
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
                // Message is read if flagged inline OR if its Id appears in the
                // append-only read-markers file (which markRead appends to).
                $isRead = ($data['read'] ?? false) || isset($readMarkers[$data['id']]);
                yield new TeamMessage(
                    id: $data['id'],
                    fromTeammateId: $data['fromTeammateId'],
                    toTeammateId: $data['toTeammateId'],
                    type: $data['type'],
                    payload: $data['payload'],
                    sentAt: new \DateTimeImmutable($data['sentAt']),
                    read: $isRead,
                );
            } catch (\Exception) {
                // Malformed line — skip and continue
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
        file_put_contents(
            $this->readMarkersPath($teammateId),
            $messageId . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * Return the count of unread messages in a teammate's inbox.
     *
     * Unread status is determined by the inline `read` field OR by the
     * presence of the messageId in the append-only read-markers companion file.
     */
    public function getUnreadCount(string $teammateId): int
    {
        $count = 0;
        foreach ($this->receive($teammateId) as $message) {
            if (!$message->read) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Block until the next unread message arrives in a teammate's inbox, or
     * a bounded timeout elapses.
     *
     * Mirrors the pattern other Agents classes use when pcntl-based true
     * parallelism isn't assumed (see AgentWorkerPool::waitForCompletion):
     * there is no cross-process wakeup primitive available for a plain-file
     * transport, so this polls the wake marker with exponential backoff
     * (5ms → 100ms cap) instead of busy-spinning. The marker is rewritten
     * with a fresh generation token on every send() (see touchWakeMarker()),
     * so a change in its content — not merely its mtime, which on many
     * filesystems only has one-second resolution — reliably signals that a
     * send() happened since the last check, at which point the inbox is
     * re-scanned for the oldest unread message.
     *
     * Returns immediately (no wait) if an unread message is already
     * sitting in the inbox when called. Does NOT mark the returned message
     * read — call markRead() explicitly. Returns null if timeoutSeconds
     * elapses with no unread message appearing.
     */
    public function waitForMessage(string $teammateId, float $timeoutSeconds = 5.0): ?TeamMessage
    {
        $message = $this->firstUnread($teammateId);
        if ($message !== null) {
            return $message;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $lastWakeToken = $this->wakeMarkerToken($teammateId);
        $pollIntervalSeconds = 0.005;
        $maxPollIntervalSeconds = 0.1;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            usleep((int) round(min($pollIntervalSeconds, $remaining) * 1_000_000));
            $pollIntervalSeconds = min($pollIntervalSeconds * 2, $maxPollIntervalSeconds);

            $currentWakeToken = $this->wakeMarkerToken($teammateId);
            if ($currentWakeToken === $lastWakeToken) {
                continue;
            }
            $lastWakeToken = $currentWakeToken;

            $message = $this->firstUnread($teammateId);
            if ($message !== null) {
                return $message;
            }
        }
    }

    /**
     * Return the oldest unread message in a teammate's inbox, or null.
     */
    private function firstUnread(string $teammateId): ?TeamMessage
    {
        foreach ($this->receive($teammateId) as $message) {
            if (!$message->read) {
                return $message;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function inboxPath(string $teammateId): string
    {
        return $this->basePath . '/' . $teammateId . '/inbox.jsonl';
    }

    private function ensureInboxDirectory(string $teammateId): void
    {
        $dir = dirname($this->inboxPath($teammateId));
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new \RuntimeException("Failed to create inbox directory: {$dir}");
        }
    }

    private function readMarkersPath(string $teammateId): string
    {
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

    private function wakeMarkerPath(string $teammateId): string
    {
        return $this->basePath . '/' . $teammateId . '/.wake';
    }

    private function touchWakeMarker(string $teammateId): void
    {
        // Written content — not just mtime — doubles as a generation
        // token so waitForMessage() can detect "did a send() happen since
        // I last looked" at sub-second resolution; filemtime() only
        // reports whole seconds on most filesystems, which is too coarse
        // for a poll interval measured in milliseconds.
        file_put_contents($this->wakeMarkerPath($teammateId), (string) hrtime(true), LOCK_EX);
    }

    /**
     * Read the current wake-marker generation token, or null if no send()
     * has ever happened for this teammate.
     */
    private function wakeMarkerToken(string $teammateId): ?string
    {
        $path = $this->wakeMarkerPath($teammateId);
        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
