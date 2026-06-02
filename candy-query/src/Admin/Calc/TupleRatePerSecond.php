<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Computes per-second rates for tuple-structured status variables.
 *
 * Some status variables store comma-separated key:value pairs. This class
 * computes rates for individual tuple components using precompiled closures.
 *
 * @see Mirrors charmbracelet/lazysql TupleRatePerSecond
 */
final class TupleRatePerSecond
{
    /** @var \Closure(array<string,string>, array<string,string>, float): array<string, float> */
    private readonly \Closure $compute;

    /** @var array<string, float> */
    private array $lastTuples = [];
    private bool $initialized = false;

    public function __construct(
        private readonly string $key,
        private readonly string $separator = ',',
    ) {
        $key = $this->key;
        $sep = $this->separator;
        $this->compute = \Closure::fromCallable(
            /** @param array<string,string> $current @param array<string,string> $previous @param float $elapsed @return array<string, float> */
            static function (array $current, array $previous, float $elapsed) use ($key, $sep): array {
                if ($elapsed <= 0) {
                    return [];
                }
                $newVal = $current[$key] ?? null;
                $oldVal = $previous[$key] ?? null;
                if ($newVal === null || $oldVal === null) {
                    return [];
                }
                $newTuples = self::parseTuples($newVal, $sep);
                $oldTuples = self::parseTuples($oldVal, $sep);
                $rates = [];
                foreach ($newTuples as $k => $newNum) {
                    $oldNum = $oldTuples[$k] ?? null;
                    if ($oldNum === null) {
                        continue;
                    }
                    $delta = $newNum - $oldNum;
                    if ($delta < 0) {
                        $delta = 0;
                    }
                    $rates[$k] = $delta / $elapsed;
                }
                return $rates;
            },
        );
    }

    /**
     * Compute tuple rates using precompiled closure.
     *
     * @param array<string, string> $current
     * @param array<string, string> $previous
     * @param float $elapsed
     * @return array<string, float>
     */
    public function compute(array $current, array $previous, float $elapsed): array
    {
        return ($this->compute)($current, $previous, $elapsed);
    }

    /**
     * Update internal tuple state from a snapshot.
     *
     * @param array<string, string> $snapshot
     */
    public function updateLast(array $snapshot): void
    {
        $val = $snapshot[$this->key] ?? null;
        if ($val !== null) {
            $this->lastTuples = self::parseTuples($val, $this->separator);
            $this->initialized = true;
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * @return array<string, float>
     */
    public function lastTuples(): array
    {
        return $this->lastTuples;
    }

    /**
     * Parse tuple string into key => numeric value map.
     *
     * @return array<string, float>
     */
    private static function parseTuples(string $input, string $separator): array
    {
        $out = [];
        foreach (explode($separator, $input) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $colonPos = strpos($pair, ':');
            if ($colonPos === false) {
                continue;
            }
            $k = trim(substr($pair, 0, $colonPos));
            $v = trim(substr($pair, $colonPos + 1));
            if ($k === '' || !is_numeric($v)) {
                continue;
            }
            $out[$k] = (float) $v;
        }
        return $out;
    }
}
