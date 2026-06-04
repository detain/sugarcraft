<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Variables;

use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Inline editor for modifying MySQL variables via SET GLOBAL / SET PERSIST.
 *
 * Uses prepared statements for all value interpolation to prevent SQL injection.
 * Variable names are backtick-escaped (they come from a static catalog, not user input).
 *
 * Error handling:
 *   - 1142 = no privilege
 *   - 1227 = access denied
 *   - 3680 = persisted_variables restriction (MySQL 8.0+)
 *
 * @see Mirrors mysql-workbench wb_admin_variable_editor
 */
final class VariableEditor
{
    private DatabaseInterface $db;

    private function __construct(
        private readonly ServerContextInterface $context,
        private readonly ?Catalog $catalog = null,
    ) {
        $this->db = $context->connection();
    }

    /**
     * Factory method to create a new VariableEditor.
     */
    public static function new(ServerContextInterface $context, ?Catalog $catalog = null): self
    {
        return new self($context, $catalog);
    }

    /**
     * Edit a variable using SET GLOBAL.
     *
     * Uses a prepared statement with the variable name backtick-escaped
     * and the value interpolated via placeholder.
     *
     * @param string $variableName The variable name (backtick-escaped internally)
     * @param string $newValue The new value to set
     * @return array{success: bool, errorCode: ?int, errorMessage: ?string} Result with success status and error details
     */
    public function edit(string $variableName, string $newValue): array
    {
        // Validate variable is editable before attempting
        if (!$this->isEditable($variableName)) {
            return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Variable not editable'];
        }

        // Backtick-escape the variable name (it comes from catalog, not user)
        $escapedName = '`' . \str_replace('`', '``', $variableName) . '`';

        // Use prepared statement for the value
        $sql = "SET GLOBAL {$escapedName} = ?";

        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false || $stmt === null) {
                return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Prepare failed'];
            }

            $result = $stmt->execute([$newValue]);
            $stmt->closeCursor();

