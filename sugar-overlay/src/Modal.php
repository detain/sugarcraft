<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

use SugarCraft\Core\Msg\KeyMsg;

/**
 * One modal layer a {@see ModalStack} paints and routes keys to.
 *
 * A modal is a value: handleKey() returns the next modal (or null to close)
 * rather than mutating itself, the same shape as a TEA update().
 */
interface Modal
{
    /**
     * The modal's body for a canvas of $cols x $rows cells. The stack places
     * it with the layer's {@see Placement} and veil (dim, border); the modal
     * only decides what it shows and may size itself to the canvas.
     */
    public function render(int $cols, int $rows): string;

    /**
     * React to a key while this modal is on top.
     *
     * @return array{0: ?Modal, 1: ?\Closure} the next modal (null closes this
     *         one) and an optional command for the host to run
     */
    public function handleKey(KeyMsg $msg): array;
}
