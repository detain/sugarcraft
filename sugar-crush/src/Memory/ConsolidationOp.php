<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * One operation of a {@see ConsolidationPlan}: what the auto-memory model
 * proposed for one note (roadmap 5.2).
 *
 * `add` a new note, `update` the content of a note auto-memory wrote earlier,
 * `delete` one, or `skip` — a decision NOT to save something, with the reason
 * from {@see SKIP_REASONS} (Kilo's taxonomy) or one the applier adds when it
 * refuses an operation ({@see APPLIER_REASONS}). Skips are kept rather than
 * dropped: "prefer saving nothing" is the expected outcome, and the reasons
 * are how a run that saved nothing can be told apart from one that failed.
 */
final readonly class ConsolidationOp
{
    public const ADD = 'add';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const SKIP = 'skip';

    /** The reasons the model may give for not saving something (Kilo's list). */
    public const SKIP_REASONS = [
        'duplicate', 'transient', 'unsupported', 'secret', 'too_specific', 'in_progress',
        'policy_belongs_in_docs', 'out_of_scope', 'self_referential',
    ];

    /**
     * The reasons the plan parser and {@see AutoMemoryConsolidator::apply()}
     * give when they refuse an operation: unparseable or malformed, over the
     * per-run cap, naming a note that does not exist, or naming a note the
     * user wrote (auto-memory only changes its own notes).
     */
    public const APPLIER_REASONS = ['invalid', 'quota_guard', 'missing', 'not_auto', 'failed'];

    /**
     * @param list<string> $tags
     */
    private function __construct(
        public string $kind,
        public string $content = '',
        public string $id = '',
        public string $scope = 'project',
        public string $type = 'pattern',
        public array $tags = [],
        public string $reason = '',
        public string $detail = '',
    ) {
    }

    /**
     * @param list<string> $tags
     */
    public static function add(string $content, string $scope = 'project', string $type = 'pattern', array $tags = []): self
    {
        return new self(self::ADD, content: $content, scope: $scope, type: $type, tags: $tags);
    }

    public static function update(string $id, string $content): self
    {
        return new self(self::UPDATE, content: $content, id: $id);
    }

    public static function delete(string $id): self
    {
        return new self(self::DELETE, id: $id);
    }

    public static function skip(string $reason, string $detail = ''): self
    {
        return new self(self::SKIP, reason: $reason, detail: $detail);
    }

    /** Whether this operation changes a note (anything but a skip). */
    public function mutates(): bool
    {
        return $this->kind !== self::SKIP;
    }
}
