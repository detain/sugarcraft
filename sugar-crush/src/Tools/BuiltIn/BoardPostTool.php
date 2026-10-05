<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\Board\Board;
use SugarCraft\Crush\Agents\Board\BoardKind;
use SugarCraft\Crush\Tools\PromptGuidance;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Post to the shared board of the parallel `Task` batch this run belongs to
 * (roadmap 4.5, Kilo's `board_post`): an INFO, ASK, RESULT, HOLD or VETO
 * ({@see BoardKind}) to one peer or to everyone.
 *
 * PERMISSION CLASS: no-ask. A post writes only the batch's own board, a file
 * the harness creates for the batch and removes after it, so a prompt would
 * protect nothing — the `Todo` and `Memory` reasoning. Bound like
 * {@see BoardReadTool}: never on a launch, added by {@see TaskTool} to a
 * batch member's run.
 *
 * A post wakes nobody. A peer hears of it as a notice on its next tool result
 * ({@see \SugarCraft\Crush\Hooks\BuiltIn\BoardNoticeHook}) and reads it when it
 * chooses to. Not parallel-safe: two posts in one step land in the order the
 * model wrote them.
 */
final readonly class BoardPostTool implements Tool, PromptGuidance
{
    public const NAME = 'BoardPost';

    private function __construct(
        private ?Board $board = null,
    ) {}

    /** The unbound tool: every call answers that this run is on no board. */
    public static function new(): self
    {
        return new self();
    }

    /** The same tool, posting to $board as its member. */
    public function withBoard(Board $board): self
    {
        return new self($board);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Post a short message to the shared board of the parallel batch of sub-agents you belong to, for one'
            . ' peer (its id) or for ' . Board::ALL . '. `kind` is INFO (something peers may want to know), ASK (a'
            . ' question), RESULT (a finished result peers can build on), HOLD (please wait before doing something)'
            . ' or VETO (please do not do something); HOLD and VETO are advice, not locks. Nobody is woken: a peer'
            . ' sees a notice on its next tool result.';
    }

    public function promptGuidance(): string
    {
        return 'Post to the shared board only what a peer running beside you needs while it works: a file you are'
            . ' about to change, a finding that changes its task, an answer to its question. Keep each post short'
            . ' (at most ' . Board::MAX_BODY_BYTES . ' bytes); your full report still goes back to whoever delegated'
            . ' you, so do not copy it onto the board.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type' => 'string',
                    'description' => 'A peer\'s id (as the board\'s roster names it), or "' . Board::ALL . '" for every peer',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => BoardKind::values(),
                    'description' => 'What the post is: INFO, ASK, RESULT, HOLD or VETO',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'The message, at most ' . Board::MAX_BODY_BYTES . ' bytes',
                ],
                'reply_to' => [
                    'type' => 'integer',
                    'description' => 'Optional. The number of the post this one answers (e.g. 3 for #3)',
                ],
            ],
            'required' => ['to', 'kind', 'body'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): ToolResult
    {
        $board = $this->board;
        if ($board === null) {
            return new ToolResult('', 'Error: there is no shared board here — only sub-agents running in one parallel'
                . ' batch share one, and this run is not in such a batch.', true);
        }

        $to = \is_string($args['to'] ?? null) ? trim($args['to']) : '';
        if (strcasecmp($to, Board::ALL) === 0) {
            $to = Board::ALL;
        }
        $kind = \is_string($args['kind'] ?? null) ? BoardKind::tryFrom(strtoupper(trim($args['kind']))) : null;
        $body = \is_string($args['body'] ?? null) ? $args['body'] : '';
        $replyTo = $args['reply_to'] ?? null;

        if ($to === '') {
            return self::refused('`to` is required: a peer id, or ' . Board::ALL);
        }
        if ($kind === null) {
            return self::refused('`kind` must be one of ' . implode(', ', BoardKind::values()));
        }
        if ($replyTo !== null && !\is_int($replyTo)) {
            if (\is_string($replyTo) && preg_match('/^#?(\d+)$/', trim($replyTo), $m) === 1) {
                $replyTo = (int) $m[1];
            } else {
                return self::refused('`reply_to` must be a post number');
            }
        }

        try {
            $entry = $board->post($to, $kind, $body, $replyTo);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return self::refused($e->getMessage());
        }

        return new ToolResult('', sprintf('Posted #%d to %s [%s].', $entry->id, $entry->to, $entry->kind->value));
    }

    private static function refused(string $why): ToolResult
    {
        return new ToolResult('', 'Error: ' . $why . '. Nothing was posted.', true);
    }
}
