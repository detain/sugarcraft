<?php

declare(strict_types=1);

namespace CandyCore\Query;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * SQLite browser as a CandyCore Model. Three panes:
 *
 *   - Tables: a list of tables. Enter loads its rows into the
 *             rows pane; the rows pane's title updates accordingly.
 *   - Rows:   a paginated peek at the selected table's contents.
 *   - Query:  an editor — type SQL, Ctrl+Enter to run. Errors land
 *             on a status line; the rowset replaces the rows pane.
 *
 * Tab cycles focus; j/k or arrows move the cursor in list panes;
 * `q` quits.
 */
final class App implements Model
{
    /**
     * @param list<string> $tables
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(
        public readonly Database $db,
        public readonly array $tables = [],
        public readonly int $tableCursor = 0,
        public readonly ?string $selectedTable = null,
        public readonly array $rows = [],
        public readonly int $rowCursor = 0,
        public readonly string $queryBuf = '',
        public readonly Pane $pane = Pane::Tables,
        public readonly ?string $error = null,
        public readonly ?string $status = null,
    ) {}

    public static function start(Database $db): self
    {
        $tables = $db->tables();
        $a = new self(db: $db, tables: $tables);
        if ($tables !== []) {
            $a = $a->loadTable($tables[0]);
        }
        return $a;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q' && $this->pane !== Pane::Query)
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Tab) {
            return [$this->withPane($this->pane->next()), null];
        }
        if ($this->pane === Pane::Query) {
            return [$this->editQuery($msg), null];
        }
        if ($this->pane === Pane::Tables) {
            return [$this->handleTablesKey($msg), null];
        }
        return [$this->handleRowsKey($msg), null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    private function handleTablesKey(KeyMsg $msg): self
    {
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->withTableCursor($this->tableCursor - 1);
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->withTableCursor($this->tableCursor + 1);
        }
        if ($msg->type === KeyType::Enter
            || $msg->type === KeyType::Space) {
            $name = $this->tables[$this->tableCursor] ?? null;
            return $name === null ? $this : $this->loadTable($name);
        }
        return $this;
    }

    private function handleRowsKey(KeyMsg $msg): self
    {
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return new self(
                db: $this->db, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: max(0, $this->rowCursor - 1),
                queryBuf: $this->queryBuf, pane: $this->pane,
                error: $this->error, status: $this->status,
            );
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return new self(
                db: $this->db, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: min(max(0, count($this->rows) - 1), $this->rowCursor + 1),
                queryBuf: $this->queryBuf, pane: $this->pane,
                error: $this->error, status: $this->status,
            );
        }
        return $this;
    }

    private function editQuery(KeyMsg $msg): self
    {
        if (($msg->ctrl && ($msg->rune === 'r' || $msg->rune === 'e'))
            || ($msg->type === KeyType::Enter && $msg->ctrl)) {
            return $this->runQuery();
        }
        if ($msg->type === KeyType::Backspace) {
            return $this->withQueryBuf(self::dropLast($this->queryBuf));
        }
        if ($msg->type === KeyType::Enter) {
            return $this->withQueryBuf($this->queryBuf . "\n");
        }
        if ($msg->type === KeyType::Space) {
            return $this->withQueryBuf($this->queryBuf . ' ');
        }
        if ($msg->type === KeyType::Char && !$msg->ctrl) {
            return $this->withQueryBuf($this->queryBuf . $msg->rune);
        }
        return $this;
    }

    private function runQuery(): self
    {
        if (trim($this->queryBuf) === '') {
            return $this;
        }
        try {
            $rows = $this->db->query($this->queryBuf);
            return new self(
                db: $this->db, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: '(query)', rows: $rows, rowCursor: 0,
                queryBuf: $this->queryBuf, pane: $this->pane,
                error: null, status: count($rows) . ' rows',
            );
        } catch (\PDOException $e) {
            return new self(
                db: $this->db, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
                pane: $this->pane,
                error: $e->getMessage(), status: null,
            );
        }
    }

    private function loadTable(string $name): self
    {
        try {
            $rows = $this->db->rows($name);
            return new self(
                db: $this->db, tables: $this->tables,
                tableCursor: array_search($name, $this->tables, true) ?: 0,
                selectedTable: $name, rows: $rows, rowCursor: 0,
                queryBuf: $this->queryBuf, pane: $this->pane,
                error: null, status: count($rows) . ' rows',
            );
        } catch (\PDOException $e) {
            return new self(
                db: $this->db, tables: $this->tables, tableCursor: $this->tableCursor,
                selectedTable: $this->selectedTable, rows: $this->rows,
                rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
                pane: $this->pane,
                error: $e->getMessage(), status: null,
            );
        }
    }

    private function withTableCursor(int $i): self
    {
        $size = count($this->tables);
        if ($size === 0) return $this;
        $i = max(0, min($size - 1, $i));
        return new self(
            db: $this->db, tables: $this->tables, tableCursor: $i,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
            pane: $this->pane, error: $this->error, status: $this->status,
        );
    }

    private function withPane(Pane $p): self
    {
        return new self(
            db: $this->db, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $this->queryBuf,
            pane: $p, error: $this->error, status: $this->status,
        );
    }

    private function withQueryBuf(string $buf): self
    {
        return new self(
            db: $this->db, tables: $this->tables, tableCursor: $this->tableCursor,
            selectedTable: $this->selectedTable, rows: $this->rows,
            rowCursor: $this->rowCursor, queryBuf: $buf,
            pane: $this->pane, error: $this->error, status: $this->status,
        );
    }

    private static function dropLast(string $s): string
    {
        if ($s === '') return $s;
        $i = strlen($s) - 1;
        while ($i > 0 && (ord($s[$i]) & 0xc0) === 0x80) {
            $i--;
        }
        return substr($s, 0, $i);
    }
}
