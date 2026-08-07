<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Append-only JSON-lines mailbox for inter-teammate message delivery.
 *
 * Each teammate's inbox is stored as a single append-only file at
 * $basePath/{teammateId}/inbox.jsonl. A companion wake marker
 * ($basePath/{teammateId}/.wake) is touched on every send to signal
 * the recipient's event loop.
 *
 * Messages are stored one JSON object per line. Reading validates every
 * line independently — a malformed line is skipped rather than aborting
 * the read, so one bad message cannot prevent delivery of subsequent ones.
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
     * touches the wake marker to signal message arrival.
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
        touch($this->wakeMarkerPath($teammateId));
    }
}
