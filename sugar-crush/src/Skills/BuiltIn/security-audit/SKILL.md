---
description: Security audit for PHP code. Check for SQL injection, XSS, CSRF, authentication issues, and other vulnerabilities.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash"
effort: high
paths:
  - "**/*.php"
---
# Security Audit Skill

When auditing PHP code for security, check:

## SQL Injection
- All database queries use prepared statements
- No user input concatenated into SQL
- Use parameter binding consistently

## XSS (Cross-Site Scripting)
- All output escaped with htmlspecialchars()
- Content-Type headers set appropriately
- CSP headers configured

## CSRF (Cross-Site Request Forgery)
- CSRF tokens on all state-changing forms
- Token validation on POST/PUT/DELETE
- SameSite cookies configured

## Authentication
- Passwords hashed with password_hash()
- Use bcrypt, argon2i, or argon2id
- Never store plain text passwords
- Session regeneration on login

## Input Validation
- Validate all user input
- Use allow-lists over block-lists
- Sanitize before output, validate before use

## File Operations
- No direct user input in file paths
- Validate file types and sizes
- Secure upload handling
- Path traversal prevention
