<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * Records every action dispatched by the parser as a flat log of
 * `['type' => ..., 'detail' => ...]` rows. Used in tests to assert the
 * exact sequence of actions produced by feeding a byte stream.
 *
 * @see Mirrors charmbracelet/x/ansi DebugHandler
 */
final class DebugHandler implements Handler
{
    /** @var list<array{type: string, detail: mixed}> */
    public array $log = [];

    public function printChar(string $rune): void
    {
        $this->log[] = ['type' => 'print', 'detail' => $rune];
    }

    public function execute(int $byte): void
    {
        $this->log[] = ['type' => 'execute', 'detail' => $byte];
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->log[] = ['type' => 'csi', 'detail' => [
            'final' => $final,
            'params' => $params,
            'prefix' => $prefix,
            'intermediate' => $intermediate,
        ]];
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        $this->log[] = ['type' => 'esc', 'detail' => [
            'final' => $final,
            'intermediate' => $intermediate,
        ]];
    }

    public function oscDispatch(string $data): void
    {
        $this->log[] = ['type' => 'osc', 'detail' => $data];
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->log[] = ['type' => 'dcs', 'detail' => [
            'final' => $final,
            'params' => $params,
            'prefix' => $prefix,
            'intermediate' => $intermediate,
            'data' => $data,
        ]];
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->log[] = ['type' => $kind, 'detail' => $data];
    }

    /**
     * Return only the entries of a given type.
     *
     * @return list<array{type: string, detail: mixed}>
     */
    public function filter(string $type): array
    {
        return array_values(array_filter($this->log, static fn ($e) => $e['type'] === $type));
    }
}
