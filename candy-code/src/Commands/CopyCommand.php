<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Message;
use SugarCraft\Code\Role;

/**
 * The `/copy [n]` selection core (C-TUI-3.a, GAP-UI-16): WHICH text the
 * command puts on the clipboard, decided without touching one terminal.
 *
 * A bare `/copy` selects the last assistant reply — the same text the user
 * can already sweep with the mouse, reachable by a keystroke when the reply
 * scrolled out of the visible region or the terminal is a multiplexer whose
 * own selection the in-app drag cannot reach. `/copy n` selects the nth
 * fenced code block counted FROM THE END of that reply, so `/copy 1` is the
 * newest block — the one a user almost always means — and the count does not
 * move when the reply grows further blocks above it.
 *
 * PURE BY CONTRACT: {@see select()} reads only its arguments. It writes no
 * clipboard, raises no {@see \SugarCraft\Core\Cmd}, and asks no host; the
 * OSC 52 + system-tool legs live in `Chat::handleCopyCommand()`, beside the
 * drag-release precedent at the same seam.
 *
 * THE FENCE RECOGNIZER is the house one, narrowed to backtick fences: a line
 * whose trimmed content opens with ``` starts a block (its leading backtick
 * run is the delimiter), and a later line of nothing but at least that many
 * backticks ends it — an info string may only ride the opening line, so a
 * ```` ```sh ```` inside a block is content, exactly as CommonMark rules it.
 * An unterminated block runs to the end of the reply rather than vanishing:
 * the copy command exists for text the user meant to keep, and half a block
 * is still most of it. Tilde fences (~~~) are not recognised — the same
 * narrowness {@see \SugarCraft\Code\Providers\ToolCallParser\MarkupScanner}
 * ships (its `FENCE` is ``` too), and model prose fences are backticks.
 *
 * This is a SugarCraft architecture type, not a port: charmbracelet/crush
 * has no `/copy`; the command follows Claude Code's `/copy`, which is
 * exactly this bare-means-last-reply / n-means-block ladder.
 */
final class CopyCommand
{
    /** A selection was made; {@see select()}'s `text` carries it. */
    public const STATUS_OK = 'ok';
    /** The session has no assistant reply yet — the bare form has nothing to select. */
    public const STATUS_NO_REPLY = 'no-reply';
    /** The argument is neither empty nor a positive whole number. */
    public const STATUS_BAD_ARG = 'bad-arg';
    /** The reply has no fences at all: named none, `blocks` is 0. */
    public const STATUS_NO_BLOCK = 'no-block';
    /** The reply has `blocks` blocks but the number named none past them. */
    public const STATUS_OUT_OF_RANGE = 'out-of-range';

    private const FENCE = '```';

    private function __construct()
    {
    }

    /**
     * The text `/copy $argument` selects from $history.
     *
     * The LAST assistant reply is the newest history row with role
     * `assistant`, not uiOnly, and a non-blank body — a tool-step row that
     * carried only calls renders as steps, not as a reply, so an empty body
     * does not qualify and the search keeps walking.
     *
     * @param list<Message> $history
     *
     * @return array{status: string, text: string, blocks: int} `text` is non-empty
     *         only under {@see STATUS_OK}; `blocks` is the fence count of the
     *         reply that was found (0 when there is none), so the caller can
     *         state what range the number fell outside of
     */
    public static function select(array $history, string $argument): array
    {
        $reply = self::lastAssistantReply($history);
        if ($reply === null) {
            return ['status' => self::STATUS_NO_REPLY, 'text' => '', 'blocks' => 0];
        }

        $blocks = self::codeBlocks($reply);

        if ($argument === '') {
            return ['status' => self::STATUS_OK, 'text' => $reply, 'blocks' => count($blocks)];
        }

        if (preg_match('/^\d+$/', $argument) !== 1) {
            return ['status' => self::STATUS_BAD_ARG, 'text' => '', 'blocks' => count($blocks)];
        }

        $fromEnd = (int) $argument;
        if ($fromEnd < 1) {
            return ['status' => self::STATUS_BAD_ARG, 'text' => '', 'blocks' => count($blocks)];
        }
        if ($blocks === []) {
            return ['status' => self::STATUS_NO_BLOCK, 'text' => '', 'blocks' => 0];
        }
        if ($fromEnd > count($blocks)) {
            return ['status' => self::STATUS_OUT_OF_RANGE, 'text' => '', 'blocks' => count($blocks)];
        }

        return ['status' => self::STATUS_OK, 'text' => $blocks[count($blocks) - $fromEnd], 'blocks' => count($blocks)];
    }

    /**
     * @param list<Message> $history
     */
    private static function lastAssistantReply(array $history): ?string
    {
        foreach (array_reverse($history) as $message) {
            if ($message->role === Role::Assistant && !$message->uiOnly && trim($message->content) !== '') {
                return $message->content;
            }
        }

        return null;
    }

    /**
     * The bodies of the reply's fenced code blocks, document order, fence
     * lines excluded and no trailing newline.
     *
     * @return list<string>
     */
    private static function codeBlocks(string $reply): array
    {
        $blocks = [];
        /** @var list<string>|null $open */
        $open = null;
        $openLength = 0;

        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            $trimmed = trim($line);
            $run = $trimmed !== '' && str_starts_with($trimmed, self::FENCE)
                ? strlen($trimmed) - strlen(ltrim($trimmed, '`'))
                : 0;

            if ($open === null) {
                if ($run >= 3) {
                    $open = [];
                    $openLength = $run;
                }

                continue;
            }

            // Inside a block: only a line of nothing but at least as many
            // backticks as opened it closes it (an info string is opening
            // grammar, and a short run cannot terminate a long one).
            if ($run >= $openLength && rtrim($trimmed, '`') === '') {
                $blocks[] = implode("\n", $open);
                $open = null;
                $openLength = 0;

                continue;
            }

            $open[] = $line;
        }

        if ($open !== null && $open !== []) {
            // Unterminated fence: the block runs to the end of the reply.
            $blocks[] = implode("\n", $open);
        }

        // An empty block — a fence pair closed around nothing, or around only
        // blank lines — carries no text to copy, and counting it would let
        // `/copy n` "succeed" with an empty clipboard write beside it. It is
        // not a block for this command.
        return array_values(array_filter($blocks, static fn(string $block): bool => trim($block) !== ''));
    }
}
