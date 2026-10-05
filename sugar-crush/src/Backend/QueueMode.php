<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

/**
 * What a prompt sent while a turn is running does (roadmap 1.C-3, decision
 * D6; OpenClaw `queueMode`, opencode `delivery`).
 *
 * The TUI binds Enter-while-busy to {@see onEnter()} — {@see Steer} unless
 * the `queueMode` setting (roadmap N-P4g) names another case — and Tab to
 * {@see Followup}. A headless host's submission that names no delivery gets
 * the same default ({@see \SugarCraft\Crush\Host\SubmitOptions::effectiveDelivery()}).
 */
enum QueueMode: string
{
    /**
     * Delivered into the RUNNING turn at its next step boundary
     * ({@see TurnInbox}); calls of the current step that have not started are
     * skipped so the message is read first. A steer that arrives after the
     * turn's last boundary is sent as the next prompt instead, so it is never
     * lost.
     */
    case Steer = 'steer';

    /** Held until the running turn ends, then sent as the next prompt. */
    case Followup = 'followup';

    /**
     * Stop the running turn at its next step boundary (a soft cancel), then
     * send the message as a new turn. What Enter does when `queueMode` is
     * `interrupt`; no key binds it on its own.
     */
    case Interrupt = 'interrupt';

    /**
     * The mode Enter uses while a turn runs: the `queueMode` setting
     * (decision D6, roadmap N-P4g), {@see Steer} when it is unset or not one
     * of the cases. The single switch: every Enter-while-busy reads it here.
     */
    public static function onEnter(): self
    {
        $value = \SugarCraft\Crush\Config\Settings\UiSettings::value('queueMode');

        return \is_string($value) ? (self::tryFrom($value) ?? self::Steer) : self::Steer;
    }
}
