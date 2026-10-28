<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\AgentsCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\FrontmatterKeyAudit;

/**
 * `/agents` (`/agent`) — the session half of {@see AgentsCommand} (roadmap
 * O-2h): its stdout captured as the reply, a non-zero exit as a notice.
 *
 * A session with no agent manager answers "not configured" rather than
 * throwing. A `candy-code` launch always supplies one; an embedder that
 * builds a session without one gets the sentence every other optional
 * collaborator answers with. (The old `?? throw` escaped out of
 * `Chat::update()`, past sugar-core's loop, and left the terminal raw.)
 *
 * C-EXT-3.d adds the three draft verbs, which need no manager at all —
 * they move files, not agents:
 *
 *  - `new <focus>` answers with the {@see NewAgentPrompt} draft request as a
 *    {@see CommandEffect::submitPrompt()}. The TUI never reaches this arm for
 *    it: `Chat::handleAgentsCommand()` intercepts `new` first and lands the
 *    prompt through the `/newrule` `withInputBuf(...)->submit()` idiom; this
 *    is the served route, which SessionHost applies as a real turn.
 *  - `proposed` lists the drafts waiting under `<root>/.candy-code/agents-proposed/`.
 *  - `accept <name>` IS THE CONSENT GATE. The agent cannot write a live
 *    preset — {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook::WRITE_ONLY_PATTERNS}
 *    denies that directory in every mode because a preset's `permissionMode:`
 *    is a self-grant — so promotion happens only here, only on a human-typed
 *    command. It re-reads the draft, validates its frontmatter against
 *    {@see FrontmatterKeyAudit::KNOWN}[AGENT] (unknown keys, an unreadable
 *    block, an invalid `permissionMode`, or a mistyped policy shape all
 *    refuse the move), PRINTS the three policy keys verbatim before moving
 *    the file, and never overwrites an existing preset. A self-granting mode
 *    is still acceptable — the accept step IS the consent — but it rides the
 *    loud `host.agents.accept.self_grant` line so what was just agreed to is
 *    in the transcript above the move.
 */
final class AgentsHostCommand implements HostCommand
{
    /** The one shape a draft file name may take; the accept argument must match it whole. */
    private const DRAFT_NAME = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    public function run(CommandContext $context, string $text): CommandResult
    {
        $words = CommandText::words($text);
        $sub = strtolower($words[0] ?? '');

        // C-EXT-3.d: the draft verbs run before the manager gate — they are
        // file operations, and a build without a sub-agent runtime can still
        // promote presets. Any other word (none, `list`, a preset name) falls
        // through to the roster arm unchanged.
        if ($sub === 'new' || $sub === 'proposed' || $sub === 'accept') {
            try {
                return match ($sub) {
                    'new' => CommandResult::reply($text, Lang::t('host.agents.new.dispatched'))
                        ->withEffect(CommandEffect::submitPrompt(NewAgentPrompt::prompt(
                            implode(' ', \array_slice($words, 1)),
                            NewAgentPrompt::existingPresets($context->projectRoot()),
                        ))),
                    'proposed' => \count($words) <= 1
                        ? CommandResult::reply($text, self::proposedListing($context))
                        : CommandResult::failure($text, Lang::t('host.agents.usage'), 2),
                    default => self::accept($context, $text, \array_slice($words, 1)),
                };
            } catch (\Throwable $e) {
                return CommandResult::failure($text, Lang::t('host.agents.error', ['error' => PermissionsCommand::reportField($e->getMessage())]), 1);
            }
        }

        if ($context->agentManager === null) {
            return CommandResult::errorReply($text, Lang::t('host.agents.not_configured'));
        }

        ob_start();
        $exitCode = (new AgentsCommand($context->agentManager))->execute($context, $words);
        $output = (string) ob_get_clean();

        return $exitCode === 0
            ? CommandResult::reply($text, $output)
            : CommandResult::failure($text, $output, $exitCode);
    }

