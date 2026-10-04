<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Compaction;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolResult;

/**
 * The files a stretch of conversation read and modified, derived MECHANICALLY
 * from its tool rows (roadmap 2.5) — never from what a model says it touched.
 *
 * A compaction summary is the only context a resumed conversation has, and
 * "which files did we change" is the one fact a summariser most often gets
 * subtly wrong (a path half-remembered, a file it only meant to edit). The
 * transcript already records it exactly: every built-in file tool lands a
 * {@see ToolResult} carrying the tool's name and its decoded arguments, so the
 * lists here are read straight off those rows and handed to
 * {@see StateSummaryTemplate} as derived sections the model is not asked to
 * write.
 *
 * WHAT COUNTS:
 *  - READ — a successful `Read`.
 *  - MODIFIED — a successful `Edit` or `Write`.
 *  - A refused or failed call touched nothing, so it counts for neither list.
 *  - Shell commands (`Bash`) are NOT parsed for paths: a guess at what a
 *    command line wrote is exactly the model-style inference this class exists
 *    to avoid.
 *
 * A file that was read and then modified is listed under modified only. Both
 * lists keep first-seen order and hold each path once.
 */
final class FilesTouched
{
    /** Tool names whose successful result means the file was read. */
    public const READ_TOOLS = ['Read'];

    /** Tool names whose successful result means the file was modified. */
    public const MODIFY_TOOLS = ['Edit', 'Write'];

    /** The argument key every built-in file tool names its path under. */
    public const PATH_ARGUMENT = 'file_path';

    /**
     * @param list<string> $read
     * @param list<string> $modified
     */
    private function __construct(
        public readonly array $read = [],
        public readonly array $modified = [],
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /**
     * Every file the tool rows of $history read or modified.
     *
     * @param list<Message> $history
     */
    public static function fromHistory(array $history): self
    {
        $touched = self::new();
        foreach ($history as $message) {
            foreach ($message->toolResults as $result) {
                if ($result instanceof ToolResult) {
                    $touched = $touched->withResult($result);
                }
            }
        }

        return $touched;
    }

    /** This set plus whatever $result read or modified. */
    public function withResult(ToolResult $result): self
    {
        if ($result->isError()) {
            return $this;
        }

        $path = $result->arguments[self::PATH_ARGUMENT] ?? null;
        if (!\is_string($path) || trim($path) === '') {
            return $this;
        }
        $path = trim($path);

        if (\in_array($result->name, self::MODIFY_TOOLS, true)) {
            return $this->withModified($path);
        }

        if (\in_array($result->name, self::READ_TOOLS, true)) {
            return $this->withRead($path);
        }

        return $this;
    }

    public function withRead(string $path): self
    {
        if (\in_array($path, $this->read, true) || \in_array($path, $this->modified, true)) {
            return $this;
        }

        return new self([...$this->read, $path], $this->modified);
    }

    public function withModified(string $path): self
    {
        if (\in_array($path, $this->modified, true)) {
            return $this;
        }

        return new self(
            array_values(array_filter($this->read, static fn (string $p): bool => $p !== $path)),
            [...$this->modified, $path],
        );
    }

    /** The union of both sets, $this first, a path modified in either listed as modified. */
    public function mergedWith(self $other): self
    {
        $merged = $this;
        foreach ($other->modified as $path) {
            $merged = $merged->withModified($path);
        }
        foreach ($other->read as $path) {
            $merged = $merged->withRead($path);
        }

        return $merged;
    }

    public function isEmpty(): bool
    {
        return $this->read === [] && $this->modified === [];
    }
}
