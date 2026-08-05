<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\ToolCall;

/**
 * SafetyClassifier reviews each tool call in Auto mode against a fixed
 * blocklist of dangerous-action categories (per the crush_code_plan.md
 * "Auto" paragraph: 13 categories). Returns the category name if blocked,
 * null if the action is safe to auto-execute.
 *
 * Mirrors charmbracelet/crush safety-classifier behavior (P2B.S3).
 */
final class SafetyClassifier
{
    /**
     * Known dangerous patterns keyed by category name.
     *
     * @var array<string, string[]>
     */
    private const PATTERNS = [
        'curl/wget-into-shell' => [
            'curl\s+.*\|\s*(sh|bash|zsh|fish)',
            'wget\s+.*\|\s*(sh|bash|zsh|fish)',
            'curl\s+.*>\s*/dev/',
            'curl\s+.*\|.*(eval|exec|bash\s+-c)',
            'wget\s+.*\|.*(eval|exec|bash\s+-c)',
        ],
        'external-endpoint' => [
            'curl\s+-X\s*(POST|PUT|PATCH)\s+https?://',
            'wget\s+--method=',
            'httpie\s+',
            'httpx\s+',
            'fetch\s+https?://',
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
            'rm\s+rf\s+./vendor',
            'rm\s+rf\s+./node_modules',
            'rm\s+rf\s+./.git',
            'rm\s+-rf\s+./vendor',
            'rm\s+-rf\s+./node_modules',
            'rm\s+-rf\s+./.git',
        ],
        'force-push-reset-hard' => [
            'git\s+push\s+--force',
            'git\s+push\s+-f',
            'git\s+push\s+--force-with-lease',
            'git\s+push\s+--force-if-includes',
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
            'gh\s+issue\s+comment\s+create',
            'gh\s+issue\s+comment\s+edit',
            'gh\s+pr\s+comment\s+create',
            'gh\s+pr\s+comment\s+edit',
            'gh\s+pr\s+review\s+submit',
            'gh\s+api\s+repos/.*/issues/.*/comments',
            'hub\s+issue\s+comment',
            'hub\s+pr\s+comment',
            'gitlab\s+note\s+create',
            'gitlab\s+mr\s+note\s+create',
        ],
        'interactive-shell-portforward' => [
            'ssh\s+.*-L\s+',
            'ssh\s+.*-R\s+',
            'ssh\s+.*-D\s+',
            'ssh\s+.*-W\s+',
            'kubectl\s+port-forward',
            'kubectl\s+exec\s+-i\s+-t',
            'kubectl\s+exec\s+--stdin\s+--tty',
            'docker\s+exec\s+-it',
            'docker\s+run\s+.*-it\s+',
            'python\s+.*-c\s+.*import\s+pty',
            'script\s+.*-q\s+.*/dev/null',
            'expect\s+',
            'socat\s+',
            'ncat\s+--exec',
            'nc\s+-e\s+',
            'netcat\s+.*-e\s+',
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
            'env\s+|\s*grep\s+SECRET',
            'env\s+|\s*grep\s+PASSWORD',
            'env\s+|\s*grep\s+KEY',
            'strings\s+.*\.env',
            'python\s+.*-c\s+.*os\.environ\[',
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
            'pnpm\s+add\s+.*--registry\s+https://registry.npmjs.org',
            'composer\s+require\s+.*--repository\s+https://packagist\.org',
            'gem\s+install\s+.*--no-document',
            'go\s+get\s+.*https://github\.com/',
            'go\s+install\s+.*@latest',
            'curl\s+.*pypi\.org.*pip\s+install',
            'wget\s+.*pypi\.org.*pip\s+install',
        ],
    ];

    /**
     * Classify a tool call — returns the dangerous-action category name if blocked,
     * null if the action is safe to auto-execute.
     */
    public function classify(ToolCall $call): ?string
    {
        if ($call->name === 'Bash') {
            return $this->classifyBash($call);
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
        $delimited = '#' . $pattern . '#i';
        return (bool) preg_match($delimited, $subject);
    }
}