    /**
     * `/agents proposed`: the draft tier as a plain name + description list.
     * Written against the filesystem, not a registry — no loader reads this
     * directory, so there is nothing to enumerate but the files themselves.
     */
    private static function proposedListing(CommandContext $context): string
    {
        $root = $context->projectRoot();
        $files = $root === '' ? [] : (glob(rtrim($root, '/') . '/' . NewAgentPrompt::DIRECTORY . '/*.md') ?: []);
        $drafts = [];
        foreach ($files as $path) {
            $name = basename((string) $path, '.md');
            if (preg_match(self::DRAFT_NAME, $name) !== 1) {
                continue;
            }
            // The glob spells the path inside the draft directory, but a
            // grafted DIRECTORY link re-anchors where those bytes actually
            // live; only files whose real path stays below the project are
            // this project's drafts.
            if (!ContainedPath::below((string) $path, $root)) {
                continue;
            }
            $meta = FrontmatterKeyAudit::metaOf((string) @file_get_contents((string) $path));
            $drafts[] = is_string($meta['description'] ?? null)
                ? NewAgentPrompt::DIRECTORY . '/' . $name . ' - ' . PermissionsCommand::reportField($meta['description'])
                : NewAgentPrompt::DIRECTORY . '/' . $name;
        }
        sort($drafts);

        if ($drafts === []) {
            return Lang::t('host.agents.proposed.none', ['dir' => NewAgentPrompt::DIRECTORY]);
        }

        return Lang::t('host.agents.proposed.heading') . "\n" . implode("\n", $drafts);
    }

    /**
     * `/agents accept <name>` — the consent gate (see the class doc-block).
     *
     * @param list<string> $args the words after the verb
     */
    private static function accept(CommandContext $context, string $text, array $args): CommandResult
    {
        if (\count($args) !== 1) {
            return CommandResult::failure($text, Lang::t('host.agents.usage'), 2);
        }

        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/agents accept']));
        }

        $name = $args[0];
        if (preg_match(self::DRAFT_NAME, $name) !== 1) {
            return CommandResult::failure($text, Lang::t('host.agents.accept.bad_name', ['name' => PermissionsCommand::reportField($name)]), 2);
        }

        $root = $context->projectRoot();
        $draft = rtrim($root, '/') . '/' . NewAgentPrompt::DIRECTORY . '/' . $name . '.md';
        // A symlinked draft rides the bad-name door: the consent print would
        // cover the target as it stands at accept time, while any later read
        // goes through the same link to a body that can still change.
        if (is_link($draft)) {
            return CommandResult::failure($text, Lang::t('host.agents.accept.bad_name', ['name' => PermissionsCommand::reportField($name)]), 2);
        }
        if ($root === '' || !is_file($draft) || !ContainedPath::below($draft, $root)) {
            // The `below` leg catches the grafted-DIRECTORY swap: the draft
            // file is a real regular file, so `is_link` cannot see that the
            // directory under which it sits is a link out of the project.
            // A body that does not resolve below the project root is not
            // this project's draft; promoting it would move foreign bytes
            // into the live tier.
            return CommandResult::failure($text, Lang::t('host.agents.accept.missing', ['name' => $name, 'dir' => NewAgentPrompt::DIRECTORY]), 1);
        }

        $content = (string) file_get_contents($draft);
        $meta = FrontmatterKeyAudit::metaOf($content);
        $problem = self::validateDraft($name, $content, $meta);
        if ($problem !== null) {
            return CommandResult::failure($text, $problem, 1);
        }

        $final = rtrim($root, '/') . '/' . NewAgentPrompt::TARGET . '/' . $name . '.md';
        if (file_exists($final)) {
            return CommandResult::failure($text, Lang::t('host.agents.accept.exists', ['name' => $name, 'dir' => NewAgentPrompt::TARGET]), 1);
        }

        // The consent row: printed BEFORE the move, and part of the same
        // reply, so what was agreed to sits above the fact of promotion in
        // the transcript. Verbatim — the human reads the file's own words.
        $report = self::policyReport($meta);

