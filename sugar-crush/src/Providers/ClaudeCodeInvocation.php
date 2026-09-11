<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Support\ProcessContainment;

/**
 * Encapsulates a Claude Code CLI invocation.
 */
final readonly class ClaudeCodeInvocation
{
    public function __construct(
        private string $claudePath = 'claude',
        private string $configDir = '~/.claude',
        private ?string $sessionId = null,
    ) {}

    public function claudePath(): string
    {
        return $this->claudePath;
    }

    public function configDir(): string
    {
        return $this->configDir;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Build the base command arguments.
     *
     * @return array<string>
     */
    public function baseArgs(): array
    {
        $args = ['--output-format', 'json'];

        if ($this->sessionId !== null) {
            $args[] = '--resume';
            $args[] = $this->sessionId;
        }

        return $args;
    }

    /**
     * Build headless print mode arguments.
     *
     * @param array<string, mixed> $options
     * @return array<string>
     */
    public function printModeArgs(string $prompt, array $options = []): array
    {
        $args = ['-p', $prompt];
        $args[] = '--output-format';
        $args[] = $options['format'] ?? 'json';

        if ($options['bare'] ?? false) {
            $args[] = '--bare';
        }

        if ($options['continue'] ?? false) {
            $args[] = '--continue';
        }

        if (isset($options['allowedTools'])) {
            $args[] = '--allowedTools';
            $args[] = $options['allowedTools'];
        }

        if (isset($options['systemPrompt'])) {
            $args[] = '--system-prompt';
            $args[] = $options['systemPrompt'];
        }

        if (isset($options['maxBudgetUsd'])) {
            $args[] = '--max-budget-usd';
            $args[] = (string) $options['maxBudgetUsd'];
        }

        if (isset($options['maxTurns'])) {
            $args[] = '--max-turns';
            $args[] = (string) $options['maxTurns'];
        }

        if (isset($options['permissionMode'])) {
            $args[] = '--permission-mode';
            $args[] = $options['permissionMode'];
        }

        return $args;
    }

    /**
     * Execute Claude Code and return the output.
     *
     * @param array<string> $args
     * @param callable(string): void|null $onChunk Called for each chunk in streaming mode
     * @throws ProviderException when the child fails to start (null exitCode) or
     *                           exits non-zero (exitCode carries the shell code).
     *                           It IS-A \RuntimeException — see E664 below.
     */
    public function execute(array $args, ?callable $onChunk = null): string
    {
        $cmd = array_merge([$this->claudePath], $this->baseArgs(), $args);

        // E672/E674: choke-point spec + env; the three auth keys ride as
        // overrides. Empty-string values are dropped by proc_open() itself
        // (measured, recorded in ProcessContainment), so an unset key stays
        // unset rather than reaching the child as ''.
        $process = proc_open(
            ProcessContainment::spawnSpec($cmd),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ProcessContainment::env([
                'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
                'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
                'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
            ])
        );

        if (!is_resource($process)) {
            // E664 (E27(a) family): this execute() is the SECOND Claude Code
            // subprocess site — the provider owns the first two, since retyped
            // in lane Q. Identical pattern: typed throw, exit code null = the
            // child never spawned.
            throw new ProviderException('Failed to start Claude Code process');
        }

        // Write stdin if needed
        fclose($pipes[0]);

        $output = '';
        $errors = '';

        if ($onChunk !== null) {
            // Streaming mode
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false) {
                    break;
                }
                $output .= $chunk;
                $onChunk($chunk);
            }
        } else {
            $output = stream_get_contents($pipes[1]);
        }

        $errors = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && $exitCode !== -1) {
            // E664: the shell exit code now rides as STRUCTURE on
            // ProviderException::$exitCode, never as the exception code, so
            // TransientFailure::statusCode() cannot misread a shell 429 or a
            // 128+signal death as an HTTP status. The message is byte-stable
            // and the class stays a \RuntimeException, so every existing
            // catch keeps its contract; the per-code retry verdict remains
            // with TransientFailure's allow-list (open half of E27(a)).
            throw new ProviderException("Claude Code exited with code $exitCode: $errors", exitCode: $exitCode);
        }

        return $output;
    }
}
