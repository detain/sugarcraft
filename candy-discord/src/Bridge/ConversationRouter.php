<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Bridge;

use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Candy\ServeError;
use CandyCode\Bundles\Discord\Lang;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\OutboundChunker;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use React\EventLoop\LoopInterface;
use SugarCraft\Plugins\Bridge\ConversationRouterCore;

/**
 * The Discord router: the shared {@see ConversationRouterCore} routing with the
 * Discord platform's error rendering, media classification and approval
 * roster boot notice layered on top.
 *
 * APPROVAL POSTURE (docs/SPEC.md §7.9): `policy['approvals']` shapes presentation.
 * The configured roster is ONE wall (empty = documented everyone); a boot in
 * that posture logs one content-free `approvals_open_roster` row. Behind it
 * rides the C-1 answer gate, and a button's per-user allowance stays a
 * platform courtesy. The core enforces both walls; see
 * {@see ConversationRouterCore::answerApproval()}.
 *
 * MUTABLE ON PURPOSE: it mirrors live session state.
 */
final class ConversationRouter extends ConversationRouterCore
{
    public function __construct(
        ServeBridge $serve,
        SessionMap $map,
        Log $log,
        TextSafety $safety,
        LoopInterface $loop,
        array $policy = [],
    ) {
        parent::__construct(
            $serve,
            $map,
            $log,
            $safety,
            new OutboundChunker(),
            $loop,
            static fn (string $key, array $params = []): string => Lang::t(self::catalogKey($key), $params),
            $policy,
        );
        $approvals = (array) ($policy['approvals'] ?? []);
        $roster = (array) ($approvals['approvers'] ?? $policy['approvers'] ?? []);
        if ((bool) ($approvals['enabled'] ?? true) && $roster === []) {
            // Operator truth (candy-discord-002): an empty roster is an OPEN
            // posture — any paired voice in the ask's conversation may answer
            // once/deny. Say so once at boot; content-free by design.
            $this->log->warn('approvals_open_roster');
        }
    }

    /** Chat-safe reason (015): a ServeError renders by kind, everything else keeps its message. */
    protected function failureReason(\Throwable $e): string
    {
        return $e instanceof ServeError ? $e->userReason() : $e->getMessage();
    }

    /**
     * Serve media refusals share one shape: a ServeError whose kind names
     * the rejected family (images_not_a_list, image_mime_mismatch,
     * documents_not_a_list, document_not_base64, document_not_decodable,
     * document_mime_mismatch — TurnMethods.php).
     */
    protected function isMediaRefusal(\Throwable $error): bool
    {
        return $error instanceof ServeError
            && (\str_starts_with($error->kind, 'image') || \str_starts_with($error->kind, 'document'));
    }

    protected function refusalKind(\Throwable $error): string
    {
        return $error instanceof ServeError ? $error->kind : 'unknown';
    }

    /**
     * Distinct faces (the '<platform>:<account>' map also carries bare
     * platform fallback aliases — retiring an approval must touch each
     * face once, not once per alias). The bundle owns this copy, so the
     * reply fallback below rides this bundle's catalog review.
     *
     * @return list<\SugarCraft\Plugins\Bridge\PlatformFace>
     */
    protected function channelFaces(): array
    {
        $seen = [];
        foreach ($this->channels as $face) {
            $seen[\spl_object_id($face)] = $face;
        }

        return \array_values($seen);
    }

    /**
     * command.exec returns {rows: [{role, content, uiOnly}], effects: []}
     * (CommandResult::toArray). uiOnly rows are transcript chrome for the
     * TUI — the channel gets the human text, joined.
     *
     * @param array<string,mixed> $result
     */
    protected function commandResultText(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['rows'] ?? []) as $row) {
            if (!\is_array($row) || (bool) ($row['uiOnly'] ?? false)) {
                continue;
            }
            $content = \trim((string) ($row['content'] ?? ''));
            if ($content !== '') {
                $parts[] = $content;
            }
        }
        $text = \implode("\n\n", $parts);

        return $text === '' ? 'Done.' : $text;
    }

    /**
     * The catalog key a core-relative key names. Each key is spelled out here, so the
     * catalog guard sees every key the router asks for; an unlisted key falls back to
     * the channels.discord namespace.
     */
    private static function catalogKey(string $key): string
    {
        return match ($key) {
            'session.could_not_open' => 'channels.discord.session.could_not_open',
            'turn.cancel_failed' => 'channels.discord.turn.cancel_failed',
            'router.dropped_queued' => 'channels.discord.router.dropped_queued',
            'session.no_id' => 'channels.discord.session.no_id',
            'session.new' => 'channels.discord.session.new',
            'session.could_not_create' => 'channels.discord.session.could_not_create',
            'turn.nothing_running' => 'channels.discord.turn.nothing_running',
            'turn.cancelled' => 'channels.discord.turn.cancelled',
            'approve.usage' => 'channels.discord.approve.usage',
            'attach.none' => 'channels.discord.attach.none',
            'model.failed' => 'channels.discord.model.failed',
            'pair.not_wired' => 'channels.discord.pair.not_wired',
            'queue.usage' => 'channels.discord.queue.usage',
            'turn.error' => 'channels.discord.turn.error',
            'turn.error_unknown' => 'channels.discord.turn.error_unknown',
            'turn.spend_cap' => 'channels.discord.turn.spend_cap',
            'approve.resolved_once' => 'channels.discord.approve.resolved_once',
            'approve.resolved_always' => 'channels.discord.approve.resolved_always',
            'approve.resolved_denied' => 'channels.discord.approve.resolved_denied',
            'approve.resolved_default' => 'channels.discord.approve.resolved_default',
            'approve.hint' => 'channels.discord.approve.hint',
            'approval.always_needs_approver' => 'channels.discord.approval.always_needs_approver',
            'approval.refused' => 'channels.discord.approval.refused',
            'approval.not_yours' => 'channels.discord.approval.not_yours',
            'replay.gap' => 'channels.discord.replay.gap',
            'media.refused' => 'channels.discord.media.refused',
            'prompt.failed' => 'channels.discord.prompt.failed',
            default => 'channels.discord.' . $key,
        };
    }
}
