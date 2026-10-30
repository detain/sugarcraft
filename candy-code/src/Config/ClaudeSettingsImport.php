<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ModelChoice;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Lang;

/**
 * The `candy-code import claude` translation table (C-TUI-5.b).
 *
 * Reads Claude Code's OWN settings documents — `~/.claude/settings.json`
 * (plus the project's `.claude/settings.json` when `--project` spells it) —
 * and maps the few keys candy-code has a home for:
 *
 *  - `permissions.allow` / `.deny` / `.ask` onto `permissionRules` rows,
 *    through {@see PermissionRule::fromClaudePrefixDialect()}'s `(prefix:*)`
 *    → `(prefix *)` translator, judged by the grammar gate
 *    {@see PermissionRule::patternRejectionReason()};
 *  - `model` onto candy-code's own per-provider `models` map (decision D9),
 *    through the same door `/model` uses, under the selected provider.
 *
 * Everything else is REPORTED, never applied: the `hooks` key needs nothing
 * because {@see \SugarCraft\Code\Hooks\ClaudeSettingsHooks} already reads
 * these same files at launch; any other key has no candy-code setting that
 * answers to it. A pattern that cannot survive the grammar gate lands in the
 * rejected list — skipped and named, never guessed at.
 *
 * DENY WINS. When one pattern appears in more than one list the stricter
 * action survives (deny over ask over allow), so an `allow` can never widen
 * a rule the same document also restricts; the allow the drop took away is
 * still enumerated in `widenings` because the operator must SEE every
 * widening the write would cause. Within the produced list deny rows ride
 * first, then ask, then allow — the launch gate reads first-match-wins, so
 * the precedence the translation decided is also the order that enforces it.
 *
 * The settings documents are UNTRUSTED INPUT: a symlinked or unreadable file
 * and any malformed document REFUSE the whole import loudly (nothing is ever
 * partially applied), the same fail-loud posture
 * {@see \SugarCraft\Code\MCP\McpForeignTranslate} keeps for foreign MCP
 * files. This class reads; it never writes. The one write door is
 * {@see self::persist()}, which hands the whole merged list through the
 * launch's {@see SettingsWriter} — the editor validates the result with the
 * launch's own strict parser before a byte is written.
 */
final class ClaudeSettingsImport
{
    /** The verb's one known source operand. */
    public const SOURCE = 'claude';

    /** The file a Claude settings directory names. */
    public const FILE_NAME = 'settings.json';

    /** Claude settings documents live in this project-relative directory. */
    public const DIRECTORY = '.claude';

    /** Hard cap on a settings document; anything bigger is refused unread. */
    public const MAX_FILE_BYTES = 1048576;

    /** Top-level keys candy-code has a home for, and their honest verdicts. */
    public const KEY_MODEL = 'model';

    public const KEY_PERMISSIONS = 'permissions';

    private function __construct()
    {
    }

    /**
     * The user's Claude settings path, or null when the home cannot be
     * vouched for — {@see Bootstrap::trustedConfigDirPath()} is the gate that
     * answers ''-by-throwing for an unowned home, the same door the user
     * config path rides.
     */
    public static function userSettingsFile(): ?string
    {
        try {
            $dir = \dirname(Bootstrap::trustedConfigDirPath());
        } catch (\Throwable) {
            return null;
        }

        return $dir . '/' . self::DIRECTORY . '/' . self::FILE_NAME;
    }

    /**
     * The project's Claude settings path under `$root` — the launch root the
     * operator named (`--root`/the working directory), the same ambient
     * default `mcp import claude-project` reads.
     */
    public static function projectSettingsFile(string $root): string
    {
        return \rtrim($root, '/') . '/' . self::DIRECTORY . '/' . self::FILE_NAME;
    }

