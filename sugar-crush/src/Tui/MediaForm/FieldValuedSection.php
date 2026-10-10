<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

/**
 * The door a {@see Section} opens when its widgets carry wire-keyed values
 * (crush-media W3.4): the form can then hydrate every such section from a
 * flat value map (PNG infotext import, preset load) and read the same map
 * back out (preset save, infotext emit) without either direction going
 * through a `Forms\Form` composite — the sections drive their fields
 * directly, exactly as the shell already does.
 *
 * A section owns its keys: {@see withFieldValues()} ignores entries that are
 * not its rows, so the whole map can be handed to every section at once.
 * Hidden rows (W3.5 toggles) contribute neither direction.
 */
interface FieldValuedSection
{
    /**
     * The section's current widget values keyed by wire name.
     *
     * @return array<string, mixed>
     */
    public function fieldValues(): array;

    /**
     * A section whose widgets answer to `$values` where its keys appear.
     *
     * @param array<string, mixed> $values
     */
    public function withFieldValues(array $values): self;
}
