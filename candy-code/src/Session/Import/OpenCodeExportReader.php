<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\SecretRedactor;

/**
 * Reads an OpenCode `export` JSON dump into Messages.
 *
 * The producer is OpenCode's session export, a dump of its SQLite store
 * (session / message / part tables). Schema pinned against: the 2026.x
 * export shape — a top-level object `{"info": {session…}, "messages":
 * [{"info": {message…}, "parts": [{…}]}]}` (older dumps key the session
 * object `session`; both spellings are accepted because the field is only
 * read for the title). Message `info` carries `role` user | assistant and
 * `time.created` in epoch milliseconds; parts carry `type`: `text`
 * ({text}, plus the `synthetic`/`ignored` injection flags — absent or 1 —
 * that mark context the human never typed), `tool` ({tool, state}),
 * `reasoning`, and the bookkeeping family `step-start` / `step-finish`.
 *
 * Cost, token and model fields are deliberately never read: an imported
 * session is unpriced by law and shows `≥` for spend, so the source's own
 * usage ledger must not leak into ours.
 */
final class OpenCodeExportReader
{
    private function __construct()
    {
    }

    /**
     * @return array{messages: list<Message>, title: ?string}
     */
    public static function read(string $raw, SecretRedactor $redactor, int $maxItems): array
    {
        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ImportRefusedException::shape('opencode export is not valid JSON');
        }
        if (!\is_array($decoded) || !isset($decoded['messages']) || !\is_array($decoded['messages']) || !\array_is_list($decoded['messages'])) {
            throw ImportRefusedException::shape('opencode export must be an object with a messages list');
        }
        $session = $decoded['info'] ?? $decoded['session'] ?? null;
        if ($session !== null && !\is_array($session)) {
            throw ImportRefusedException::shape('opencode export carries a session object that is not an object');
        }
        if (\count($decoded['messages']) > $maxItems) {
            throw ImportRefusedException::tooManyItems($maxItems);
        }

        $messages = [];
        foreach ($decoded['messages'] as $index => $entry) {
            if (!\is_array($entry) || !\is_array($entry['info'] ?? null) || !isset($entry['parts']) || !\is_array($entry['parts'])) {
                throw ImportRefusedException::shape('opencode message ' . $index . ' is not an info/parts pair');
            }
            $role = $entry['info']['role'] ?? null;
            if ($role !== 'user' && $role !== 'assistant') {
                throw ImportRefusedException::shape('opencode message ' . $index . ' carries an unknown role value');
            }
            $created = $entry['info']['time']['created'] ?? null;
            $when = \is_int($created) ? intdiv($created, 1000) : null;

            $text = '';
            $tools = [];
            foreach ($entry['parts'] as $part) {
                if (!\is_array($part) || !\is_string($part['type'] ?? null)) {
                    throw ImportRefusedException::shape('opencode message ' . $index . ' carries a part with no type');
                }
                if (($part['synthetic'] ?? 0) == 1 || ($part['ignored'] ?? 0) == 1) {
                    continue;
                }
                switch ($part['type']) {
                    case 'text':
                        $chunk = $part['text'] ?? null;
                        if (!\is_string($chunk)) {
                            throw ImportRefusedException::shape('an opencode text part carries no string text');
                        }
                        if (trim($chunk) !== '') {
                            $text .= ($text === '' ? '' : "\n") . $chunk;
                        }
                        break;

                    case 'tool':
                        $tool = $part['tool'] ?? null;
                        if (!\is_string($tool)) {
                            throw ImportRefusedException::shape('an opencode tool part carries no string tool name');
                        }
                        $state = $part['state'] ?? null;
                        $detail = \is_array($state) && \is_string($state['output'] ?? null)
                            ? $state['output']
                            : json_encode($state, \JSON_UNESCAPED_SLASHES);
                        // Buffered until the spoken text of this message lands
                        // first: the flattened text rows keep the shape the
                        // reader recognised, not a re-timed fiction.
                        $tools[] = Message::notice('[Tool call] ' . $tool . ' ' . $redactor->redact((string) $detail), $when);
                        break;

                    case 'reasoning':
                    case 'step-start':
                    case 'step-finish':
                        break;

                    default:
                        throw ImportRefusedException::shape('opencode message ' . $index . ' carries an unknown part type ' . $part['type']);
                }
            }

            $rows = $text === '' ? $tools : [self::speak($redactor->redact($text), $role, $when), ...$tools];
            if (count($rows) > $maxItems - count($messages)) {
                throw ImportRefusedException::tooManyItems($maxItems);
            }
            foreach ($rows as $row) {
                $messages[] = $row;
            }
        }

        $title = \is_array($session) ? ($session['title'] ?? null) : null;

        return ['messages' => $messages, 'title' => \is_string($title) && trim($title) !== '' ? $title : null];
    }

    /** The one spoken row a message's accumulated text becomes. */
    private static function speak(string $text, string $role, ?int $when): Message
    {
        return $role === 'assistant'
            ? Message::assistant($text, $when)
            : Message::user($text, $when);
    }
}