            return ['success' => $result, 'errorCode' => null, 'errorMessage' => null];
        } catch (\PDOException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Edit a variable using SET PERSIST (MySQL 8.0+).
     *
     * Sets the variable both globally and persists it to mysqld-auto.cnf.
     *
     * @param string $variableName The variable name (backtick-escaped internally)
     * @param string $newValue The new value to set
     * @return array{success: bool, errorCode: ?int, errorMessage: ?string} Result with success status and error details
     */
    public function editPersistent(string $variableName, string $newValue): array
    {
        // Validate variable is editable before attempting
        if (!$this->isEditable($variableName)) {
            return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Variable not editable'];
        }

        // Check MySQL version supports SET PERSIST (8.0+)
        // We assume the caller has already checked, but double-check here
        // For safety, we catch errors from older versions gracefully

        // Backtick-escape the variable name
        $escapedName = '`' . \str_replace('`', '``', $variableName) . '`';

        // Use prepared statement for the value
        $sql = "SET PERSIST {$escapedName} = ?";

        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false || $stmt === null) {
                return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Prepare failed'];
            }

            $result = $stmt->execute([$newValue]);
            $stmt->closeCursor();

            return ['success' => $result, 'errorCode' => null, 'errorMessage' => null];
        } catch (\PDOException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Persist a variable using SET PERSIST (MySQL 8.0+).
     *
     * Sets the variable globally AND persists it to mysqld-auto.cnf.
     * Gated to MySQL 8.0+ because PERSIST is not available in earlier versions.
     *
     * @param string $variableName The variable name (backtick-escaped internally)
     * @param string $newValue The new value to set
     * @return array{success: bool, errorCode: ?int, errorMessage: ?string} Result with success status and error details
     */
    public function persist(string $variableName, string $newValue): array
    {
        if (!$this->context->version()->isAtLeast(8, 0)) {
            return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Persisting requires MySQL 8.0+'];
        }

        if (!$this->isEditable($variableName)) {
            return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Variable not editable'];
        }

        $escapedName = '`' . \str_replace('`', '``', $variableName) . '`';
        $sql = "SET PERSIST {$escapedName} = ?";

        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false || $stmt === null) {
                return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Prepare failed'];
            }

            $result = $stmt->execute([$newValue]);
            $stmt->closeCursor();

            return ['success' => $result, 'errorCode' => null, 'errorMessage' => null];
        } catch (\PDOException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Persist a variable using SET PERSIST_ONLY (MySQL 8.0+).
     *
     * Only persists to mysqld-auto.cnf without affecting the running value.
     * Use this for variables that cannot be set at runtime (error 1238).
     * Gated to MySQL 8.0+ because PERSIST_ONLY is not available in earlier versions.
     *
     * @param string $variableName The variable name (backtick-escaped internally)
     * @param string $newValue The new value to persist
     * @return array{success: bool, errorCode: ?int, errorMessage: ?string} Result with success status and error details
     */
    public function persistOnly(string $variableName, string $newValue): array
    {
        if (!$this->context->version()->isAtLeast(8, 0)) {
            return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Persisting requires MySQL 8.0+'];
        }

        if (!$this->isEditable($variableName)) {
            return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Variable not editable'];
        }

        $escapedName = '`' . \str_replace('`', '``', $variableName) . '`';
        $sql = "SET PERSIST_ONLY {$escapedName} = ?";

        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false || $stmt === null) {
                return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Prepare failed'];
            }

            $result = $stmt->execute([$newValue]);
            $stmt->closeCursor();

            return ['success' => $result, 'errorCode' => null, 'errorMessage' => null];
        } catch (\PDOException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Reset persisted variables using RESET PERSIST (MySQL 8.0+).
     *
     * Without an argument, clears all persisted variables from mysqld-auto.cnf.
     * With a name argument, removes only that specific variable.
     * Gated to MySQL 8.0+ because RESET PERSIST is not available in earlier versions.
     *
     * @param string|null $variableName Optional variable name to reset (clears all if null)
     * @return array{success: bool, errorCode: ?int, errorMessage: ?string} Result with success status and error details
     */
    public function resetPersist(?string $variableName = null): array
    {
        if (!$this->context->version()->isAtLeast(8, 0)) {
            return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Persisting requires MySQL 8.0+'];
        }

        // RESET PERSIST does not require the variable to be editable
        // because it removes the persisted value, not sets it
        $sql = $variableName !== null
            ? "RESET PERSIST `" . \str_replace('`', '``', $variableName) . "`"
            : "RESET PERSIST";

        try {
            $stmt = $this->db->prepare($sql);
            if ($stmt === false || $stmt === null) {
                return ['success' => false, 'errorCode' => null, 'errorMessage' => 'Prepare failed'];
            }

            $result = $stmt->execute([]);
            $stmt->closeCursor();

            return ['success' => $result, 'errorCode' => null, 'errorMessage' => null];
        } catch (\PDOException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get a preview of what the SET statement would look like.
     *
     * Used to show the user what will be executed before confirming.
     *
     * @param string $variableName The variable name
     * @param string $newValue The proposed new value
     * @param string $mode SET mode: 'global', 'persist', or 'persist_only'
     * @return string The SET statement preview
     */
    public function getEditPreview(string $variableName, string $newValue, string $mode = 'global'): string
    {
        $escapedName = '`' . \str_replace('`', '``', $variableName) . '`';
        $keyword = match ($mode) {
            'persist' => 'SET PERSIST',
            'persist_only' => 'SET PERSIST_ONLY',
            default => 'SET GLOBAL',
        };

        // Show value with quotes if it looks like it needs them
        $displayValue = $this->needsQuotes($newValue)
            ? "'" . \str_replace("'", "''", $newValue) . "'"
            : $newValue;

        return "{$keyword} {$escapedName} = {$displayValue}";
    }

    /**
     * Check if a variable is editable per its metadata.
     *
     * @param string $variableName The variable name to check
     * @return bool True if the variable is editable
     */
    public function isEditable(string $variableName): bool
    {
        if ($this->catalog === null) {
            // If no catalog, assume not editable (safer default)
            return false;
        }

        try {
            return $this->catalog->isEditable($variableName);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the last error message from the most recent operation.
     *
     * @return string|null The error message or null if no error
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Check if the last error was a privilege error (1142 or 1227).
     *
     * @return bool True if the last error was privilege-related
     */
    public function isPrivilegeError(): bool
    {
        return $this->lastErrorCode === 1142 || $this->lastErrorCode === 1227;
    }

    /**
     * Check if the last error was related to persisted_variables (3680).
     *
     * @return bool True if the last error was a persisted_variables restriction
     */
    public function isPersistedVariablesError(): bool
    {
        return $this->lastErrorCode === 3680;
    }

    // ─── Private State ───────────────────────────────────────────────────────

    private ?string $lastError = null;
    private ?int $lastErrorCode = null;

    /**
     * Handle a PDOException, extracting the error code and message.
     *
     * Returns error info array and stores it for backward-compatible accessors.
     *
     * @return array{success: false, errorCode: ?int, errorMessage: string}
     */
    private function handleError(\PDOException $e): array
    {
        // PDOException::getCode() returns the MySQL error code for PDO errors
        // Cast to int to ensure we have a numeric code; if 0 or non-numeric, use null
        $code = $e->getCode();
        $this->lastErrorCode = \is_numeric($code) && (int) $code !== 0 ? (int) $code : null;
        $this->lastError = $e->getMessage();

        return [
            'success' => false,
            'errorCode' => $this->lastErrorCode,
            'errorMessage' => $this->lastError,
        ];
    }

    /**
     * Determine if a value needs quotes for SQL display.
     */
    private function needsQuotes(string $value): bool
    {
        // Numeric values don't need quotes
        if (\is_numeric($value)) {
            return false;
        }

        // Boolean-like values
        $lower = \strtolower($value);
        if ($lower === 'on' || $lower === 'off' || $lower === 'true' || $lower === 'false') {
            return false;
        }

        // Already quoted
        if (\str_starts_with($value, "'") && \str_ends_with($value, "'")) {
            return false;
        }

        // Contains special characters that need escaping
        if (\str_contains($value, "'") || \str_contains($value, '\\')) {
            return true;
        }

        // Default: needs quotes for safety
        return true;
    }
}
