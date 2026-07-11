<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

final readonly class ProtectFilesHook implements HookInterface
{
    /**
     * Default protected-file patterns applied when the hook is constructed
     * without an explicit list. Each entry is a regex matched against the
     * command / file-path pulled from the tool call.
     */
    public const DEFAULT_PROTECTED_PATTERNS = [
        '/(^|[\s\/])\.env(\s|$)/',
        '/composer\.json\b/',
        '/composer\.lock\b/',
        '/\.git\/config\b/',
        '/(^|\/)config\/[^\s]*\.php\b/',
    ];

    /** @var list<string> */
    private array $protectedPatterns;

    /**
     * @param list<string>|null $protectedPatterns Regex patterns to protect;
     *        null keeps {@see self::DEFAULT_PROTECTED_PATTERNS}. An empty list
     *        is honoured verbatim (protects nothing) — callers that want the
     *        defaults must pass null, not [].
     */
    public function __construct(?array $protectedPatterns = null)
    {
        $this->protectedPatterns = array_values($protectedPatterns ?? self::DEFAULT_PROTECTED_PATTERNS);
    }

    /**
     * Immutable setter: returns a copy guarding the given patterns instead.
     *
     * @param list<string> $protectedPatterns
     */
    public function withProtectedPatterns(array $protectedPatterns): self
    {
        return new self($protectedPatterns);
    }

    /** @return list<string> */
    public function protectedPatterns(): array
    {
        return $this->protectedPatterns;
    }

    public function name(): string
    {
        return 'protect-files';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^(Bash|Edit|Write|Read)$';
    }

    public function execute(HookContext $context): HookResult
    {
        $toolName = ucfirst(strtolower($context->toolName));
        $input = match ($toolName) {
            'Bash' => $context->toolArgs['command'] ?? '',
            'Edit', 'Write', 'Read' => $context->toolArgs['file_path'] ?? '',
            default => $context->toolInput,
        };

        foreach ($this->protectedPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return HookResult::deny(
                    "This hook prevents modification of files matching: $pattern"
                );
            }
        }

        return HookResult::allow();
    }
}