        $targetDir = rtrim($root, '/') . '/' . NewAgentPrompt::TARGET;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('could not create ' . $targetDir);
        }
        // Disclosed window (review NIT-2): the consent above was printed from
        // the bytes read a few statements back; a concurrent rewrite of the
        // draft between that read and this rename would promote the newer
        // body under an older print. The tree is the human's own project and
        // the move is one-shot — the audit accepted the trade as disclosed
        // rather than re-reading and re-printing under a lock.
        if (!rename($draft, $final)) {
            throw new \RuntimeException('could not move the draft into ' . $targetDir);
        }

        return CommandResult::reply($text, $report . Lang::t('host.agents.accept.moved', [
            'name' => $name,
            'path' => NewAgentPrompt::TARGET . '/' . $name . '.md',
        ]));
    }

    /**
     * The draft's frontmatter judged against what a preset reader actually
     * acts on. Returns the refusal row, or null when the move may go ahead.
     *
     * @param array<string,mixed> $meta
     */
    private static function validateDraft(string $name, string $content, array $meta): ?string
    {
        if (preg_match('/^---[ \t]*\n/', $content) !== 1 || $meta === []) {
            return Lang::t('host.agents.accept.frontmatter', ['name' => $name]);
        }

        foreach (array_keys($meta) as $key) {
            $key = (string) $key;
            if (in_array($key, FrontmatterKeyAudit::KNOWN[FrontmatterKeyAudit::AGENT], true)) {
                continue;
            }

            $suggest = FrontmatterKeyAudit::suggestion(FrontmatterKeyAudit::AGENT, $key);

            return Lang::t('host.agents.accept.unknown', [
                'name' => $name,
                'key' => PermissionsCommand::reportField($key),
                'hint' => $suggest === null ? '' : ' (did you mean `' . $suggest . '`?)',
            ]);
        }

        if (array_key_exists('permissionMode', $meta)) {
            $mode = $meta['permissionMode'];
            $known = array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases());
            if (!is_string($mode) || !in_array($mode, $known, true)) {
                return Lang::t('host.agents.accept.mode', [
                    'name' => $name,
                    'mode' => PermissionsCommand::reportField(is_scalar($mode) ? (string) $mode : get_debug_type($mode)),
                    'modes' => implode(', ', $known),
                ]);
            }
        }

        foreach (NewAgentPrompt::POLICY_KEYS as $key) {
            if (!array_key_exists($key, $meta) || self::policyText($meta[$key]) !== null) {
                continue;
            }

            return Lang::t('host.agents.accept.type', ['name' => $name, 'key' => $key]);
        }

        return null;
    }

    /**
     * The policy keys printed verbatim, in {@see NewAgentPrompt::POLICY_KEYS}
     * order, with the self-grant warning under the mode that earned it.
     *
     * @param array<string,mixed> $meta
     */
    private static function policyReport(array $meta): string
    {
        $lines = [Lang::t('host.agents.accept.policy')];
        foreach (NewAgentPrompt::POLICY_KEYS as $key) {
            $lines[] = Lang::t('host.agents.accept.policy_line', [
                'key' => $key,
                'value' => PermissionsCommand::reportField(self::policyText($meta[$key] ?? null) ?? ''),
            ]);
        }

        $mode = $meta['permissionMode'] ?? null;
        if (is_string($mode) && in_array($mode, NewAgentPrompt::SELF_GRANTING_MODES, true)) {
            $lines[] = Lang::t('host.agents.accept.self_grant', ['mode' => $mode]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A policy value as the one transcript line it needs — a scalar as text,
     * a list joined with commas — or null when the shape is none of those
     * (the reader wants a string or a list of strings).
     */
    private static function policyText(mixed $value): ?string
    {
        if ($value === null) {
            return 'not declared';
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return var_export($value, true);
        }

        if (is_string($value)) {
            return trim($value) === '' ? 'not declared' : $value;
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    return null;
                }
                $items[] = $item;
            }

            return $items === [] ? 'none' : implode(', ', $items);
        }

        return null;
    }
}
