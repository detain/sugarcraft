<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests\Support;

use SugarCraft\Ansi\Parser\Handler;

/**
 * Records every {@see Handler} call as a normalised string so two handlers can
 * be compared for byte-identical dispatch behaviour.
 *
 * The point of the flat string log is the non-implementor regression: a plain
 * `Handler` must see EXACTLY what it saw before the
 * {@see \SugarCraft\Ansi\Parser\SubparamsAwareHandler} capability existed, so
 * the comparison is "log A === log B" rather than a per-argument assertion that
 * could quietly overlook a new argument.
 */
class DispatchRecorder implements Handler
{
    /** @var list<string> one entry per {@see Handler} call, in arrival order */
    public array $calls = [];

    /**
     * The raw `$params` arrays handed to {@see Handler::csiDispatch()}, kept
     * alongside the string log so a test can compare a list *length* against the
     * pushed flag list without re-parsing this class's own formatting.
     *
     * @var list<list<int>>
     */
    public array $csiParams = [];

    public function printChar(string $rune): void
    {
        $this->emit('print:' . bin2hex($rune));
    }

    public function execute(int $byte): void
    {
        $this->emit('execute:' . $byte);
    }

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $this->csiParams[] = $params;
        $this->emit(sprintf(
            'csi:%s:%s:%d:%d',
            chr($final),
            self::list($params),
            $prefix,
            $intermediate,
        ));
    }

    public function escDispatch(int $final, int $intermediate): void
    {
        $this->emit(sprintf('esc:%s:%d', chr($final), $intermediate));
    }

    public function oscDispatch(string $data): void
    {
        $this->emit('osc:' . $data);
    }

    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
    {
        $this->emit(sprintf(
            'dcs:%s:%s:%d:%d:%s',
            chr($final),
            self::list($params),
            $prefix,
            $intermediate,
            $data,
        ));
    }

    public function sosPmApcDispatch(string $kind, string $data): void
    {
        $this->emit(sprintf('sosPmApc:%s:%s', $kind, $data));
    }

    /**
     * Append one dispatch line. Overridden by the capable recorder to mirror the
     * same line into its interleaved timeline; kept protected so a subclass can
     * observe every handler call without duplicating this formatting.
     */
    protected function emit(string $entry): void
    {
        $this->calls[] = $entry;
    }

    /**
     * @param list<int> $values
     */
    protected static function list(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR);
    }
}
