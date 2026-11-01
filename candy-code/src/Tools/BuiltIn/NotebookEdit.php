<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Agents\PathJail as AgentPathJail;
use SugarCraft\Code\Notebook\Notebook;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\ToolOutputSpill;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Code\Tools\PathJail;
use SugarCraft\Code\Tools\ReadLedger;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Edit one cell of a Jupyter notebook by id (roadmap C-TOOL-6.b).
 *
 * A notebook is JSON, so `Edit` can already rewrite it — but only by matching
 * a raw `old_string` inside the serialized file, which means a base64 image
 * blob or a two-space indent shift defeats the match, and a hand-patched
 * `source` array routinely leaves the cell's `outputs` describing the PREVIOUS
 * source. Upstream's NotebookEdit made cell-by-id editing a distinct verb for
 * that reason, and this tool adopts its semantics: cells are addressed by
 * `cell_id` (or by `cell-<n>` index keys when the document predates ids), and
 * every edit rewrites the whole document through {@see Notebook}, which keeps
 * the cells it was not told about byte-identical — metadata, outputs and
 * attachments included.
 *
 * EDIT SEMANTICS, as a recorded lane decision (GAP-TL-15):
 *  - `replace` keeps the cell's `id` and `metadata` untouched, swaps its
 *    `source`, and — when the cell is a code cell — clears `outputs` and nulls
 *    `execution_count`, because the recorded results describe the OLD source
 *    and cannot be honestly kept. Replacing with a `cell_type` flips the cell
 *    kind: going to `code` adds an empty `outputs` and a null
 *    `execution_count`; going to `markdown` drops both, since neither has
 *    meaning outside a code cell.
 *  - `insert` places a new cell AFTER `cell_id`, or at the front when no
 *    `cell_id` is given, and requires `cell_type` (there is nothing to infer
 *    the kind from). On nbformat >= 4.5 the new cell is minted a fresh 8-hex
 *    `id`, the same shape nbformat's own `random_id` writes; on 4.4 documents
 *    no id is added, because none is expected there.
 *  - `delete` removes the cell and leaves the rest of the document untouched.
 *  - A document that is not nbformat v4, or whose JSON does not parse, is
 *    REFUSED outright — nothing is written. nbformat 3 reshapes the whole
 *    document (worksheets, `input` instead of `source`), and a tool that
 *    "edited" those by guessing would be rewriting a format it does not
 *    model.
 *
 * NOT {@see \SugarCraft\Code\Tools\ParallelSafe}, following the same rule as
 * {@see Edit} and {@see Write}: it mutates the workspace, and an orphaned fork
 * of a parallel group has no deadline left to stop it.
 *
 * The staleness and read-before-write doors, and their exact wording, are
 * Edit's (roadmap 3.I-2 / C-TOOL-5): the model edits a notebook it has READ —
 * Read answers `.ipynb` as cell pages and records the RAW bytes in the ledger,
 * so the hash compared here is of the same file content this tool re-reads.
 */
