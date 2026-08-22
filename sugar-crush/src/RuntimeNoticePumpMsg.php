<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Wake-up telling {@see Chat} to drain
 * {@see Diagnostics\RuntimeNoticeSink} into the transcript (E171).
 *
 * Deliberately payload-free, for {@see ToolEventPumpMsg}'s reason and one more
 * of its own. The notices live in a PROCESS-WIDE sink rather than on any Chat,
 * because the subsystems that raise them are `final readonly` value objects
 * several layers below anything holding a model — see that class's doc-block —
 * and on the interactive path they are not even in this process when they
 * raise one. A Msg carrying the text would mean the sink had already been
 * drained by whatever built the Msg, and the only thing that can build one is
 * the tick below.
 *
 * Emitted from exactly one place: {@see Chat::subscriptions()}'s poll. Unlike
 * {@see ToolEventPumpMsg} it does NOT re-send itself per remaining entry —
 * {@see Chat::update()} folds the whole drained batch into one append, because
 * a notice has no two-state running→done shape to make visible and there is
 * nothing to be gained by rendering between two of them.
 */
final class RuntimeNoticePumpMsg implements Msg
{
}