    /**
     * Read and translate every named file into one report.
     *
     * Missing files are simply absent from `files` — the caller's doors
     * decide whether an absent file is "nothing to do" (the default scope) or
     * a refusal (`--project` spelled at a root with no settings).
     *
     * @param list<string> $paths
     *
     * @return array{
     *     files: list<string>,
     *     rules: list<array{pattern: string, action: string}>,
     *     model: ?string,
     *     widenings: list<string>,
     *     dropped: list<string>,
     *     skipped: list<array{key: string, note: string}>,
     *     rejected: list<array{source: string, pattern: string, reason: string}>,
     * }
     *
     * @throws RuntimeException a file is present but unreadable/hostile, or a
     *                          `permissions` list is not a list of strings
     * @throws InvalidArgumentException the document is malformed JSON or not
     *                                  an object — the whole import refuses
     */
    public static function inspect(array $paths): array
    {
        $documents = [];
        foreach ($paths as $path) {
            $doc = self::read($path);
            if ($doc !== null) {
                $documents[$path] = $doc;
            }
        }

        // Precedence union: the FIRST document that mentions a pattern in the
        // stricter bucket wins, and a later document can only RAISE the
        // restriction on a pattern (deny over ask over allow), never loosen it.
        /** @var array<string, string> $chosen */
        $chosen = [];
        /** @var list<string> $wideningPatterns */
        $widening = [];
        $skipped = [];
        $rejected = [];
        $model = null;

        foreach ($documents as $source => $doc) {
            $permissions = $doc[self::KEY_PERMISSIONS] ?? null;
            if ($permissions === null) {
                // Absent permissions is nothing to say, not a hole.
            } elseif (!\is_array($permissions)) {
                throw new RuntimeException(Lang::t('cli.sub.import.permissions_not_object', ['path' => $source]));
            } else {
                foreach (['deny', 'ask', 'allow'] as $action) {
                    $list = $permissions[$action] ?? [];
                    if ($list === null) {
                        continue;
                    }
                    if (!\is_array($list)) {
                        throw new RuntimeException(Lang::t('cli.sub.import.list_not_array', ['path' => $source, 'list' => $action]));
                    }
                    foreach ($list as $entry) {
                        if (!\is_string($entry)) {
                            throw new RuntimeException(Lang::t('cli.sub.import.list_entry', ['path' => $source, 'list' => $action]));
                        }
                        $pattern = PermissionRule::fromClaudePrefixDialect($entry);
                        $reason = PermissionRule::patternRejectionReason($pattern);
                        if ($reason !== null) {
                            $rejected[] = ['source' => $source, 'pattern' => $entry, 'reason' => $reason];

                            continue;
                        }
                        $previous = $chosen[$pattern] ?? null;
                        $chosen[$pattern] = self::stricter($previous, $action);
                        if ($action === 'allow') {
                            $widening[] = $pattern;
                        }
                    }
                }
                foreach ($permissions as $key => $_) {
                    if ($key !== 'allow' && $key !== 'deny' && $key !== 'ask') {
                        $skipped[] = ['key' => 'permissions.' . $key, 'note' => Lang::t('cli.sub.import.skipped_nohome')];
                    }
                }
            }

            $rawModel = $doc[self::KEY_MODEL] ?? null;
            if ($rawModel !== null) {
                if (\is_string($rawModel) && $rawModel !== '' && !\preg_match('/[\x00-\x1F\x7F]/', $rawModel)) {
                    if ($model === null) {
                        $model = $rawModel;
                    }
                } else {
                    $skipped[] = ['key' => 'model', 'note' => Lang::t('cli.sub.import.skipped_unusable')];
                }
            }

            foreach ($doc as $key => $_) {
                if ($key === self::KEY_PERMISSIONS || $key === self::KEY_MODEL) {
                    continue;
                }
                if ($key === 'hooks') {
                    $skipped[] = ['key' => 'hooks', 'note' => Lang::t('cli.sub.import.skipped_hooks')];

                    continue;
                }
                $skipped[] = ['key' => (string) $key, 'note' => Lang::t('cli.sub.import.skipped_nohome')];
            }
        }

        // Order the produced list deny-first, then ask, then allow, keeping
        // translation order inside each bucket (the documents' own spelling).
        $rules = [];
        foreach (['deny', 'ask', 'allow'] as $action) {
            foreach ($chosen as $pattern => $chosenAction) {
                if ($chosenAction === $action) {
                    $rules[] = ['pattern' => $pattern, 'action' => $action];
                }
            }
        }

        // Patterns the documents spelled as `allow` that the precedence above
        // downgraded to ask/deny: the operator must SEE them — they were
        // requests to widen that the stricter half of the same document won.
        $dropped = [];
        foreach (array_unique($widening) as $pattern) {
            if (($chosen[$pattern] ?? 'allow') !== 'allow') {
                $dropped[] = $pattern;
            }
        }

        return [
            'files' => array_keys($documents),
            'rules' => $rules,
            'model' => $model,
            'widenings' => array_values(array_unique($widening)),
            'dropped' => $dropped,
            'skipped' => $skipped,
            'rejected' => $rejected,
        ];
    }

