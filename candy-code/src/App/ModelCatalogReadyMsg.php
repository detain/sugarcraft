<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * The async model-catalog answer landing on an open (or soon-open) palette
 * (C-PRV-5.c).
 *
 * Emitted from the {@see \SugarCraft\Core\Cmd::promise()} wake that
 * {@see Chat::pickPaletteProvider()} attaches: a palette open hands the
 * provider's `GET {root}/models` to the event loop instead of paying for it
 * synchronously on the keystroke path (the PRV-1 sync prime froze keys and
 * repaint for up to its whole 3-second discovery budget, once per process),
 * and this message arrives when the fetch answers — or resolves null when it
 * did not.
 *
 * WHY IT CARRIES $ids AT ALL, WHEN NOTHING READS THEM. The rows the picker
 * shows are re-derived from the {@see \SugarCraft\Code\Providers\ModelCatalog}
 * memo on every paint, and the wake's own `then` fills that memo before this
 * message dispatches — the merge is by derivation, exactly like
 * {@see ContextMeasuredMsg}'s stamp. The payload exists so the arm can carry
 * the answer's identity honestly: it names which provider answered and
 * whether it answered with anything, which is what a future keyed narrowing
 * (per-query re-filter) would need, and it keeps the message self-explanatory
 * on the transcript of a debug run. A failed fetch arrives as
 * `ids === null` and changes nothing on screen.
 */
final class ModelCatalogReadyMsg implements Msg
{
    /**
     * @param string $provider the palette provider name the fetch was armed for
     * @param list<string>|null $ids the ids the endpoint reported, null when it answered nothing
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?array $ids,
    ) {
    }
}
