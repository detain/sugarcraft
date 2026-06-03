---
description: PHP best practices, PSR-12 compliance, type safety, and modern PHP patterns. Use when reviewing or writing PHP code.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash"
effort: high
paths:
  - "**/*.php"
---
# PHP Best Practices Skill

When working with PHP code, enforce these standards:

## Type Safety
- Always use `declare(strict_types=1);` at the top of every file
- Prefer union types and nullable types over docblocks
- Use `never`, `void`, `true` return types when appropriate

## PSR-12 Compliance
- Namespaces use uppercase
- Classes use PascalCase
- Methods and functions use camelCase
- Constants use UPPER_SNAKE_CASE
- Opening braces on same line, closing on new line
- 4 spaces for indentation

## Modern PHP Patterns
- Use readonly properties for immutable data
- Use constructor property promotion
- Use match expressions instead of switch
- Use nullsafe operator (?->) when appropriate
- Use anonymous classes for simple wrappers

## Error Handling
- Throw exceptions with meaningful messages
- Use specific exception types
- Always catch and handle or re-throw
- Never suppress errors with @

## Performance
- Use isset() over array_key_exists() for performance
- Prefer preallocation in loops
- Use yield for large iterables
- Cache require/include results