    /**
     * The only write door: merge the translated rows onto the user tier's
     * EXISTING `permissionRules` (deduped by pattern+action, so re-running
     * the import is a no-op) and set `model` when one carried, in ONE
     * {@see SettingsWriter::write()} of the `You` tier — which validates the
     * whole result through the launch's own strict parser
     * ({@see Bootstrap::permissionRules()}) and settles it through the one
     * writer config.json has.
     *
     * @param array{rules: list<array{pattern: string, action: string}>, model: ?string, ...} $report
     *
     * @return array{path: string, added: int, merged: int, model: bool} counts for the success line
     */
    public static function persist(array $report, SettingsWriter $writer, ?string $provider = null): array
    {
        $existing = $writer->current(SettingsTier::You)['permissionRules'] ?? [];
        $existingRows = [];
        foreach (\is_array($existing) ? $existing : [] as $row) {
            if (\is_array($row) && \is_string($row['pattern'] ?? null) && \is_string($row['action'] ?? null)) {
                $existingRows[$row['pattern'] . '|' . $row['action']] = true;
            }
        }

        $merged = \is_array($existing) ? array_values($existing) : [];
        $added = 0;
        foreach ($report['rules'] as $row) {
            if (isset($existingRows[$row['pattern'] . '|' . $row['action']])) {
                continue;
            }
            $merged[] = $row;
            ++$added;
        }

        $path = $writer->write(SettingsTier::You, ['permissionRules' => $merged]);

        $modelWritten = false;
        if ($report['model'] !== null) {
            // candy-code keeps model ids per provider (decision D9), so the
            // scalar `model` lands under the provider this install selects —
            // and it rides the very door `/model` uses
            // ({@see ModelChoice::persist()}), which writes the whole `models`
            // map merged so no other provider's entry is ever lost. The
            // caller vets the provider name against the real provider list
            // and withholds the model when there is no name worth writing:
            // an entry under a name nothing reads would sit in the config
            // forever, which is the one thing /model itself refuses.
            if ($provider === null || $provider === '') {
                throw new \InvalidArgumentException('the imported model arrived without a vetted provider name');
            }
            $layered = Bootstrap::readUserConfig()['models'] ?? [];
            ModelChoice::persist($writer, $provider, $report['model'], \is_array($layered) ? $layered : []);
            $modelWritten = true;
        }

        return ['path' => $path, 'added' => $added, 'merged' => \count($merged), 'model' => $modelWritten];
    }

    /**
     * One trusted read: absent → null; present-but-hostile (symlink, not a
     * plain file, oversized, unreadable) → loud RuntimeException; malformed
     * document → loud InvalidArgumentException. Nothing here is ever
     * executed — the bytes are a document to translate.
     *
     * @return ?array<string, mixed>
     */
    private static function read(string $path): ?array
    {
        if (!\is_file($path) || \is_link($path)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new RuntimeException(Lang::t('cli.sub.import.unreadable_kind', ['path' => $path]));
            }

            return null;
        }

        $size = \filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException(Lang::t('cli.sub.import.too_large', ['path' => $path, 'cap' => (string) self::MAX_FILE_BYTES]));
        }

        // BARE spelling — the \-prefixed form is invisible to the read-path census scanner
        $raw = @file_get_contents($path);
        if (!\is_string($raw)) {
            throw new RuntimeException(Lang::t('cli.sub.import.unreadable', ['path' => $path]));
        }

        try {
            $decoded = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(Lang::t('cli.sub.import.invalid_json', ['path' => $path, 'error' => $e->getMessage()]));
        }

        // A JSON list at the root (`[]` decoded to a non-empty list) is not a
        // settings document; `{}` and `[]` are indistinguishable after decode
        // and both mean "the document says nothing", so only a NON-empty list
        // is refused (the ClaudeSettingsHooks::parse posture).
        if (!\is_array($decoded) || ($decoded !== [] && \array_is_list($decoded))) {
            throw new InvalidArgumentException(Lang::t('cli.sub.import.not_an_object', ['path' => $path]));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * deny over ask over allow; a null previous adopts the new action.
     *
     * @param ?string $previous one of allow|ask|deny|null
     */
    private static function stricter(?string $previous, string $action): string
    {
        $rank = ['allow' => 0, 'ask' => 1, 'deny' => 2];
        if ($previous === null) {
            return $action;
        }

        return $rank[$action] > $rank[$previous] ? $action : $previous;
    }
}
