<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * The optional width surface of a prompt (finding 038, second interface).
 *
 * {@see Readline::run()} uses it to adopt the terminal column budget when
 * the host left the display width unset (tri-state: null = adopt, 0 =
 * explicit unbounded, positive = given). It is a separate contract from
 * {@see PromptInterface} because foreign prompt shapes may drive the pump
 * without being width-managed; the pump's is_callable guards stay as the
 * BC seam for those.
 *
 * The height sibling (`viewportRows()/withViewportRows()`) is deliberately
 * NOT here: only the text-area and list shapes paginate, and forcing the
 * pair onto text/confirmation prompts would invent a knob they cannot
 * honor. Readline probes it with is_callable the same way.
 */
interface SizingInterface
{
    /** Display bound in cells; null = unset (adoption-eligible), 0 = unbounded. */
    public function displayWidth(): ?int;

    /** Immutable setter for the display bound (see the tri-state above). */
    public function withDisplayWidth(?int $cells): self;
}
