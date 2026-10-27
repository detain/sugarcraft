<?php

declare(strict_types=1);

namespace SugarCraft\Code\App\Tabs;

use Closure;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ScreenStackPoppedMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\Msg\SubscriptionsMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\PrintMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\SequenceMsg;
use SugarCraft\Core\Subscription;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\TickRequest;
use Throwable;

/**
 * Lazy Cmd/subscription wrapper stamping every produced Msg with its
 * owning tab key.
 *
 * A parked tab's model still returns plain `Closure(): ?Msg` Cmds that
 * know nothing about the multi-session deck; this scope re-wraps them AT
 * THE DECK BOUNDARY so each result reaches Program::dispatch() already
 * labelled. Msgs that Program intercepts for the runtime itself are NOT
 * labelled — a wrapped sentinel would be swallowed (a wrapped QuitMsg
 * would never quit), so pass-through classes return identity and the
 * structural classes (batch/sequence/tick/promise) keep their own shape
 * while every leaf inside them is mapped recursively.
 */
final class TabScope
{
    /**
     * Prefix {@see subscriptions()} welds onto every re-keyed id.
     *
     * Program::reconcileSubscriptions() diffs subscription sets BY ID
     * only, so several live Chats can coexist on one loop only when
     * their ids cannot collide.
     */
    public const ID_PREFIX = 'tab:';

    /**
     * Msg classes Program::dispatch() intercepts for the runtime itself.
     *
     * Wrapping any of these would hide it from the strict `instanceof`
     * ladder in sugar-core/src/Program.php, silently swallowing quits,
     * prints, terminal handoffs, and screen-stack routing. WindowSizeMsg
     * sits in this set even though it later reaches the model: Program
     * consumes its recorder/renderer-reset role first, and a Cmd that
     * mints a resize does not exist in this app today — if one ever
     * does, the fix is to unwrap the envelope in that model arm, NOT to
     * drop this entry and lose the reset for every other producer.
     *
     * @var list<class-string<Msg>>
     */
    public const PASS_THROUGH = [
        RawMsg::class,
        PrintMsg::class,
        ExecRequest::class,
        SuspendMsg::class,
        InterruptMsg::class,
        QuitMsg::class,
        WindowSizeMsg::class,
        SubscriptionsMsg::class,
        ScreenStackPushedMsg::class,
        ScreenStackPoppedMsg::class,
    ];

    /**
     * Msg classes whose payload is a nested tree of Cmds and producers.
     *
     * These keep their identity but every leaf inside them is mapped
     * through the same envelope rules.
     *
     * @var list<class-string<Msg>>
     */
    public const STRUCTURAL = [
        BatchMsg::class,
        SequenceMsg::class,
        TickRequest::class,
        AsyncCmd::class,
    ];

    private function __construct()
    {
    }

    /**
     * Wrap a tab model's Cmd so its result carries $tabKey.
     *
     * @param string $tabKey Deck key of the producing tab.
     * @param (Closure(): ?Msg)|null $cmd The raw Cmd; null passes through.
     * @return (Closure(): ?Msg)|null
     */
    public static function cmd(string $tabKey, ?Closure $cmd): ?Closure
    {
        if ($cmd === null) {
            return null;
        }

        return static function () use ($tabKey, $cmd): ?Msg {
            /** @var Msg|null $produced */
            $produced = $cmd();

            return self::msg($tabKey, $produced);
        };
    }

    /**
     * Map one dispatch result through the envelope rules.
     *
     * @param string $tabKey Deck key to stamp.
     * @param Msg|null $msg The Msg a Cmd just produced; null passes through.
     * @return Msg|null
     */
    public static function msg(string $tabKey, ?Msg $msg): ?Msg
    {
        if ($msg === null) {
            return null;
        }

        if ($msg instanceof BatchMsg) {
            return new BatchMsg(array_map(
                static fn (?Closure $inner): ?Closure => self::cmd($tabKey, $inner),
                $msg->cmds,
            ));
        }

        if ($msg instanceof SequenceMsg) {
            return new SequenceMsg(array_map(
                static fn (?Closure $inner): ?Closure => self::cmd($tabKey, $inner),
                $msg->cmds,
            ));
        }

        if ($msg instanceof TickRequest) {
            return new TickRequest($msg->seconds, self::cmd($tabKey, $msg->produce));
        }

        if ($msg instanceof AsyncCmd) {
            // The rejection leg RETURNS a scoped value, so Program's own
            // reject path (raw ExceptionMsg plus the user handler) never
            // fires for tab work — the owning tab's model sees the
            // failure as an ordinary Msg instead.
            return new AsyncCmd($msg->promise->then(
                static fn (mixed $resolved): ?Msg => $resolved instanceof Msg
                    ? self::msg($tabKey, $resolved)
                    : null,
                static fn (Throwable $failure): Msg => new TabScopedMsg($tabKey, new ExceptionMsg($failure)),
            ));
        }

        foreach (self::PASS_THROUGH as $sentinel) {
            if ($msg instanceof $sentinel) {
                return $msg;
            }
        }

        return new TabScopedMsg($tabKey, $msg);
    }

    /**
     * Re-key a tab model's Subscriptions onto the deck's shared loop.
     *
     * Ids become `tab:<key>:<id>` so several live Chats never collide in
     * the by-id reconciliation diff, and producers fire through
     * {@see msg()} exactly like wrapped Cmd results. Subscriptions named
     * in $foregroundOnly are DROPPED — the deck keeps them on the single
     * Chat that owns the visible frame, so a background poll, status
     * line, or idle compact never double-announces from parked tabs.
     *
     * @param string $tabKey Deck key to weld into every id.
     * @param Subscriptions|null $subscriptions The model's declared set; null passes through.
     * @param list<string> $foregroundOnly Original ids to suppress on this holder.
     * @return Subscriptions|null
     */
    public static function subscriptions(string $tabKey, ?Subscriptions $subscriptions, array $foregroundOnly = []): ?Subscriptions
    {
        if ($subscriptions === null) {
            return null;
        }

        $kept = [];
        foreach ($subscriptions->all() as $subscription) {
            if (in_array($subscription->id, $foregroundOnly, true)) {
                continue;
            }

            $produce = $subscription->produce;
            $kept[] = new Subscription(
                id: self::ID_PREFIX . $tabKey . ':' . $subscription->id,
                kind: $subscription->kind,
                params: $subscription->params,
                produce: static fn (): ?Msg => self::msg($tabKey, $produce()),
            );
        }

        return new Subscriptions($kept);
    }
}
