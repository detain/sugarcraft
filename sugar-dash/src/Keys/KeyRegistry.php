<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Keys;

use SugarCraft\Dash\Events\Key;

/**
 * Central registry for key bindings.
 *
 * Provides a single source of truth for key-to-action mappings
 * across the application. Used for status bar display, help modals,
 * and global shortcut handling.
 *
 * Mirrors the teautils KeyRegistry pattern.
 */
final class KeyRegistry
{
    /** @var array<string, KeyMeta> */
    private static array $bindings = [];

    /** @var array<string, list<string>> */
    private static array $byCategory = [];

    /**
     * Register a key binding.
     *
     * @param KeyIdentifier $id Unique identifier for this binding
     * @param Key $key Key combination
     * @param string $description Human-readable description
     * @param string $category Category (e.g., 'navigation', 'editing')
     */
    public static function register(KeyIdentifier $id, Key $key, string $description, string $category = 'general'): void
    {
        $meta = new KeyMeta($id, $key, $description, $category);
        self::$bindings[$id->value] = $meta;

        if (!isset(self::$byCategory[$category])) {
            self::$byCategory[$category] = [];
        }
        self::$byCategory[$category][] = $id->value;
    }

    /**
     * Get a binding by ID.
     */
    public static function get(KeyIdentifier|string $id): ?KeyMeta
    {
        $idValue = $id instanceof KeyIdentifier ? $id->value : $id;
        return self::$bindings[$idValue] ?? null;
    }

    /**
     * Get all bindings for a category.
     *
     * @return list<KeyMeta>
     */
    public static function byCategory(string $category): array
    {
        $ids = self::$byCategory[$category] ?? [];
        return array_map(fn($id) => self::$bindings[$id], $ids);
    }

    /**
     * Get all registered categories.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_keys(self::$byCategory);
    }

    /**
     * Check if a key matches any registered binding.
     */
    public static function match(Key $key): ?KeyMeta
    {
        foreach (self::$bindings as $meta) {
            if ($meta->key->matches($key->key, $key->ctrl, $key->alt, $key->shift)) {
                return $meta;
            }
        }
        return null;
    }

    /**
     * Reset the registry (useful for testing).
     */
    public static function reset(): void
    {
        self::$bindings = [];
        self::$byCategory = [];
    }
}
