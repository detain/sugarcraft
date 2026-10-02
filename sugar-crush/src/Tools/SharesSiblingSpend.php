<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Support\SiblingSpendLedger;

/**
 * A {@see Tool} that bills a provider itself and so must share its spend with
 * the other members of a concurrent group (audit B4-rem) — today only
 * {@see BuiltIn\TaskTool}, whose body is a whole delegated agentic run.
 *
 * {@see \SugarCraft\Crush\Runtime::executeConcurrently()} creates one
 * {@see SiblingSpendLedger} per group that has such a member and, inside each
 * member's forked child, executes the copy this method returns. The copy
 * records every step it bills onto the ledger and adds what its siblings have
 * recorded to its own spend-cap check; the parent reads the same records back
 * for a member whose child died before reporting.
 *
 * A seam rather than a parameter of {@see Tool::execute()} because exactly one
 * tool needs it and the rest of the corpus must not change shape for it.
 */
interface SharesSiblingSpend
{
    /** The same tool, recording its spend onto $ledger under the ledger's member name. */
    public function withSiblingSpend(SiblingSpendLedger $ledger): Tool;
}
