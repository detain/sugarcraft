<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * Session persistence for sugar-crush.
 *
 * Stores and restores session state to ~/.config/sugarcraft-crush/session.json.
 * Gracefully handles missing or corrupted session files by returning a
 * fresh empty session rather than propagating errors.
 *
 * Persisted state:
 *   - cwd         — current working directory
 *   - selected    — list of selected file paths
 *   - filter      — active filter string
 *   - sortColumn  — sort column name
 *   - sortDir     — sort direction (asc|desc)
 *   - activePane  — active pane identifier
 *
 * @mirrors charmbracelet/<repo>.Session
 */
final class Session
{
    private const SESSION_FILE = '.config/sugarcraft-crush/session.json';

    /**
     * @param string $cwd        Current working directory
     * @param list<string> $selected Selected file paths
     * @param string $filter     Active filter string
     * @param string $sortColumn Sort column name
     * @param string $sortDir    Sort direction (asc|desc)
     * @param string $activePane Active pane identifier
     */
    public function __construct(
        public readonly string $cwd = '',
        public readonly array $selected = [],
        public readonly string $filter = '',
        public readonly string $sortColumn = 'name',
        public readonly string $sortDir = 'asc',
        public readonly string $activePane = 'files',
    ) {
    }

    /**
     * Load session state from the JSON file.
     *
     * If the file does not exist or is corrupted, returns a fresh
     * empty Session rather than throwing.
     */
    public static function load(): self
    {
        $path = self::sessionFilePath();

        // Guard: file does not exist → fresh session
        if (!is_file($path)) {
            return new self();
        }

        // Guard: readable → parse content
        $content = @file_get_contents($path);
        if ($content === false) {
            return new self();
        }

        // Guard: valid JSON → decode
        /** @var array<string, mixed>|null $data */
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }
        if (!is_array($data)) {
            return new self();
        }

        return new self(
            cwd: self::string($data, 'cwd', ''),
            selected: self::stringList($data, 'selected', []),
            filter: self::string($data, 'filter', ''),
            sortColumn: self::string($data, 'sortColumn', 'name'),
            sortDir: self::string($data, 'sortDir', 'asc'),
            activePane: self::string($data, 'activePane', 'files'),
        );
    }

    /**
     * Save session state to the JSON file.
     *
     * Creates the directory hierarchy if it does not exist.
     * Errors are suppressed — save failures are silent to avoid
     * disrupting the user session.
     */
    public function save(): void
    {
        $path = self::sessionFilePath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $data = [
            'cwd' => $this->cwd,
            'selected' => $this->selected,
            'filter' => $this->filter,
            'sortColumn' => $this->sortColumn,
            'sortDir' => $this->sortDir,
            'activePane' => $this->activePane,
        ];

        @file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /**
     * @return $this Fresh instance with updated cwd
     */
    public function withCwd(string $cwd): self
    {
        return new self(
            cwd: $cwd,
            selected: $this->selected,
            filter: $this->filter,
            sortColumn: $this->sortColumn,
            sortDir: $this->sortDir,
            activePane: $this->activePane,
        );
    }

    /**
     * @param list<string> $selected
     * @return $this Fresh instance with updated selected files
     */
    public function withSelected(array $selected): self
    {
        return new self(
            cwd: $this->cwd,
            selected: $selected,
            filter: $this->filter,
            sortColumn: $this->sortColumn,
            sortDir: $this->sortDir,
            activePane: $this->activePane,
        );
    }

    /**
     * @return $this Fresh instance with updated filter
     */
    public function withFilter(string $filter): self
    {
        return new self(
            cwd: $this->cwd,
            selected: $this->selected,
            filter: $filter,
            sortColumn: $this->sortColumn,
            sortDir: $this->sortDir,
            activePane: $this->activePane,
        );
    }

    /**
     * @return $this Fresh instance with updated sort
     */
    public function withSort(string $column, string $direction): self
    {
        return new self(
            cwd: $this->cwd,
            selected: $this->selected,
            filter: $this->filter,
            sortColumn: $column,
            sortDir: $direction,
            activePane: $this->activePane,
        );
    }

    /**
     * @return $this Fresh instance with updated active pane
     */
    public function withActivePane(string $pane): self
    {
        return new self(
            cwd: $this->cwd,
            selected: $this->selected,
            filter: $this->filter,
            sortColumn: $this->sortColumn,
            sortDir: $this->sortDir,
            activePane: $pane,
        );
    }

    /**
     * Resolve the session file path under the user's home directory.
     */
    private static function sessionFilePath(): string
    {
        $home = self::homeDirectory();
        return $home . '/' . self::SESSION_FILE;
    }

    /**
     * Return the home directory, falling back to environment detection.
     */
    private static function homeDirectory(): string
    {
        // Try environment variable first (Early Exit)
        $envHome = getenv('HOME');
        if ($envHome !== false && $envHome !== '') {
            return $envHome;
        }

        // Fall back to posix_get_home for POSIX systems
        $posixHome = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        if ($posixHome !== null) {
            return $posixHome;
        }

        // Last resort: current directory (should rarely happen)
        return getcwd() ?: '/tmp';
    }

    /**
     * Extract a string field from decoded session data.
     *
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key, string $default): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : $default;
    }

    /**
     * Extract a list-of-strings field from decoded session data.
     *
     * @param array<string, mixed> $data
     * @param list<string> $default
     * @return list<string>
     */
    private static function stringList(array $data, string $key, array $default): array
    {
        if (!is_array($data[$key] ?? null)) {
            return $default;
        }
        /** @var list<string> */
        return array_values(array_filter(
            $data[$key],
            static fn(mixed $v): bool => is_string($v),
        ));
    }
}
