<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * The conversation as plain labelled text, for a tool-less side call on the
 * title backend: the `/goal` judge (roadmap 3.D-3) and the `/btw` side
 * question (5.14b).
 *
 * WHY TEXT AND NOT THE MESSAGES THEMSELVES. Both calls go to a cheap model
 * with no tools, and a replayed history carries tool calls and tool rows a
 * tool-less request cannot pair — some providers reject the request outright.
 * As one block of text inside a single user turn, every provider takes it,
 * and the tool OUTPUT stays in: the judge decides on evidence, and a test run
 * the agent made is exactly the evidence it needs (it cannot run anything
 * itself).
 *
 * Only what the model was shown ({@see Message::agentVisible()}), newest last,
 * bounded twice — per row and in total, the oldest rows dropped first — so a
 * long session costs a bounded call. The text is fenced: a closing tag inside
 * a row (a file the agent read could hold one) is defused so the block cannot
 * be ended early.
 */
final class TranscriptDigest
{
    /** Rows of the tail the digest is built from. */
    public const MAX_ROWS = 60;

    /** Characters of one row kept, split between its head and its tail. */
    public const ROW_CHARS = 3000;

    /** Characters of the whole digest. */
    public const TOTAL_CHARS = 40000;

    /**
     * The digest of $history, oldest kept row first, or '' when the model was
     * shown nothing.
     *
     * @param array<int, Message> $history
     */
    public static function of(array $history, string $fence = 'transcript'): string
    {
        $rows = [];
        $total = 0;
        foreach (array_reverse(\array_slice(Message::agentVisible($history), -self::MAX_ROWS)) as $message) {
            $line = self::row($message, $fence);
            if ($line === null) {
                continue;
            }
            $cost = mb_strlen($line) + 1;
            if ($rows !== [] && $total + $cost > self::TOTAL_CHARS) {
                break;
            }
            $total += $cost;
            $rows[] = $line;
        }

        return implode("\n", array_reverse($rows));
    }

    /** $text with every `</$fence>` defused, so it cannot close the block it sits in. */
    public static function fenced(string $text, string $fence): string
    {
        return str_ireplace('</' . $fence, '<\\/' . $fence, $text);
    }

    private static function row(Message $message, string $fence): ?string
    {
        if ($message->toolResults !== []) {
            $parts = [];
            foreach ($message->toolResults as $result) {
                if (!$result instanceof ToolResult) {
                    continue;
                }
                $body = $result->error !== null && $result->error !== ''
                    ? 'ERROR: ' . $result->error
                    : $result->result;
                $parts[] = 'Tool result (' . $result->name . '): ' . self::clip(trim($body));
            }

            return $parts === [] ? null : self::fenced(implode("\n", $parts), $fence);
        }

        $text = trim($message->content);
        $calls = [];
        foreach ($message->toolCalls as $call) {
            if ($call instanceof ToolCall) {
                $calls[] = Message::describeToolCall($call);
            }
        }
        if ($calls !== []) {
            $text = ltrim($text . "\n[called: " . implode('; ', $calls) . ']');
        }
        if ($text === '') {
            return null;
        }

        $label = match ($message->role) {
            Role::User => 'User',
            Role::Assistant => 'Assistant',
            Role::System => 'System',
        };

        return self::fenced($label . ': ' . self::clip($text), $fence);
    }

    /** $text cut to {@see ROW_CHARS}, keeping its head and its tail (an error is often last). */
    private static function clip(string $text): string
    {
        if (mb_strlen($text) <= self::ROW_CHARS) {
            return $text;
        }
        $half = intdiv(self::ROW_CHARS, 2);

        return mb_substr($text, 0, $half) . "\n[… cut …]\n" . mb_substr($text, -$half);
    }
}
