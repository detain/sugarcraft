<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

/**
 * The ONE snapshot of session state a `statusLine` command receives on its
 * stdin — the shape Claude Code hands its statusline scripts, plus a
 * `candy_code` object carrying what only this app knows.
 *
 * WHY A PURE CLASS. The tick that refreshes the status line lives on the
 * update path, and the child that runs the command lives behind a fork
 * ({@see StatusLineCommand::cmd()}). Both need the same bytes, and neither
 * may reach back into the live {@see \SugarCraft\Code\Chat} for them: the
 * forked child would read the PARENT's memory as it was at fork time and
 * call itself current, and the update path must not have the model's
 * shape leak into a Config value. So the caller gathers the figures through
 * the accessors the status bar already reads and hands them in; this class
 * only ARRANGES them. That keeps the wire shape one PSR-4 type with one
 * definition, and makes every key of it testable without a Chat, a
 * backend, or a process.
 *
 * THE SHAPE. Top level mirrors Claude Code's documented statusline input —
 * `session_id`, `cwd`, `model{id,display_name}`, `workspace{current_dir,
 * project_dir}`, `version`, `cost{total_cost_usd}` — so a script written
 * for that tool finds what it expects. This app has ONE name per model and
 * no prettier label to offer, so `display_name` carries the same string as
 * `id`: inventing decoration here would be a claim about data the provider
 * never reported. The `candy_code` object then carries what the bar paints
 * but the Claude Code shape has no slot for: `provider`, `permission_mode`,
 * `context_tokens`, `context_percent`, `branch`.
 *
 * WHY THE EXTRAS ARE UNDER THEIR OWN KEY. A script that walks
 * `model.display_name` must not trip over a foreign field, and a script
 * that wants this app's richer picture asks for it BY NAME. Mixing the two
 * vocabularies at top level would make each future addition a collision
 * risk against whatever Claude Code's shape becomes.
 *
 * NULLES STAY NULL. Pre-session, every gathered field can be unknown: no
 * persisted id yet, no engine answering for a model, a detached HEAD, no
 * pricing. The keys are always present with JSON `null` — never omitted,
 * never the empty string — because a script cannot tell an omitted key from
 * a null one without a schema, and here the schema IS this class. Every
 * object in the shape is built from a fixed set of literal keys, so no
 * nested map can ever serialise as `[]` on the empty path.
 *
 * WHO SEES THESE BYTES. The status line is user-tier only — the configured
 * command is code the user themselves wrote, already trusted to run on a
 * timer with the inherited environment. The payload leaks nothing that
 * command does not already own: it runs in `cwd`, its environment carries
 * `PATH`, and `projectRoot()` is the directory it was launched against.
 */
final class StatusLineSession
{
    /**
     * @param string|null $sessionId the persisted session id, null before the session has one
     * @param string|null $cwd the directory the command runs in — also `workspace.current_dir`
     * @param string|null $modelId the served model when known; `display_name` carries the same string
     * @param string|null $projectDir the project root — `workspace.project_dir`
     * @param float $totalCostUsd session spend including the in-flight turn's billed part
     * @param string|null $provider this app's extra: the provider behind the model
     * @param string|null $permissionMode this app's extra: the PermissionMode value string
     * @param int $contextTokens this app's extra: the context figure the bar paints
     * @param int $contextPercent this app's extra: 0-100, the rounded bar percentage
     * @param string|null $branch this app's extra: the current git branch, null detached or off-repo
     */
    public function __construct(
        public readonly ?string $sessionId,
        public readonly ?string $cwd,
        public readonly ?string $modelId,
        public readonly ?string $projectDir,
        public readonly string $version,
        public readonly float $totalCostUsd,
        public readonly ?string $provider,
        public readonly ?string $permissionMode,
        public readonly int $contextTokens,
        public readonly int $contextPercent,
        public readonly ?string $branch,
    ) {
    }

    /**
     * The stdin document, or '' when it cannot be built.
     *
     * The flags are {@see \SugarCraft\Code\Hooks\ScriptHook}'s stdin set —
     * the other place this app writes JSON to a user-configured external
     * script: unescaped slashes (paths read as paths, not as `\/` soup),
     * unescaped unicode, and INVALID_UTF8_SUBSTITUTE rather than a failure,
     * because a branch or directory name carrying a byte that is not valid
     * UTF-8 is external data this app does not control and must not let
     * poison the whole document. With those flags over scalar-only input
     * `json_encode()` has nothing left to fail on; the `''` arm is the
     * fail-closed shape, not a path this build expects to walk.
     */
    public function toJson(): string
    {
        $json = json_encode([
            'session_id' => $this->sessionId,
            'cwd' => $this->cwd,
            'model' => [
                'id' => $this->modelId,
                'display_name' => $this->modelId,
            ],
            'workspace' => [
                'current_dir' => $this->cwd,
                'project_dir' => $this->projectDir,
            ],
            'version' => $this->version,
            'cost' => [
                'total_cost_usd' => $this->totalCostUsd,
            ],
            'candy_code' => [
                'provider' => $this->provider,
                'permission_mode' => $this->permissionMode,
                'context_tokens' => $this->contextTokens,
                'context_percent' => $this->contextPercent,
                'branch' => $this->branch,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return \is_string($json) ? $json : '';
    }
}
