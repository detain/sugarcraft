<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Tools\BuiltIn\Compress;

/**
 * The preview of `/compact --self` (roadmap 3.B-4, Kilo's legacy
 * self-compaction): on that turn alone, the model's `Compress` call is put to
 * the person as a question carrying the summary it would apply, so it lands
 * only once they have read it — through the same permission modal (a Veil
 * overlay) every engine question uses. Approving runs the call; refusing
 * leaves the conversation as it was, and the model reads the refusal.
 *
 * Registered by {@see \SugarCraft\Crush\Backend\EngineBackend} on the turn's
 * own copy of the hook chain, only when the turn's prompt is the `--self`
 * trigger ({@see Compress::isSelfCompaction()}); every other turn's Compress
 * call (a plain `/compress`) is not previewed. Kilo re-summarised its
 * summary on the next compaction; here a later range that covers this block
 * nests it as a `(bN)` placeholder, so the text the person approved is never
 * rewritten by a model again.
 */
final readonly class CompressPreviewHook implements HookInterface
{
    public const NAME = 'compress-preview';

    /** Summary lines the question shows before it says how many it left out. */
    public const PREVIEW_LINES = 30;

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^' . Compress::NAME . '$';
    }

    public function execute(HookContext $context): HookResult
    {
        if ($context->toolName !== Compress::NAME) {
            return HookResult::allow();
        }

        return HookResult::ask(self::question($context->toolArgs));
    }

    /**
     * The question: the topic, each range's boundaries and its summary —
     * every summary line up to {@see PREVIEW_LINES} in all, then how many
     * more there are.
     *
     * @param array<string, mixed> $arguments the Compress call's arguments
     */
    public static function question(array $arguments): string
    {
        $topic = \is_string($arguments['topic'] ?? null) ? trim($arguments['topic']) : '';
        $lines = [];
        foreach (\is_array($arguments['ranges'] ?? null) ? $arguments['ranges'] : [] as $range) {
            if (!\is_array($range)) {
                continue;
            }
            $lines[] = sprintf('%s…%s:', (string) ($range['from'] ?? '?'), (string) ($range['to'] ?? '?'));
            foreach (explode("\n", trim((string) ($range['summary'] ?? ''))) as $line) {
                $lines[] = $line;
            }
        }
        $shown = \array_slice($lines, 0, self::PREVIEW_LINES);
        $hidden = \count($lines) - \count($shown);

        return '/compact --self: the model\'s summary would replace the conversation it covers'
            . ($topic === '' ? '' : ' ("' . $topic . '")') . ".\n\n"
            . implode("\n", $shown)
            . ($hidden > 0 ? "\n… (" . $hidden . ' more line' . ($hidden === 1 ? '' : 's') . ')' : '')
            . "\n\nApprove to apply it; refuse to keep the conversation as it is.";
    }
}
