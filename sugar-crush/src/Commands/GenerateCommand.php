<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

/**
 * argv-style parser behind the `/generate` console command (crush-media W2.3).
 *
 * The command builds the EXACT inputSchema-named argument map the
 * {@see \SugarCraft\Crush\Tools\BuiltIn\GenerateImage} tool documents, so the
 * console path and the model path share one implementation and one wire
 * vocabulary (`n_iter` reaches the SD server, never the `--n-iter` flag
 * spelling). Fail-fast by design: an unknown flag, a missing value, a
 * non-numeric numeric, or a malformed `--size` yields a usage-level error
 * string the host command renders as a row — nothing is dialed, nothing
 * throws upward.
 *
 * This class is pure: no transport, no settings, no Chat state. It reads as
 * English and returns a plan the caller trusts.
 */
final class GenerateCommand
{
    /**
     * Flag table: flag => [wire argument name, value kind].
     * `size` is a pseudo-kind split into `width` + `height`.
     */
    public const FLAGS = [
        '--negative' => ['negative_prompt', 'string'],
        '--steps' => ['steps', 'int'],
        '--cfg' => ['cfg_scale', 'float'],
        '--size' => ['size', 'size'],
        '--seed' => ['seed', 'int'],
        '--sampler' => ['sampler_name', 'string'],
        '--scheduler' => ['scheduler', 'string'],
        '--batch' => ['batch_size', 'int'],
        '--n-iter' => ['n_iter', 'int'],
        '--save' => ['save_to_disk', 'bool'],
    ];

    /** Short one-line synopsis used for the bare-command usage answer. */
    public const USAGE = '/generate <prompt> [--negative TEXT] [--steps N] [--cfg N] [--size WxH] [--seed N] [--sampler NAME] [--scheduler NAME] [--batch N] [--n-iter N] [--save]';

    private function __construct()
    {
    }

    /**
     * Parse quote-split argv tokens (everything after `/generate`).
     *
     * @param list<string> $tokens
     * @return array{args: array<string, mixed>, help: bool, error: ?string}
     *   `args` is the tool-shaped argument map (inputSchema names) and is
     *   empty whenever `error` is set; `help` short-circuits to the long
     *   flag block; `error` carries the usage-level refusal sentence.
     */
    public static function parse(array $tokens): array
    {
        $args = [];
        $help = false;
        $positionals = [];

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '--help' || $token === '-h') {
                $help = true;
                continue;
            }

            if (!self::isFlag($token)) {
                if (str_starts_with($token, '--')) {
                    return self::refusal(sprintf('Unknown flag "%s". %s', $token, self::USAGE));
                }
                $positionals[] = $token;
                continue;
            }

            [$wire, $kind] = self::FLAGS[$token];

            if ($kind === 'bool') {
                $args[$wire] = true;
                continue;
            }

            $value = $tokens[$i + 1] ?? null;
            if ($value === null || self::isFlag($value)) {
                return self::refusal(sprintf('The flag %s needs a value.', $token));
            }
            $i++;

            $failure = self::apply($wire, $kind, $value, $args);
            if ($failure !== null) {
                return self::refusal($failure);
            }
        }

        if ($help) {
            return ['args' => [], 'help' => true, 'error' => null];
        }

        $prompt = trim(implode(' ', $positionals));
        if ($prompt === '') {
            return self::refusal('A prompt is required, for example: /generate "a watercolor fox at dawn" --steps 20');
        }

        $args = ['prompt' => $prompt] + $args;

        return ['args' => $args, 'help' => false, 'error' => null];
    }

    /** The bare-command answer (Wave 3.1 opens the interactive form here). */
    public static function usage(): string
    {
        return self::USAGE . "\nAdd --help for the full flag table.";
    }

    /** Long, flag-per-line help block rendered from the flag table itself. */
    public static function help(): string
    {
        $lines = [self::USAGE, '', 'Flags:'];
        foreach (self::FLAGS as $flag => [$wire, $kind]) {
            $lines[] = sprintf('  %-12s %s (%s)', $flag, $wire, $kind);
        }

        return implode("\n", $lines);
    }

    /**
     * Is this token a flag of ours? A leading double dash that the table
     * does not know is reported as unknown by the caller loop.
     */
    private static function isFlag(string $token): bool
    {
        return isset(self::FLAGS[$token]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function apply(string $wire, string $kind, string $value, array &$args): ?string
    {
        if ($kind === 'string') {
            $args[$wire] = $value;

            return null;
        }

        if ($kind === 'int') {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if ($validated === false) {
                return sprintf('--%s expects a whole number, got "%s".', self::flagFor($wire), $value);
            }
            $args[$wire] = $validated;

            return null;
        }

        if ($kind === 'float') {
            if (!is_numeric($value)) {
                return sprintf('--%s expects a number, got "%s".', self::flagFor($wire), $value);
            }
            $args[$wire] = $value + 0.0;

            return null;
        }

        // kind === 'size': WxH, split into the tool's width + height.
        if (!preg_match('/^(\d+)x(\d+)$/i', $value, $matches)) {
            return sprintf('--size expects WIDTHxHEIGHT like 1024x1024, got "%s".', $value);
        }
        $args['width'] = (int) $matches[1];
        $args['height'] = (int) $matches[2];

        return null;
    }

    /** Reverse-map a wire name to its CLI spelling for error messages. */
    private static function flagFor(string $wire): string
    {
        foreach (self::FLAGS as $flag => [$name, $_kind]) {
            if ($name === $wire) {
                return ltrim($flag, '-');
            }
        }

        return $wire;
    }

    /**
     * @return array{args: array<string, mixed>, help: bool, error: string}
     */
    private static function refusal(string $error): array
    {
        return ['args' => [], 'help' => false, 'error' => $error];
    }
}
