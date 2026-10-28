<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\SecretRedactor;

/**
 * Reads a Codex CLI rollout log (JSONL) into Messages.
 *
 * The producer is Codex's `rollout-*.jsonl`. Schema pinned against: the
 * 2025/2026 rollout shape — one JSON object per line with `timestamp`
 * (RFC 3339) and `type`: `session_meta` (payload id/cwd/cli_version),
 * `turn_context` (per-turn settings), `event_msg` (the live event stream,
 * which only mirrors `response_item` rows and carries `token_count` usage
 * nobody asked to import), and `response_item` — the transcript proper,
 * whose payload type is `message` ({role, content list of input_text /
 * output_text / text}), `function_call` ({name, arguments, call_id}),
 * `function_call_output` ({call_id, output}) or `reasoning`.
 *
 * `event_msg`, `turn_context`, `session_meta` and `reasoning` rows advance
 * the file, not the conversation, so they are skipped; anything else is an
 * unknown shape and refused. Function calls and their outputs flatten to
 * text notice rows — an imported transcript is reference material and must
 * never carry a replayable tool row.
 */
final class CodexTranscriptReader
{
    private function __construct()
    {
    }

    /**
     * @return array{messages: list<Message>, title: ?string}
     */
    public static function read(string $raw, SecretRedactor $redactor, int $maxItems): array
    {
        $messages = [];
        $line = 0;
        foreach (self::lines($raw) as $text) {
            ++$line;
            $decoded = self::decode($text, $line);
            $type = $decoded['type'] ?? null;
            if (!\is_string($type)) {
                throw ImportRefusedException::shape('codex line ' . $line . ' carries no string type');
            }
            if ($type === 'session_meta' || $type === 'turn_context' || $type === 'event_msg') {
                continue;
            }
            if ($type !== 'response_item') {
                throw ImportRefusedException::shape('codex line ' . $line . ' carries an unknown type value');
            }
            $payload = $decoded['payload'] ?? null;
            if (!\is_array($payload) || !\is_string($payload['type'] ?? null)) {
                throw ImportRefusedException::shape('codex line ' . $line . ' has a response item without a typed payload');
            }
            $when = self::seconds($decoded['timestamp'] ?? null);
            $rows = match ($payload['type']) {
                'message' => self::messageRows($payload, $redactor, $when),
                'function_call' => [self::callRow($payload, $redactor, $when)],
                'function_call_output' => [self::outputRow($payload, $redactor, $when)],
                'reasoning' => [],
                default => throw ImportRefusedException::shape('codex line ' . $line . ' carries an unknown response item type ' . $payload['type']),
            };
            foreach ($rows as $row) {
                if (\count($messages) >= $maxItems) {
                    throw ImportRefusedException::tooManyItems($maxItems);
                }
                $messages[] = $row;
            }
        }

        return ['messages' => $messages, 'title' => null];
    }

    /**
     * A `message` payload → its zero or one conversation rows. Developer and
     * system frames arrive as untrusted notice text, never as instructions.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private static function messageRows(array $payload, SecretRedactor $redactor, ?int $when): array
    {
        $role = $payload['role'] ?? null;
        if (!\is_string($role)) {
            throw ImportRefusedException::shape('a codex message payload carries no string role');
        }
        $parts = $payload['content'] ?? null;
        if (!\is_array($parts)) {
            throw ImportRefusedException::shape('a codex message payload carries content that is not a list');
        }
        $text = '';
        foreach ($parts as $part) {
            if (!\is_array($part) || !\is_string($part['type'] ?? null)) {
                throw ImportRefusedException::shape('a codex message content part carries no type');
            }
            if ($part['type'] !== 'input_text' && $part['type'] !== 'output_text' && $part['type'] !== 'text') {
                throw ImportRefusedException::shape('a codex message carries an unknown content part type ' . $part['type']);
            }
            $chunk = $part['text'] ?? null;
            if (\is_string($chunk) && trim($chunk) !== '') {
                $text .= ($text === '' ? '' : "\n") . $chunk;
            }
        }
        if ($text === '') {
            return [];
        }
        $clean = $redactor->redact($text);

        return [match ($role) {
            'user' => Message::user($clean, $when),
            'assistant' => Message::assistant($clean, $when),
            'developer', 'system' => Message::notice($clean, $when),
            default => throw ImportRefusedException::shape('a codex message payload carries an unknown role value ' . $role),
        }];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function callRow(array $payload, SecretRedactor $redactor, ?int $when): Message
    {
        $name = $payload['name'] ?? null;
        if (!\is_string($name)) {
            throw ImportRefusedException::shape('a codex function call carries no string name');
        }
        $arguments = $payload['arguments'] ?? null;
        $arguments = \is_string($arguments) ? $arguments : json_encode($arguments, \JSON_UNESCAPED_SLASHES);

        return Message::notice('[Tool call] ' . $name . ' ' . $redactor->redact((string) $arguments), $when);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function outputRow(array $payload, SecretRedactor $redactor, ?int $when): Message
    {
        $output = $payload['output'] ?? null;
        if (!\is_string($output)) {
            $output = json_encode($output, \JSON_UNESCAPED_SLASHES);
        }

        return Message::notice('[Tool result] ' . $redactor->redact((string) $output), $when);
    }

    /**
     * The file's non-blank lines, in order; the trailing newline fragment
     * is padding, not a corrupt row.
     *
     * @return list<string>
     */
    private static function lines(string $raw): array
    {
        return array_values(array_filter(
            explode("\n", $raw),
            static fn(string $text): bool => trim($text) !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $text, int $line): array
    {
        try {
            $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ImportRefusedException::shape('codex line ' . $line . ' is not valid JSON');
        }
        if (!\is_array($decoded)) {
            throw ImportRefusedException::shape('codex line ' . $line . ' is not a JSON object');
        }

        return $decoded;
    }

    private static function seconds(mixed $timestamp): ?int
    {
        if (\is_int($timestamp)) {
            return $timestamp > 9_999_999_999 ? intdiv($timestamp, 1000) : $timestamp;
        }
        if (!\is_string($timestamp) || $timestamp === '') {
            return null;
        }
        $unix = strtotime($timestamp);

        return $unix === false ? null : $unix;
    }
}
