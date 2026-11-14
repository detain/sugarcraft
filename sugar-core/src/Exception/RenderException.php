<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

use SugarCraft\Core\Lang;

/**
 * Exception for render-domain faults (SGR state machine errors, cell
 * coordinate problems, frame-budget overflow).
 *
 * Domain exception under the family base; not thrown by sugar-core
 * itself today (finding sugar-core-023 honesty note, sc-22): the
 * render layer's current faults surface through their own guards, and
 * this named type exists for consumers classifying render failures.
 * If a render site ever adopts it, the SPL-parenting law in
 * {@see \SugarCraft\Core\Exception\RuntimeException} governs whether
 * it stays here or moves to the runtime lineage.
 */
final class RenderException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create from a translatable key and parameters.
     *
     * @param string $key   Translation key in Lang format
     * @param array<string, mixed> $params Key-value pairs for placeholder substitution
     */
    public static function fromKey(string $key, array $params = []): self
    {
        return new self(Lang::t($key, $params));
    }
}
