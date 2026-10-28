<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\SecretRedactor;

/**
 * Reads a Claude Code project transcript (JSONL) into Messages.
 *
 * The producer is Claude Code's session log under
 * `~/.claude/projects/<encoded-cwd>/<uuid>.jsonl`. Schema pinned against:
 * Claude Code 2.1.x — one JSON object per line; conversation lines carry
 * `type` user | assistant | system with an ISO-millisecond `timestamp` and
 * a `message` object whose `content` is either a plain string or a list of
 * content blocks ({type:'text',text}, {type:'tool_use',name,input,id},
 * {type:'tool_result',content}); sidechain and meta injections carry
 * `isSidechain`/`isMeta` true; `file-history-snapshot` lines carry no
 * timestamp, and the telemetry family (attachment, mode, permission-mode,
 * queue-operation, cost-state, bridge-session, ai-title, last-prompt) is
 * bookkeeping, not conversation.
 *
 * Foreign tool activity never survives as a replayable row: `tool_use` and
 * `tool_result` blocks are flattened to text notice rows, so an imported
 * transcript cannot make a model believe it may re-run someone else's call.
 *
 * @internal
 */
final class ClaudeCodeTranscriptReader
{
    /** Envelope types that are bookkeeping, never conversation. */
    public const TELEMETRY_TYPES = [
        'attachment',
        'mode',
        'permission-mode',
        'queue-operation',
        'cost-state',
        'bridge-session',
        'file-history-snapshot',
        'ai-title',
        'last-prompt',
    ];

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
                throw ImportRefusedException::shape('claude code line ' . $line . ' carries no string type');
            }
            if (\in_array($type, self::TELEMETRY_TYPES, true)) {
                continue;
            }
            if (($decoded['isSidechain'] ?? false) === true || ($decoded['isMeta'] ?? false) === true) {
                continue;
            }
            if ($type !== 'user' && $type !== 'assistant' && $type !== 'system') {
                throw ImportRefusedException::shape('claude code line ' . $line . ' carries an unknown type value');
            }
            if (\count($messages) >= $maxItems) {
                throw ImportRefusedException::tooManyItems($maxItems);
            }

            $when = self::seconds($decoded['timestamp'] ?? null);
            if ($type === 'system') {
                $content = $decoded['content'] ?? null;
                if (!\is_string($content)) {
                    throw ImportRefusedException::shape('claude code line ' . $line . ' carries a system row without string content');
                }
                $messages[] = Message::notice($redactor->redact($content), $when);
                continue;
            }

            $message = $decoded['message'] ?? null;
            if (!\is_array($message)) {
                throw ImportRefusedException::shape('claude code line ' . $line . ' has no message object');
            }
            $content = $message['content'] ?? null;
            $blocks = \is_string($content) ? [['type' => 'text', 'text' => $content]] : $content;
            if (!\is_array($blocks)) {
                throw ImportRefusedException::shape('claude code line ' . $line . ' carries a message content that is neither string nor list');
            }
            foreach ($blocks as $block) {
                if (\count($messages) >= $maxItems) {
                    throw ImportRefusedException::tooManyItems($maxItems);
                }
                if (!\is_array($block) || !\is_string($block['type'] ?? null)) {
                    throw ImportRefusedException::shape('claude code line ' . $line . ' carries a content block with no type');
                }
                $row = self::rowFor($block, $type, $redactor, $when);
                if ($row !== null) {
                    $messages[] = $row;
                }
            }
        }

        return ['messages' => $messages, 'title' => null];
    }

    /**
     * One content block → its transcript row, or null when the block carries
     * no importable text.
     *
     * @param array<string, mixed> $block
     */
    private static function rowFor(array $block, string $type, SecretRedactor $redactor, ?int $when): ?Message
    {
        switch ($block['type']) {
            case 'text':
                $text = $block['text'] ?? null;
                if (!\is_string($text) || trim($text) === '') {
                    return null;
                }
                $clean = $redactor->redact($text);

                return $type === 'assistant'
                    ? Message::assistant($clean, $when)
                    : Message::user($clean, $when);

            case 'tool_use':
                $name = $block['name'] ?? null;
                if (!\is_string($name)) {
                    throw ImportRefusedException::shape('a tool_use block carries no string name');
                }
                $input = $block['input'] ?? null;
                $arguments = \is_string($input) ? $input : json_encode($input, \JSON_UNESCAPED_SLASHES);

                return Message::notice(
                    '[Tool call] ' . $name . ' ' . $redactor->redact((string) $arguments),
                    $when,
                );

            case 'tool_result':
                $inner = $block['content'] ?? '';
                if (\is_array($inner)) {
                    $parts = [];
                    foreach ($inner as $part) {
                        if (\is_array($part) && ($part['type'] ?? null) === 'text' && \is_string($part['text'] ?? null)) {
                            $parts[] = $part['text'];
                        }
                    }
                    $inner = $parts === [] ? json_encode($inner, \JSON_UNESCAPED_SLASHES) : implode("\n", $parts);
                }
                if (!\is_string($inner)) {
                    throw ImportRefusedException::shape('a tool_result block carries content that is neither string nor list');
                }

                return Message::notice('[Tool result] ' . $redactor->redact($inner), $when);

            default:
                throw ImportRefusedException::shape('claude code carries an unknown content block type ' . $block['type']);
        }
    }

    /**
     * The file's non-blank lines, in order. A JSONL log ends with a trailing
     * newline; that final empty fragment is padding, not a corrupt row.
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
        if (trim($text) === '') {
            throw ImportRefusedException::shape('claude code line ' . $line . ' is not a JSON object');
        }
        try {
            $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ImportRefusedException::shape('claude code line ' . $line . ' is not valid JSON');
        }
        if (!\is_array($decoded)) {
            throw ImportRefusedException::shape('claude code line ' . $line . ' is not a JSON object');
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
