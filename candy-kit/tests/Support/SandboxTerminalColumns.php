<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests\Support;

/**
 * Makes width-dependent assertions independent of the runner's environment.
 *
 * The kit presenters' omitted `$width` now resolves through
 * {@see \SugarCraft\Kit\Internal\WidthProbe::columns()} — an exported
 * `COLUMNS` (or a superglobal copy of it) on the dev/CI box would otherwise
 * silently re-flow every default-width golden and every "80 cells" pin.
 * Snapshot in `setUp()`, silence or pin, restore in `tearDown()` — the same
 * discipline {@see \SugarCraft\Kit\Tests\DefaultThemeTest} already applies
 * to the theme-detection variables.
 */
trait SandboxTerminalColumns
{
    /** @var array{env: string|false, _ENV: mixed, _SERVER: mixed}|null */
    private ?array $savedColumns = null;

    /** Snapshot the live value and hide the terminal width (probe → fallback). */
    private function sandboxTerminalColumns(): void
    {
        $this->snapshotTerminalColumns();
        $this->writeTerminalColumns(null);
    }

    /** Pin the advertised terminal width for this test (int or numeric string). */
    private function pinTerminalColumns(int|string $columns): void
    {
        $this->snapshotTerminalColumns();
        $this->writeTerminalColumns((string) $columns);
    }

    private function restoreTerminalColumns(): void
    {
        if ($this->savedColumns === null) {
            return;
        }
        $saved = $this->savedColumns;
        $this->savedColumns = null;
        if ($saved['env'] === false) {
            putenv('COLUMNS');
        } else {
            putenv('COLUMNS=' . $saved['env']);
        }
        foreach (['_ENV' => $saved['_ENV'], '_SERVER' => $saved['_SERVER']] as $super => $value) {
            if ($value === '__ABSENT__') {
                unset(${$super}['COLUMNS']);
            } else {
                ${$super}['COLUMNS'] = $value;
            }
        }
    }

    private function snapshotTerminalColumns(): void
    {
        if ($this->savedColumns !== null) {
            return; // already armed this test — keep the original snapshot
        }
        $this->savedColumns = [
            'env'     => getenv('COLUMNS'),
            '_ENV'    => array_key_exists('COLUMNS', $_ENV) ? $_ENV['COLUMNS'] : '__ABSENT__',
            '_SERVER' => array_key_exists('COLUMNS', $_SERVER) ? $_SERVER['COLUMNS'] : '__ABSENT__',
        ];
    }

    private function writeTerminalColumns(?string $columns): void
    {
        if ($columns === null) {
            putenv('COLUMNS');
            unset($_ENV['COLUMNS'], $_SERVER['COLUMNS']);
            return;
        }
        putenv('COLUMNS=' . $columns);
        $_ENV['COLUMNS']    = $columns;
        $_SERVER['COLUMNS'] = $columns;
    }
}
