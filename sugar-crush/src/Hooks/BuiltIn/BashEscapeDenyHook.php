<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * OPT-IN guard that denies Bash commands referencing filesystem paths outside a
 * jail root — the closest a PreToolUse hook can get to the PathJail that
 * {@see \SugarCraft\Crush\Tools\BuiltIn\Edit} et al. enforce, given that Bash
 * itself is intentionally un-jailed.
 *
 * IMPORTANT: this is a best-effort HEURISTIC, NOT a security boundary. It only
 * inspects literal path-like tokens; `$HOME/../..`, `$(pwd)/..`, command
 * substitution, symlinks, and here-docs all evade it. For real containment run
 * the process in a jail/container. It is deliberately NOT registered by
 * {@see \SugarCraft\Crush\Hooks\HookManager::registerBuiltIns()} — a caller must
 * construct it with a root and register it explicitly.
 */
final readonly class BashEscapeDenyHook implements HookInterface
{
    public function __construct(
        private string $root,
    ) {}

    public function name(): string
    {
        return 'bash-escape-deny';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^Bash$';
    }

    public function execute(HookContext $context): HookResult
    {
        $command = $context->toolArgs['command'] ?? '';
        $root = realpath($this->root) ?: $this->root;

        foreach ($this->pathTokens($command) as $token) {
            $resolved = $this->lexicalResolve($root, $token);
            if (!$this->within($root, $resolved)) {
                return HookResult::deny(
                    "This hook denies Bash paths outside the workspace root: $token"
                );
            }
        }

        return HookResult::allow();
    }

    /**
     * Best-effort tokeniser: split on shell whitespace and keep only tokens
     * that look like a filesystem path escaping (or possibly escaping) the
     * root — absolute paths and any token containing a `..` component. Plain
     * relative tokens (`file.txt`, `src/App.php`) stay inside the root by
     * construction and are ignored.
     *
     * @return list<string>
     */
    private function pathTokens(string $command): array
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];

        $paths = [];
        foreach ($tokens as $token) {
            $token = trim($token, "\"'");
            if ($token === '' || str_starts_with($token, '-')) {
                continue; // option flag, not a path
            }
            if (str_starts_with($token, '/') || $this->hasDotDot($token)) {
                $paths[] = $token;
            }
        }

        return $paths;
    }

    private function hasDotDot(string $path): bool
    {
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Lexically resolve a token against the root WITHOUT touching the
     * filesystem (the target may not exist yet), collapsing `.` and `..`.
     */
    private function lexicalResolve(string $root, string $token): string
    {
        $base = str_starts_with($token, '/') ? $token : $root . '/' . $token;

        return $this->normalize($base);
    }

    private function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $stack = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $stack);
    }

    private function within(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
