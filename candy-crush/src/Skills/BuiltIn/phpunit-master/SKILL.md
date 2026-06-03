---
description: PHPUnit testing best practices, mocking, data providers, and test organization.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash"
effort: high
paths:
  - "**/*Test.php"
---
# PHPUnit Master Skill

When writing PHPUnit tests:

## Test Structure
- One assertion per test when possible
- Descriptive test names: testMethodNameDoesExpectedBehavior
- Follow Arrange-Act-Assert pattern
- Keep tests independent and isolated

## Mocking
- Mock external dependencies
- Use mockery for fluent mocking
- Configure expectations before assertions
- Avoid mocking what you're testing

## Data Providers
- Use data providers for parameterized tests
- Keep provider methods private or in same class
- Name data sets descriptively

## Fixtures
- Use setUp() for common fixtures
- Use tearDown() for cleanup
- Consider shared fixtures for expensive operations

## Coverage
- Aim for meaningful coverage, not 100%
- Test edge cases and error paths
- Mock database, filesystem, network