<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\ShellWords;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

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
     *
     * `composer.json` / `composer.lock` USED TO BE LISTED and are not any more.
     * They are neither secrets (both are committed) nor policy (nothing in them
     * decides what this session may do), and the entries were unanchored
     * substrings judged against Read paths and whole Bash strings alike, so
     * they refused `grep -c . candy-core/composer.json`, a `Read` of any
     * manifest, and every `for d in candy-*; do [ -f "$d/composer.json" ]` loop — in
     * a PHP monorepo, the most ordinary inspection there is. A model that
     * wanted to abuse a manifest's `scripts` already holds Bash, so the deny
     * contained nothing while blocking the audit and dependency work the
     * agent is for.
     *
     * THE `.env` PATTERN USED TO BE `/(^|[\s\/])\.env(\s|$)/` (audit F-J2), and
     * both of its boundaries were the bypass. Wanting whitespace or the end of
     * the string AFTER the name let `cat .env;true`, `cat .env|x` and
     * `cat .env>&2` through; wanting whitespace or `/` BEFORE it let
     * `cat ".env"`, `cat '.env'`, `cat <.env` and `--env-file=.env` through;
     * and `.env.local` / `.env.production` — where frameworks put the real
     * credentials — were never covered at all. Both boundaries are now
     * lookarounds over the same `[\w.-]` "this byte makes it a different name"
     * class {@see self::WRITE_ONLY_PATTERNS} uses, so any punctuation a shell or
     * a quote puts next to the name still matches. What the family covers, as a
     * deliberate list rather than an accident of a regex:
     *
     *  - DENIED: `.env`, `.env.<anything>` (`.env.local`, `.env.production`,
     *    `.env.bak`, `.env.swp` — a backup of the secret is the secret), and
     *    `.envrc`. direnv's `.envrc` is the same thing under another name:
     *    `export AWS_SECRET_ACCESS_KEY=…` is what people put in it, and it is
     *    as often gitignored as `.env` is. A committed `.envrc` that only says
     *    `use flake` costs a refused Read; a leaked one costs the key.
     *  - ALLOWED: the committed templates `.env.example`, `.env.sample`,
     *    `.env.dist`, `.env.template`, `.env.tpl` (and `.env.<stage>.example`,
     *    `.envrc.example`). They exist precisely so they can be read and are
     *    the first file an agent opens to learn what a project needs.
     *  - NOT A `.env` AT ALL: a name where `.env` is glued to a word before it
     *    (`foo.env.example`, `process.env.API_KEY`, `dotenv.php`), or followed
     *    by more name (`.environment`, `.env_old`, `.env-local`) — the
     *    lookbehind/lookahead are what keep `grep -rn process.env src` usable.
     *    Nor a `.env/` DIRECTORY: `python -m venv .env` is a common spelling of
     *    a virtualenv, and `.env/bin/python` holds no secret. `prod.env` is
     *    deliberately out of scope for the same reason `process.env` is: the
     *    name shape cannot tell them apart.
     *
     * KEY MATERIAL (step 0.14-c), read- and write-denied like `.env`:
     *
     *  - `*.pem` and `*.key`, any case: a TLS or signing private key is the
     *    credential itself. The name must have a stem (`server.key`,
     *    `deploy.PEM`), because a BARE `.key` is far more often jq's or a
     *    template's property path (`jq .key`) than a dotfile; a glob stem
     *    (`*.pem`, `?.key`) counts, so `Glob **\/*.key` and `Grep
     *    include=*.pem` are refused as statements of intent. Public
     *    certificates are `.pem` too (`ca.pem`) and are refused all the same:
     *    the extension cannot tell a chain from a key, and the fail-closed
     *    reading costs a `Read` of a public file. In `Bash` a dotted property
     *    spelled like a file (`grep -rn item.key src`) is refused as well —
     *    the text-match cost the `.env` family already pays for
     *    `process.env`-free spellings; the `Grep` tool's `pattern` is not
     *    judged, so the same search through it still works.
     *  - SSH identities `id_rsa`, `id_dsa`, `id_ecdsa`, `id_ed25519` (and
     *    their `_sk`/suffixed variants such as `id_rsa_work`), EXCEPT the
     *    `.pub` half, which exists to be shared.
     */
    public const DEFAULT_PROTECTED_PATTERNS = [
        '/(?<![\w.-])\.env(?:rc)?(?![\w\/-])(?!(?:\.[\w-]+)*\.(?:example|sample|dist|template|tpl)(?![\w.-]))/',
        '/[\w*?-]\.(?:pem|key)(?![\w.-])/i',
        '/(?<![\w.-])id_(?:rsa|dsa|ecdsa|ed25519)[\w.-]*(?<!\.pub)(?![\w.-])/',
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
     * transcript is the credential leaked. The `.sugar-crush` entries decide
     * what the session may do, and a decision is changed by WRITING it. Denying the read bought
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
     *
     * `.git/hooks/` AND `.git/info/` (audit F-J4) ARE POLICY OF A DIFFERENT
     * KIND: code and rules that run OUTSIDE this session. A file written to
     * `.git/hooks/pre-commit` executes on the user's next `git commit` — in
     * their own shell, under no sugar-crush mode at all — so one injected
     * `cp ./payload.sh ./.git/hooks/pre-commit` was persistence that outlived
     * the session that planted it, and before this entry it was allowed in
     * `bypass-permissions` (the shipped default) and auto-run under
     * `accept-edits`. `.git/info/` rides along because `info/attributes` can
     * route a path through a `filter`/`diff` driver and `info/exclude` decides
     * what `git status` shows the user. Write-only, like the rest of this
     * list: reading a hook script grants nothing, so `Read`/`Grep` of one stays
     * allowed. `Bash` is judged against every pattern (see {@see execute()}),
     * which means `ls .git/hooks` and `cat .git/hooks/pre-commit` are refused
     * too — accepted as the fail-closed cost, since a shell string cannot say
     * which way it is about to touch the file, and `Read` answers the same
     * question.
     *
     * The shape: `(?<![\w-])` so `./.git/hooks`, `".git/hooks` and
     * `--x=.git/hooks` all count while a bare `repo.git/hooks` does not (a
     * bare repository's hooks run on a push TO it, not on this user's next
     * commit); the `/` straight after `.git` keeps `.github/` out; `/+(?:\./+)*`
     * so `.git//hooks` and `.git/./hooks` are the same directory they are to
     * the kernel; `(?![\w.-])` so `hooks-sample/` is not `hooks/`; and `i`
     * because on a case-insensitive filesystem (macOS, Windows) `.GIT/HOOKS`
     * IS the hooks directory — on Linux the widening costs a refusal of a
     * name nobody uses.
     *
     * KNOWN LIMIT — `git config` writes `.git/config` WITHOUT NAMING IT.
     * `git config core.hooksPath ./evil` redirects every hook to a directory
     * no pattern here covers, and `core.fsmonitor`, `core.pager`,
     * `diff.external` and filter drivers name a program the same way. A
     * key-by-key pattern list would be the whack-a-mole this docblock already
     * refuses for backup suffixes, and the honest boundary is the permission
     * MODE: `git config …` is not a scoped write, so `accept-edits` and
     * `default` prompt for it; `bypass-permissions` does not, by definition.
     * Nor is `.git/config` itself in this list: it sits in
     * {@see self::DEFAULT_PROTECTED_PATTERNS} proper and is refused read-side
     * too.
     *
     * THE REST OF THE POLICY SURFACE (step 0.8). `hooks.yaml`, `config.json`
     * and `agents/` were never the whole of what decides a session's powers,
     * so the agent could still grant itself the rest by writing it:
     *
     *  - `.sugar-crush/settings.json` and `settings.local.json` — the layered
     *    settings tiers (`allowedTools`, `instructions`, `statusLine`, which
     *    is a COMMAND run on a timer), user and project scope alike, since the
     *    pattern is unanchored the way the `config.json` one is.
     *  - `.mcp.json` — the server list a trusted project launches, i.e. which
     *    programs start next session. Bounded on both sides by the
     *    "different name" class, so `foo.mcp.json` and `.mcp.json.example`
     *    are other files. An `mcp__*` call whose free text names `.mcp.json`
     *    is refused too — the fail-closed cost {@see inputsFor()} already
     *    names for `.env`.
     *  - `.sugar-crush/skills`, `commands`, `rules` and `workflows` — prompt
     *    text the next session treats as the operator's own instructions,
     *    slash commands that run shell, and PHP workflow files that execute
     *    when invoked: written once, they outlive the session that planted
     *    them, the `.git/hooks/` argument above. The directory name itself is
     *    matched (`(?![\w.-])`, not a trailing `/`), so `mv x
     *    .sugar-crush/skills` is refused as well as a write inside it.
     *
     * The cost is real and accepted: `cat .mcp.json` and `ls
     * .sugar-crush/skills` in Bash are refused like `cat .git/hooks/x` is,
     * and editing a project skill now needs the human (or a gated mode's
     * prompt once step 0.8b moves these to always-Ask). `Read`, `Grep` and
     * `Glob` of all of them stay allowed — reading a policy grants nothing.
     * The `.claude/` and `.opencode/` trees sugar-crush also imports skills
     * and agents from are NOT listed: they are other tools' configuration,
     * which other agents edit legitimately, and widening onto them is a
     * decision for 0.8b's Ask path rather than a silent deny here.
     */
    public const WRITE_ONLY_PATTERNS = [
        '#(^|/)\.sugar-crush/(hooks\.yaml|config\.json)(?![\w.-])#',
        '#(^|/)\.sugar-crush/agents/#',
        '#(?<![\w-])\.git/+(?:\./+)*(?:hooks|info)(?![\w.-])#i',
        '#(^|/)\.sugar-crush/settings(?:\.local)?\.json(?![\w.-])#',
        '#(?<![\w.-])\.mcp\.json(?![\w.-])#',
        '#(^|/)\.sugar-crush/(?:skills|commands|rules|workflows)(?![\w.-])#',
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

    /**
     * EVERY TOOL THAT CAN PUT A FILE'S BYTES INTO THE TRANSCRIPT, not just the
     * four that used to be listed (audit F-J2). The matcher was
     * `^(Bash|Edit|Write|Read)$`, so `Grep pattern="=" path="."
     * include_ignored=true` — classed read-only and allowed in every mode —
     * printed `.env:1:DB_PASSWORD=…` without this hook ever being asked.
     *
     *  - `Grep`, `Lsp`: read file contents (Lsp's hover/definition answers
     *    quote the file it was pointed at).
     *  - `Glob`: lists names only, so a match is not a leak — it is judged so
     *    that `Glob pattern=**\/.env*` is refused as a statement of intent, the
     *    same way `Read .env` is, rather than answered with the paths a
     *    follow-up call would need. Glob's OUTPUT is not screened: a `**\/*`
     *    walk lists a `.env` among everything else, and naming a file is not
     *    reading it.
     *  - `mcp__*`: an MCP server's semantics are opaque here — a filesystem
     *    server's `read_file {"path": ".env"}` is a Read by another name.
     *
     * Matched case-insensitively ({@see \SugarCraft\Crush\Hooks\HookConfig::pattern()}
     * adds `i`), so `bash`/`grep` spellings are caught as before. WebFetch,
     * Task and Skill are deliberately absent: their string arguments are URLs
     * and prose, where `.env` is a word rather than a file this process opens.
     */
    public function matcher(): string
    {
        return '^(Bash|Edit|Write|Read|Grep|Glob|Lsp|mcp__.*)$';
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
     * WHAT "THE COMMAND" MEANS FOR A PATTERN (audit F-J2). Each pattern runs
     * against the raw string AND what bash will actually hand the process — the
     * quote-removed words and redirection targets from
     * {@see \SugarCraft\Crush\Permissions\ShellWords}. The raw string alone
     * let `cat .git/"config"` and `cat .e''nv` through, because the quotes sit
     * inside the very name the pattern spells; the words alone would lose a
     * name inside a substitution (`$(cat .env)` stays one word) or an
     * unterminated parse. Matching both is the deny-side union: a spelling
     * either form catches is refused. Still best-effort — `$HOME`, globs
     * (`cat .en?`), variables and `cd` remain outside what any text match can
     * see.
     *
     * `Read`, `Grep`, `Glob` AND `Lsp` ARE JUDGED AGAINST A SHORTER LIST —
     * everything except {@see self::WRITE_ONLY_PATTERNS}, which is where the
     * argument for that lives: none of the four can write. `Bash` is not: one
     * shell string does not say whether it is about to read the file or write
     * it. Neither is an `mcp__*` tool, for the same reason one level up — its
     * name does not say what the server does with the path.
     *
     * WHICH ARGUMENTS ARE JUDGED, per tool — see {@see inputsFor()}.
     */
    public function execute(HookContext $context): HookResult
    {
        $toolName = ucfirst(strtolower($context->toolName));
        $inputs = self::inputsFor($toolName, $context);
        // The matched tools that cannot write are judged without
        // WRITE_ONLY_PATTERNS: the built-ins whose catalog declaration is
        // read-only. Compared case-insensitively, like the matcher.
        $readOnly = \in_array(
            strtolower($context->toolName),
            array_map('strtolower', ToolCatalog::namesOf(ToolPermissionClass::Read)),
            true,
        );

        foreach ($this->protectedPatterns as $pattern) {
            if ($readOnly && \in_array($pattern, self::WRITE_ONLY_PATTERNS, true)) {
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
     * The strings a pattern is matched against for one tool call.
     *
     *  - `Bash`: the raw `command` plus its {@see shellSpellings()}.
     *  - `Edit`/`Write`/`Read`: `file_path`, as given and canonicalised.
     *  - `Grep`: `path` (canonicalised) and `include` — `include=.env` narrows
     *    the search to exactly the secret. NOT `pattern`: that is the TEXT being
     *    searched for, and `Grep pattern=".env" path=src` (where does this code
     *    load its env file?) is ordinary work. A directory `path` cannot be
     *    screened here at all — `.` contains `.env` — which is why
     *    {@see \SugarCraft\Crush\Tools\BuiltIn\Grep} itself never opens the
     *    secret files; this half refuses the call that NAMES one.
     *  - `Glob`: `path` (canonicalised) and `pattern`.
     *  - `Lsp`: `path`, canonicalised.
     *  - anything else (`mcp__*`): every string leaf of the DECODED arguments,
     *    at any depth, plus the shell spellings of each. Not the raw JSON
     *    `toolInput`: `json_encode()` escapes `/` as `\/`, so `.git/config`
     *    arrives as `.git\/config` and the pattern never sees it. Every leaf
     *    rather than a guessed list of path-ish keys, because one server's
     *    `path` is another's `uri`, `target` or `command` — the cost is that an
     *    MCP call whose free text merely names `.env` is refused too, which is
     *    the fail-closed direction for a tool this hook cannot see inside.
     *
     * @return list<string>
     */
    private static function inputsFor(string $toolName, HookContext $context): array
    {
        $args = $context->toolArgs;

        return match ($toolName) {
            'Bash' => self::shellSpellings($args['command'] ?? ''),
            'Edit', 'Write', 'Read' => self::pathSpellings($args['file_path'] ?? null),
            'Grep' => [...self::pathSpellings($args['path'] ?? null), ...self::strings($args['include'] ?? null)],
            'Glob' => [...self::pathSpellings($args['path'] ?? null), ...self::strings($args['pattern'] ?? null)],
            'Lsp' => self::pathSpellings($args['path'] ?? null),
            default => self::leafSpellings($args),
        };
    }

    /**
     * $command as written plus every quote-removed form bash would produce
     * from it: the dequoted command text, each word on its own (so a `^`
     * anchored custom pattern sees a word start), and each redirection target
     * (which {@see \SugarCraft\Crush\Permissions\ShellWords::dequoted()} leaves
     * out — `cat < ".git/config"` reads the file through one).
     *
     * @return list<string>
     */
    private static function shellSpellings(mixed $command): array
    {
        if (!\is_string($command) || $command === '') {
            return [''];
        }

        $words = ShellWords::parse($command);
        $spellings = [$command, $words->dequoted()];
        foreach ($words->commands as $argv) {
            foreach ($argv as $word) {
                $spellings[] = $word;
            }
        }
        foreach ($words->redirections as $redirection) {
            if ($redirection['target'] !== null) {
                $spellings[] = $redirection['target'];
            }
        }

        return array_values(array_unique($spellings));
    }

    /**
     * Every string leaf of $args, each with its shell spellings — an MCP
     * server's `command` argument is a shell line as much as Bash's is.
     *
     * @param array<array-key, mixed> $args
     * @return list<string>
     */
    private static function leafSpellings(array $args): array
    {
        $spellings = [];
        array_walk_recursive($args, static function (mixed $leaf) use (&$spellings): void {
            if (\is_string($leaf) && $leaf !== '') {
                array_push($spellings, ...self::shellSpellings($leaf));
            }
        });

        return $spellings === [] ? [''] : array_values(array_unique($spellings));
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return \is_string($value) ? [$value] : [];
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
