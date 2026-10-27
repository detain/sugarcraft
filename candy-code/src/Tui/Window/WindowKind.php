<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

/**
 * What a window slot in the shared session-window kit describes: a top-level
 * session tab, or one delegated agent riding beneath its session.
 *
 * Part of the P-TABS.a kit (GAP-SES-12 / GAP-UI-N6): the model both the
 * session-tabs pick and the agent-attach pick build on. Nothing in the shell
 * consumes it yet — the kit is deliberately inert until P-TABS.g and
 * P-AGENT.b weld their routing onto it.
 */
enum WindowKind: string
{
    /** A top-level conversation session tab. */
    case Session = 'session';

    /** A delegated agent run shown attached to its parent session. */
    case Agent = 'agent';
}
