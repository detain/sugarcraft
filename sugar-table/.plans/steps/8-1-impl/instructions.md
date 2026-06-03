# Step 8.1: Implementation - Add Keyboard Navigation

## Goal
Add keyboard-based scrolling for the viewport so users can navigate with arrow keys and Page Up/Down.

## Background
The table has `withScrollY()` and `withViewportHeight()` for viewport scrolling, but no built-in keyboard controls. Users must programmatically call these methods.

## Implementation Tasks
1. Add a method `withKeyboardNavigation(bool $enable = true): self` to enable/disable
2. Consider adding an `onKeyPress(callable $handler): self` callback for custom handling
3. Document that keyboard navigation requires integration with an input library (candy-input or similar)
4. OR provide a simple static method that processes key events and returns updated table

## Key Concept
Rather than making Table responsible for input handling (which would require async/event loop integration), provide helper methods that make it easy to hook up keyboard navigation:

```php
// Helper to check if scroll changed based on key
$table = $table->handleKey(KeyMsg::arrowDown);  // Returns new table with scrollY adjusted

// Or a method that returns the new scroll position
$newScrollY = $table->scrollYForKey(KeyMsg::arrowDown);
```

## API Design Options

Option A - Table handles key:
```php
$t = $table->withKeyboardNavigation(true);
$t = $t->handleKey($keyMessage);  // Returns new table
```

Option B - Helper methods:
```php
// Returns updated scrollY based on key and viewport
$table = $table->withScrollY($table->scrollYForKey($key));
```

Option C - Document as integration required:
Just provide the scrollY getter/setter and document that users should wire up to their input system.

Recommend Option B - provide helper methods without requiring the table to own the event loop.

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
  - Add scrollYForKey() method
  - Maybe add handleKey() method

## Key Mappings
- ArrowUp -> scrollY - 1 (or row - 1)
- ArrowDown -> scrollY + 1 (or row + 1)
- PageUp -> scrollY - viewportHeight
- PageDown -> scrollY + viewportHeight
- Home -> scrollY = 0
- End -> scrollY = max

## Verification
- scrollYForKey returns correct new scrollY for each key
- Works with viewportHeight for Page Up/Down

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-keyboard-nav`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- Keep it simple - Table shouldn't own the event loop
- Focus on helper methods that make integration easy

## Exit Criteria
- Helper methods work correctly
- All existing tests still pass
- Code committed, PR created, merged to master