#[BuiltInTool(name: 'NotebookEdit', permission: ToolPermissionClass::Write, position: 48)]
final readonly class NotebookEdit implements Tool, AcceptsWorktreeJail, BuildsFromCatalog
{
    use RebindsWorktreeJail;

    /**
     * Largest notebook this tool parses — the same ceiling as Edit's and
     * Read's default, and for the same reason: past it the JSON could still
     * parse but the read is not trusted to be the whole picture.
     */
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    /** The three cell edits this tool performs. */
    public const EDIT_MODES = ['replace', 'insert', 'delete'];

    /** The two cell kinds a notebook edit may write. */
    public const CELL_TYPES = ['code', 'markdown'];

    /**
     * $writeSeam is a TEST SEAM only, the same shape as {@see Write}'s and
     * {@see Edit}'s: the payload writer handed to
     * {@see AtomicFileWriter::replace()}, so a test can fail a write part-way
     * and prove the original notebook bytes survive. Production passes null.
     */
    public function __construct(
        private ?string $root = null,
        private ?AgentPathJail $worktreeJail = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?\Closure $writeSeam = null,
        private ?ReadLedger $readLedger = null,
        private bool $requireReadBeforeWrite = false,
    ) {}

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root, readLedger: $context->readLedger);
    }

    /** The read ledger this instance checks and records into, or null. */
    public function readLedger(): ?ReadLedger
    {
        return $this->readLedger;
    }

    /**
     * This tool with the read-before-write rule claimed (roadmap C-TOOL-5,
     * honoured here as it is by Edit and Write). Same shape as
     * {@see Edit::withRequireReadBeforeWrite()}: every property is a promoted
     * constructor argument, so the object vars round-trip.
     */
    public function withRequireReadBeforeWrite(bool $on): self
    {
        return new self(...array_replace(get_object_vars($this), ['requireReadBeforeWrite' => $on]));
    }

    public function name(): string
    {
        return 'NotebookEdit';
    }

    public function description(): string
    {
        return 'Replace, insert or delete ONE cell of a Jupyter notebook (.ipynb), addressed by its cell id. '
            . 'Cells are addressed by the id Read shows (or `cell-0`, `cell-1`, ... index keys when the notebook predates nbformat 4.5 ids). '
            . 'A replaced code cell has its outputs cleared and execution_count nulled, because the recorded results describe the previous source; '
            . 'every cell this edit does not touch is kept byte-identical, metadata, outputs and attachments included. '
            . 'insert requires cell_type and places the new cell after cell_id (or first when no cell_id is given), minting an id on nbformat 4.5+. '
            . 'Only nbformat v4 notebooks are accepted; anything else is refused and nothing is written.'
            . ($this->readLedger === null
                ? ''
                : ' If the notebook changed on disk after you last read it — by the user, a formatter or a command — the edit is refused until you read it again; file left unchanged.')
            . ($this->readLedger === null || !$this->requireReadBeforeWrite
                ? ''
                : ' Editing a notebook the session has never read is refused; read it first.');
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Path of the .ipynb notebook to edit',
                ],
                'edit_mode' => [
                    'type' => 'string',
                    'enum' => self::EDIT_MODES,
                    'description' => 'replace rewrites the addressed cell, insert adds a new cell after cell_id (or first), delete removes the addressed cell',
                ],
                'cell_id' => [
                    'type' => 'string',
                    'description' => 'The cell to address: its id (Read shows it in the cell header) or its `cell-<n>` index key. Required for replace and delete; the anchor for insert.',
                ],
                'new_source' => [
                    'type' => 'string',
                    'description' => 'The cell source to write, for replace and insert. An empty string empties the cell.',
                ],
                'cell_type' => [
                    'type' => 'string',
                    'enum' => self::CELL_TYPES,
                    'description' => 'Required for insert; on replace it flips the addressed cell to the given kind.',
                ],
            ],
            'required' => ['file_path', 'edit_mode'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $id = $args['id'] ?? '';
        $path = $args['file_path'] ?? '';
        if (!is_string($path) || $path === '') {
            return new ToolResult(toolCallId: $id, content: 'Error: file_path is required and must name a notebook file', isError: true);
        }

        $mode = $args['edit_mode'] ?? '';
        if (!is_string($mode) || !in_array($mode, self::EDIT_MODES, true)) {
            return new ToolResult(toolCallId: $id, content: 'Error: edit_mode must be one of: ' . implode(', ', self::EDIT_MODES), isError: true);
        }

        // NUL-byte guard first, before anything reaches the filesystem: the
        // jail calls realpath(), which ValueError-crashes on an embedded NUL.
        // Same door Edit and Read hold.
        if (str_contains($path, "\0")) {
            return new ToolResult(toolCallId: $id, content: 'Error: file_path contains a NUL byte', isError: true);
        }

        // C-BUG-12: the jail decides, not the root string — the shape is
        // Edit's and Write's, byte for byte.
        if ($this->worktreeJail !== null) {
            $resolved = $this->worktreeJail->resolve($path);
            if ($resolved === null || !file_exists($resolved)) {
                return new ToolResult(toolCallId: $id, content: 'Error: path outside worktree', isError: true);
            }
            $path = $resolved;
        } elseif ($this->root !== null && PathJail::resolve($this->root, $path) === null) {
            return new ToolResult(toolCallId: $id, content: 'Error: path outside workspace root', isError: true);
        }

        if (ToolOutputSpill::readablePath($path) !== null) {
            return new ToolResult(toolCallId: $id, content: "Error: $path is saved tool output and is read-only; Read or Grep it instead", isError: true);
        }

        if (!file_exists($path)) {
            return new ToolResult(toolCallId: $id, content: "Error: file not found: $path", isError: true);
        }
        if (!is_file($path)) {
            // Not a regular file — a FIFO would BLOCK the read below open
            // (directory shapes fail through Notebook::parse's refusal).
            return new ToolResult(toolCallId: $id, content: "Error: not a regular file: $path", isError: true);
        }

        $cellId = $args['cell_id'] ?? null;
        $newSource = $args['new_source'] ?? null;
        $cellType = $args['cell_type'] ?? null;
        foreach (['cell_id' => $cellId, 'new_source' => $newSource, 'cell_type' => $cellType] as $label => $value) {
            if ($value !== null && !is_string($value)) {
                return new ToolResult(toolCallId: $id, content: "Error: $label must be a string when given", isError: true);
            }
        }

        if ($mode !== 'insert' && ($cellId === null || $cellId === '')) {
            return new ToolResult(toolCallId: $id, content: "Error: cell_id is required for a $mode edit (Read shows it in each cell header)", isError: true);
        }
        if ($mode === 'insert' && ($cellType === null || !in_array($cellType, self::CELL_TYPES, true))) {
            return new ToolResult(toolCallId: $id, content: 'Error: an insert edit requires cell_type, one of: ' . implode(', ', self::CELL_TYPES), isError: true);
        }
        if ($mode !== 'delete' && $newSource === null) {
            return new ToolResult(toolCallId: $id, content: "Error: new_source is required for a $mode edit", isError: true);
        }
        if ($cellType !== null && !in_array($cellType, self::CELL_TYPES, true)) {
            return new ToolResult(toolCallId: $id, content: 'Error: cell_type must be one of: ' . implode(', ', self::CELL_TYPES), isError: true);
        }

        // The read-before-write rule (roadmap C-TOOL-5): on, a notebook the
        // session never read through Read is refused outright.
        if ($this->requireReadBeforeWrite && $this->readLedger !== null && !$this->readLedger->has($path)) {
            return new ToolResult(toolCallId: $id, content: self::unreadRefusal($path), isError: true);
        }

        if (($size = filesize($path)) !== false && $size > $this->maxBytes) {
            return new ToolResult(toolCallId: $id, content: $this->tooLarge($path, $size), isError: true);
        }
        $limit = $this->maxBytes < PHP_INT_MAX ? $this->maxBytes + 1 : null;
        $original = file_get_contents($path, false, null, 0, $limit);
        if ($original === false || strlen($original) > $this->maxBytes) {
            return new ToolResult(toolCallId: $id, content: $original === false ? "Error reading file: $path" : $this->tooLarge($path, strlen($original)), isError: true);
        }

        // The same staleness door Edit holds, on the same bytes — Read's cell
        // pages observe the RAW file, so the hash this compares is of the
        // exact notebook the model saw.
        $stale = $this->readLedger?->staleness($path, $original);
        if ($stale !== null) {
            return new ToolResult(toolCallId: $id, content: self::staleRefusal($path), isError: true);
        }

        $notebook = Notebook::parse($original);
        if ($notebook === null) {
            // Refused outright, nothing written: this is not an nbformat v4
            // notebook this tool may model — text editing its JSON is what
            // `Edit` and `Write` are for.
            return new ToolResult(toolCallId: $id, content: "Error: $path is not a parseable nbformat v4 notebook; nothing was written", isError: true);
        }

        $summary = '';
        switch ($mode) {
            case 'replace':
                $before = null;
                foreach ($notebook->describe() as $entry) {
                    if ($entry['key'] === $cellId) {
                        $before = $entry;
                        break;
                    }
                }
                $updated = $notebook->replace((string) $cellId, (string) $newSource, $cellType);
                if ($updated === null) {
                    return new ToolResult(toolCallId: $id, content: $this->unknownCell($path, (string) $cellId, $notebook), isError: true);
                }
                $summary = 'replaced cell ' . $cellId . ' as ' . ($cellType ?? $before['type']);
                break;
            case 'insert':
                $placed = $notebook->insert($cellId === '' ? null : $cellId, (string) $newSource, (string) $cellType);
                if ($placed === null) {
                    return new ToolResult(toolCallId: $id, content: $this->unknownCell($path, (string) $cellId, $notebook), isError: true);
                }
                [$updated, $index] = $placed;
                $summary = 'inserted a new ' . $cellType . ' cell at position ' . $index . ' (' . $updated->cellKeys()[$index] . ')';
                break;
            default:
                $updated = $notebook->delete((string) $cellId);
                if ($updated === null) {
                    return new ToolResult(toolCallId: $id, content: $this->unknownCell($path, (string) $cellId, $notebook), isError: true);
                }
                $summary = 'deleted cell ' . $cellId;
                break;
        }

        try {
            $serialized = $updated->serialize();
        } catch (\InvalidArgumentException) {
            return new ToolResult(toolCallId: $id, content: "Error: the edited notebook could not be serialized; file left unchanged", isError: true);
        }

        try {
            AtomicFileWriter::replace($path, $serialized, $this->writeSeam);
        } catch (\RuntimeException) {
            return new ToolResult(toolCallId: $id, content: "Error writing file: $path", isError: true);
        }

        // The write just landed IS the model's new picture of the file (the
        // ledger law Edit and Write keep): record it, so the next edit is not
        // refused as stale against the model's own change.
        $this->readLedger?->record($path, $serialized);

        return new ToolResult(toolCallId: $id, content: "Notebook updated: $path — $summary");
    }

    /** Why an unknown cell address is refused, naming what IS addressable. */
    private function unknownCell(string $path, string $cellId, Notebook $notebook): string
    {
        return "Error: cell '$cellId' was not found in $path; the notebook holds: "
            . implode(', ', $notebook->cellKeys());
    }

    /**
     * Why a notebook changed behind the model's back is refused — Edit's
     * family wording verbatim (roadmap 3.I-2): the file must not be half-
     * trusted, so the model re-reads and re-applies.
     */
    private static function staleRefusal(string $path): string
    {
        return "Error: {$path} changed on disk since you last read it (an edit by the user, a formatter or a command); "
            . 'Read it again before editing it; file left unchanged';
    }

    /**
     * Why a notebook the session never read is refused under the
     * read-before-write setting — Edit's family wording verbatim.
     */
    private static function unreadRefusal(string $path): string
    {
        return "Error: {$path} has not been read in this session; Read it first; file left unchanged";
    }

    /** The over-cap refusal — Edit's family wording, notebook-flavoured. */
    private function tooLarge(string $path, int $size): string
    {
        return sprintf(
            'Error: notebook too large to edit: %s is %s bytes, over the %s-byte NotebookEdit limit (maxBytes); file left unchanged',
            $path,
            number_format($size),
            number_format($this->maxBytes),
        );
    }
}
