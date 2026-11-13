<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Format\CassetteLoader;

/**
 * `candy-vcr diff [--strict] <a.cas> <b.cas>`
 *
 * Compare two cassettes structurally — EVERY header field (version,
 * dimensions, runtime, timestamp mode, env, typing speed, theme, playback
 * speed, font and pixel geometry) and, per event, the kind, the payload and
 * the timestamp. Timestamps compare within 2 ms so the different formats'
 * rounding (absolute `t` at 3 decimals, relative `dt` accumulation) does
 * not read as drift. Exit 0 if identical, 1 if they differ, 2 on a bad
 * invocation.
 *
 * Migration metadata: V1ToV2Migrator injects `_id` (every event) and `_enc`
 * (output events) into payloads. Those keys mark the migration, not the
 * session, so by default a migrated cassette compares equal to its source.
 * Pass --strict to include them in the payload comparison.
 */
final class DiffCommand implements DocumentedCommand
{
    /** Seconds of slack tolerated on event timestamps (format-rounding noise). */
    private const T_TOLERANCE_SECONDS = 0.002;

    /** Payload keys minted by migration, ignored unless --strict. */
    private const MIGRATION_METADATA_KEYS = ['_id', '_enc'];

    public function summary(): string
    {
        return 'Compare two cassettes event-by-event';
    }

    public function usage(): string
    {
        return 'usage: candy-vcr diff [--strict] <a.cas> <b.cas>' . "\n";
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $paths = [];
        $strict = false;
        foreach ($args as $arg) {
            if ($arg === '--strict') {
                $strict = true;
                continue;
            }
            if (str_starts_with($arg, '--')) {
                fwrite($stderr, "candy-vcr diff: unknown option {$arg}\n");
                return 2;
            }
            $paths[] = $arg;
        }
        if (count($paths) !== 2) {
            fwrite($stderr, $this->usage());
            return 2;
        }
        try {
            $loader = new CassetteLoader();
            $a = $loader->load($paths[0]);
            $b = $loader->load($paths[1]);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr diff: {$e->getMessage()}\n");
            return 1;
        }
        CassetteLoader::writeTapeWarnings($loader, $stderr, 'diff');

        $diffs = $this->collectDiffs($a, $b, $strict);
        if ($diffs === []) {
            fwrite($stdout, "cassettes are identical (header fields match; events match kind, payload, and timestamps within 2 ms)\n");
            return 0;
        }
        foreach ($diffs as $line) {
            fwrite($stdout, $line . "\n");
        }
        fwrite($stdout, sprintf("\n%d difference(s)\n", count($diffs)));
        return 1;
    }

    /** @return list<string> */
    private function collectDiffs(Cassette $a, Cassette $b, bool $strict): array
    {
        $diffs = [];

        if ($a->header->version !== $b->header->version) {
            $diffs[] = sprintf('header.v: %d != %d', $a->header->version, $b->header->version);
        }
        if ($a->header->cols !== $b->header->cols || $a->header->rows !== $b->header->rows) {
            $diffs[] = sprintf(
                'header dimensions: %dx%d != %dx%d',
                $a->header->cols,
                $a->header->rows,
                $b->header->cols,
                $b->header->rows,
            );
        }

        // Every remaining scalar header field, compared strictly. cols/rows
        // were handled as one combined "dimensions" row above and `v` as
        // "header.v" for stable historical needles.
        $scalarFields = [
            'created' => static fn (CassetteHeader $h): string => $h->createdAt,
            'runtime' => static fn (CassetteHeader $h): string => $h->runtime,
            'timestampMode' => static fn (CassetteHeader $h): string => $h->timestampMode,
            'typingSpeed' => static fn (CassetteHeader $h): ?float => $h->typingSpeed,
            'theme' => static fn (CassetteHeader $h): ?string => $h->theme,
            'playbackSpeed' => static fn (CassetteHeader $h): ?float => $h->playbackSpeed,
            'fontSize' => static fn (CassetteHeader $h): ?int => $h->fontSize,
            'fontFamily' => static fn (CassetteHeader $h): ?string => $h->fontFamily,
            'widthPx' => static fn (CassetteHeader $h): ?int => $h->widthPx,
            'heightPx' => static fn (CassetteHeader $h): ?int => $h->heightPx,
        ];
        foreach ($scalarFields as $label => $read) {
            $av = $read($a->header);
            $bv = $read($b->header);
            if ($av !== $bv) {
                $diffs[] = sprintf('header.%s: %s != %s', $label, self::render($av), self::render($bv));
            }
        }

        if ($a->header->env !== $b->header->env) {
            $diffs[] = sprintf(
                'header.env: %s != %s',
                self::render($a->header->env),
                self::render($b->header->env),
            );
        }

        $aCount = $a->eventCount();
        $bCount = $b->eventCount();
        if ($aCount !== $bCount) {
            $diffs[] = sprintf('event count: %d != %d', $aCount, $bCount);
        }

        $max = max($aCount, $bCount);
        for ($i = 0; $i < $max; $i++) {
            $aEvent = $a->events[$i] ?? null;
            $bEvent = $b->events[$i] ?? null;

            // $i < max(aCount, bCount) guarantees at least one side exists,
            // but the null-safe reads below keep that visible to the types.
            $aKind = $aEvent?->kind->value ?? 'none';
            $bKind = $bEvent?->kind->value ?? 'none';

            if ($aEvent === null) {
                $diffs[] = sprintf('event[%d]: missing in A, present in B (%s)', $i, $bKind);
                continue;
            }
            if ($bEvent === null) {
                $diffs[] = sprintf('event[%d]: present in A (%s), missing in B', $i, $aKind);
                continue;
            }
            if ($aEvent->kind !== $bEvent->kind) {
                $diffs[] = sprintf(
                    'event[%d]: kind %s != %s',
                    $i,
                    $aKind,
                    $bKind,
                );
            }
            if (abs($aEvent->t - $bEvent->t) > self::T_TOLERANCE_SECONDS) {
                $diffs[] = sprintf(
                    'event[%d]: t differs (%s != %s)',
                    $i,
                    number_format($aEvent->t, 3, '.', ''),
                    number_format($bEvent->t, 3, '.', ''),
                );
            }
            $aPayload = $strict ? $aEvent->payload : self::stripMigrationMetadata($aEvent->payload);
            $bPayload = $strict ? $bEvent->payload : self::stripMigrationMetadata($bEvent->payload);
            if ($aPayload !== $bPayload) {
                $diffs[] = sprintf('event[%d] payload differs (%s)', $i, $aKind);
            }
        }

        return $diffs;
    }

    /** @param array<array-key, mixed> $payload @return array<array-key, mixed> */
    private static function stripMigrationMetadata(array $payload): array
    {
        return array_diff_key($payload, array_flip(self::MIGRATION_METADATA_KEYS));
    }

    /** @param mixed $value */
    private static function render($value): string
    {
        if ($value === null) {
            return 'none';
        }
        if (\is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return $json === false ? '{...}' : $json;
        }
        if (\is_string($value)) {
            // Belt for finding 004: diff prints header scalars. Escaping
            // via JSON means no control character can reach the terminal
            // through a diff line (invalid UTF-8 fails the encode and hits
            // the dotting fallback); printable text stays readable.
            $json = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
            if (\is_string($json)) {
                $json = preg_replace('/^"(.*)"$/s', '$1', $json) ?? $json;
                return $json;
            }
            return preg_replace('/[^\x20-\x7e]/', '.', $value) ?? '';
        }
        return (string) $value;
    }
}
