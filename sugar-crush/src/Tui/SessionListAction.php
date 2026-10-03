<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * A row action the session picker asks its host to carry out
 * ({@see SessionPicker::handleKey()}'s action slot).
 *
 * The picker owns only display state — the filter, the inline rename draft,
 * the armed delete. Anything that writes the session store or moves the user
 * onto another session is reported back as one of these, and
 * {@see \SugarCraft\Crush\Chat} performs it, so the store is touched from one
 * place and the mid-turn and current-session refusals stay where the session
 * state lives (Appendix P §3.2).
 *
 * The two toggles change which rows are worth loading, which is also the
 * host's business: it re-reads the store and hands the rows back.
 *
 * This is a SugarCraft action set; charmbracelet/crush's picker has no row
 * actions.
 */
enum SessionListAction: string
{
    /** Commit the inline rename ({@see SessionPicker::renameTarget()}). */
    case Rename = 'rename';
    /** Delete the armed row; sub-agent children go with it, branches are detached. */
    case Delete = 'delete';
    /** Delete the armed row and every child session under it. */
    case DeleteWithChildren = 'delete-with-children';
    /** Pin or unpin the highlighted row. */
    case Pin = 'pin';
    /** Fork the highlighted row as a branch and switch to the copy. */
    case Fork = 'fork';
    /** Archive (soft-hide) the highlighted row. */
    case Archive = 'archive';
    /** Bring an archived row back. */
    case Unarchive = 'unarchive';
    /** Show or hide sub-agent child rows under their parents. */
    case ToggleChildren = 'children';
    /** Show or hide archived rows. */
    case ToggleArchived = 'archived';
    /** The filter was opened: the host may widen the loaded rows to search over. */
    case Filter = 'filter';
}
