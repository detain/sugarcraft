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
     *
     * THE LAST TWO GUARD THE FILES THAT DECIDE WHAT THIS SESSION IS ALLOWED TO
     * DO. `~/.sugar-crush/config.json` carries `permissionMode`,
     * `permissionRules` and the `trustedProjectHooks` allowlist;
     * `.sugar-crush/hooks.yaml` is shell commands run on tool calls; and
     * `.sugar-crush/agents/*.md` presets carry their own `permissionMode:` and
     * `tools:` (see {@see \SugarCraft\Crush\Agents\AgentPreset}). Before
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hookFiles()} read them, writing
     * them was inert; now that they are live, an unprompted write to
     * `trustedProjectHooks` is the model granting itself the trust the gate
     * exists to withhold — measured end-to-end, in the shipped
     * bypass-permissions default, as one Bash call plus one provider switch.
     * So the deny fires HERE, ahead of {@see PermissionGateHook}, in every
     * permission mode.
     *
     * The rest of `~/.sugar-crush` (the session database, the memory store) is
     * deliberately NOT listed: it is per-user data rather than policy, and
     * nothing there decides what this session may do.
     */
    public const DEFAULT_PROTECTED_PATTERNS = [
        '/(^|[\s\/])\.env(\s|$)/',
        '/composer\.json\b/',
        '/composer\.lock\b/',
        '/\.git\/config\b/',
        '/(^|\/)config\/[^\s]*\.php\b/',
        ...self::WRITE_ONLY_PATTERNS,
    ];

    /**
     * The patterns that guard POLICY rather than SECRETS, and are therefore
     * enforced against `Edit`/`Write` (and the `Bash` command text) but NOT
     * against `Read` — see {@see execute()}.
     *
     * READING A POLICY FILE GRANTS NO CAPABILITY. The other defaults are
     * secrets, where refusing `Read` IS the point: `.env` read out into the
     * transcript is the credential leaked. These two decide what the session
     * may do, and a decision is changed by WRITING it. Denying the read bought
     * no containment and cost the ordinary inspection — "why is my reviewer
     * preset behaving oddly" is answered by opening
     * `.sugar-crush/agents/reviewer.md`, and this repo's own tracked
     * `.sugar-crush/config.json` (`worktreeCleanupPeriodDays` and friends, read
     * by {@see \SugarCraft\Crush\Agents\WorktreeConfig}) is not a policy file at
     * all: the pattern is unanchored, so it matches a PROJECT-scoped
     * `.sugar-crush/config.json` as readily as the `~/`-scoped one the
     * trust list actually lives in.
     *
     * THE LOOKAHEAD IS DELIBERATELY ASYMMETRIC. `(?![\w.-])` is an allow-list
     * of the bytes that make a longer name a DIFFERENT file — `config.dev.json`,
     * `hooks.yaml.dist`, `config.json.bak`, `config.jsonx` all pass it and are
     * not protected. Anything else following the name still matches, so
     * `hooks.yaml~` is denied. That is the fail-closed direction of an
     * incomplete list and is left as-is: a byte NOT in the allow-list is a
     * spelling nobody has shown to be a separate file, and enumerating every
     * editor's backup suffix here would be the whack-a-mole version of the same
     * guess.
     */
    public const WRITE_ONLY_PATTERNS = [
        '#(^|/)\.sugar-crush/(hooks\.yaml|config\.json)(?![\w.-])#',
        '#(^|/)\.sugar-crush/agents/#',
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

    /**
     * THE TWO HALVES OF THIS ARE NOT EQUALLY STRONG, and pretending otherwise
     * would be the finding rather than the fix.
     *
     * The `file_path` half is airtight for what it covers: the path is matched
     * as spelled AND canonicalised (see {@see pathSpellings()}), so a relative
     * spelling, a `..` walk, or a symlink pointing at the config file all meet
     * the same pattern the absolute path does. WHAT IT DOES NOT COVER IS A
     * HARDLINK: `realpath()` resolves symlinks and has nothing to resolve for a
     * second directory entry pointing at the same inode, so `ln
     * ~/.sugar-crush/config.json ./notes.json` is a name this cannot tell from
     * an unrelated file. Same reason the read side does not lean on this hook
     * (see below); "airtight" is about spellings, not about inodes.
     *
     * The `command` half is BEST-EFFORT DEFENCE IN DEPTH and nothing more. It
     * is a substring match against one shell string, and a shell has unbounded
     * ways to name a file without spelling it: `$HOME` expansion, `cd
     * ~/.sugar-crush && … > hooks.yaml`, `sh -c 'e''cho'`, a path built in a
     * variable, `tee`, `python -c`, a heredoc. It catches the obvious
     * `>> ~/.sugar-crush/config.json` and is worth having for that; it is not a
     * containment boundary, and the reason the read side does not depend on it
     * is {@see \SugarCraft\Crush\Cli\Bootstrap::trustedProjectHookRoots()},
     * which freezes the trust list for the process so a write that slips
     * through here still cannot take effect in the session that made it.
     *
     * `Read` IS JUDGED AGAINST A SHORTER LIST — everything except
     * {@see self::WRITE_ONLY_PATTERNS}, which is where the argument for that
     * lives. `Bash` is not: one shell string does not say whether it is about
     * to read the file or write it.
     */
    public function execute(HookContext $context): HookResult
    {
        $toolName = ucfirst(strtolower($context->toolName));
        $inputs = match ($toolName) {
            'Bash' => [$context->toolArgs['command'] ?? ''],
            'Edit', 'Write', 'Read' => self::pathSpellings($context->toolArgs['file_path'] ?? null),
            default => [$context->toolInput],
        };

        foreach ($this->protectedPatterns as $pattern) {
            if ($toolName === 'Read' && \in_array($pattern, self::WRITE_ONLY_PATTERNS, true)) {
                continue;
            }

            foreach ($inputs as $input) {
                if (\is_string($input) && preg_match($pattern, $input) === 1) {
                    return HookResult::deny(
                        "This hook prevents modification of files matching: $pattern"
                    );
                }
            }
        }

        return HookResult::allow();
    }

    /**
     * Every spelling of $path a pattern should get a chance to match: the one
     * the tool call gave, and the canonical one.
     *
     * RESOLVED, BECAUSE A PATTERN MATCHES TEXT AND A WRITE TOUCHES AN INODE.
     * `ln -s ~/.sugar-crush/config.json ./notes.json` makes those two different
     * strings for one file, and the raw spelling is the half that does not
     * match. `realpath()` answers false for a file that does not exist yet —
     * which is most of what `Write` does — so the PARENT is canonicalised and
     * the basename re-attached, which is what catches a link pointing at the
     * config DIRECTORY as well as one pointing at the file.
     *
     * @return list<string> at least one entry, so the caller's loop is uniform
     */
    private static function pathSpellings(mixed $path): array
    {
        if (!\is_string($path) || $path === '') {
            return [''];
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            $parent = realpath(\dirname($path));
            $resolved = $parent === false ? false : rtrim($parent, '/') . '/' . basename($path);
        }

        return $resolved === false || $resolved === $path ? [$path] : [$path, $resolved];
    }
}
