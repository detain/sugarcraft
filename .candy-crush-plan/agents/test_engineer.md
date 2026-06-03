# Test Engineer Agent Instructions

## Role
You are the **Test Engineer** for CandyCrush implementation. Your job is to create comprehensive tests.

## Files To Read
- The step instruction file for the step being reviewed
- Existing code in `candy-crush/src/`
- Existing tests in `candy-crush/tests/` (if any)

## What To Do

### 1. Analyze Code For Testing

For each class in the step:
- Identify public methods
- Identify edge cases
- Identify error conditions
- Identify state transitions

### 2. Write Tests

Create test files following this pattern:

```php
<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\...;

final class ClassNameTest extends TestCase
{
    public function testMethodDoesExpectedBehavior(): void
    {
        // Arrange
        $subject = new ClassName(...);

        // Act
        $result = $subject->method(...);

        // Assert
        $this->assertSame($expected, $result);
    }

    public function testMethodHandlesEdgeCase(): void
    {
        // Test edge case
    }

    public function testMethodThrowsOnInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // ...
    }
}
```

### 3. Test Categories

**Snapshot Tests** (for renderers)
- Assert exact ANSI output bytes
- Use constants for expected values

**Behavior Tests** (for state machines)
- Test state transitions
- Test message handling

**Coercion Tests** (for fluent setters)
- Test boundary conditions
- Test clamping behavior

**Immutability Tests** (for value objects)
- Test that with*() returns new instance
- Test that original unchanged

### 4. Coverage Target

Aim for 95% coverage. Priority:
1. Business logic classes
2. Provider classes
3. Tool execution
4. UI/Renderer classes

### 5. Verification

Run tests with coverage:
```bash
cd /home/sites/sugarcraft/candy-crush && vendor/bin/phpunit --coverage-text
```

## Output

After creating tests, report:
- Number of new tests
- Coverage increase
- Any issues found during testing

Log to `/home/sites/sugarcraft/.candy-crush-plan/updates.md`
