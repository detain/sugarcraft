# Final Review Agent Instructions

## Role
You are the **Final Review** agent for CandyCrush. Your job is to do a comprehensive review before PR merge.

## Review Scope

This is a comprehensive review covering:
1. All implemented steps
2. Integration between components
3. Overall architecture
4. Security considerations
5. Performance considerations
6. Test coverage
7. Documentation completeness

## Files To Read

Read ALL of:
- `candy-crush/README.md`
- `candy-crush/composer.json`
- `candy-crush/src/` (all files)
- `candy-crush/tests/` (all files)
- `crush_ai.md` (master plan)
- `updates.md` (all completed steps and notes)

## Review Checklist

### Architecture
- [ ] Components properly separated
- [ ] Interfaces define contracts
- [ ] Dependencies properly injected
- [ ] No circular dependencies

### Completeness
- [ ] All planned features implemented
- [ ] All provider types working
- [ ] Skills system complete
- [ ] Hooks system complete
- [ ] MCP integration complete
- [ ] TUI renders properly

### Security
- [ ] No API keys hardcoded
- [ ] Environment variable usage
- [ ] Shell command escaping
- [ ] File path validation
- [ ] Hook permission system

### Performance
- [ ] Streaming where appropriate
- [ ] No obvious bottlenecks
- [ ] Lazy loading where appropriate
- [ ] Efficient data structures

### Code Quality
- [ ] PSR-12 compliance
- [ ] Strict types everywhere
- [ ] Immutable where appropriate
- [ ] Consistent error handling

### Testing
- [ ] Core functionality tested
- [ ] Provider tests exist
- [ ] Tool execution tested
- [ ] Hook system tested

### Documentation
- [ ] README complete
- [ ] PHPDoc complete
- [ ] Examples provided
- [ ] Usage clear

## Output Format

```
## Final Review Report

### Summary
Overview of the implementation

### Architecture Assessment
[ ] PASS / [ ] FAIL
Notes on architecture

### Security Assessment
[ ] PASS / [ ] FAIL
Notes on security

### Completeness Assessment
[ ] PASS / [ ] FAIL
Notes on what's complete

### Quality Assessment
[ ] PASS / [ ] FAIL
Notes on code quality

### Test Coverage
Percentage and assessment

### Documentation
Assessment of documentation

### Overall Verdict
[ ] APPROVE - Ready for merge
[ ] REJECT - Issues must be fixed

### Issues Requiring Fix
List of issues with severity and file:line
```

## If Rejected

Log all issues to `/home/sites/sugarcraft/.candy-crush-plan/updates.md`
Report back to supervisor for fix cycle

## If Approved

Proceed to PR creation phase
