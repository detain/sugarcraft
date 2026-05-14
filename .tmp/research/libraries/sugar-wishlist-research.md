# SSH Endpoint Launcher Research: sugar-wishlist Improvements

**Date:** 2026-05-13
**Upstream:** charmbracelet/wishlist (Go)
**Focus:** PHP 8.3+ SSH shortcut/launcher patterns

---

## Executive Summary

sugar-wishlist is a SSH endpoint launcher with YAML/JSON shortcuts. The current implementation covers basic functionality (endpoint picker + `pcntl_exec` dispatch) but is missing features present in comparable tools across Go, Rust, and Python ecosystems.

**Key gaps identified:**
1. No proxy/jump host support
2. No SSH config file import
3. Limited endpoint metadata (no description, link, remote command)
4. No host matching patterns/hints system
5. No identity file alternatives/fallbacks
6. No environment variable handling

---

## 1. Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-wishlist/src/`

### 1.1 Config.php (L1-199)
- Supports JSON and YAML flat-list formats
- Custom YAML parser (no ext-yaml dependency)
- Field extraction: name, host, port, user, identity_file(s), description, options

### 1.2 Endpoint.php (L1-64)
- Immutable value object with: name, host, port, user, identityFile, description, options
- `toSshArgv()` generates ssh command args
- `displayLine()` for picker display

### 1.3 Launcher.php (L1-54)
- Uses `pcntl_exec()` to replace PHP process with ssh
- Callable executor for testability

### 1.4 Picker.php (L1-191)
- Terminal picker with j/k/arrow navigation
- Type-to-search filtering
- Raw mode TTY handling

---

## 2. Comparative Analysis by Category

### 2.1 Config File Formats

| Tool | Format(s) | Notes |
|------|-----------|-------|
| **wishlist (Go)** | YAML, SSH config | Parses ~/.ssh/config natively, hints system |
| **sshs (Rust)** | SSH config only | Direct ~/.ssh/config reading |
| **sshconf (Python)** | SSH config | Non-intrusive read/write preserving format |
| **russh (Rust)** | TOML | Sessions + procedures config |
| **purple (Rust)** | SSH config | Round-trip fidelity, cloud sync |
| **ssm (Go)** | SSH config + Include | Directory-based config.d |
| **sugar-wishlist** | JSON, YAML | Custom flat-list YAML parser |

**Observation:** Most tools leverage existing SSH config knowledge. sugar-wishlist's JSON/YAML approach is simpler but requires users to maintain separate config from ~/.ssh/config.

### 2.2 Host Matching Patterns

| Tool | Pattern Support |
|------|-----------------|
| **wishlist** | Glob patterns via hints (`match: "*.local"`) |
| **ssh2-config-rs** | Full SSH config pattern matching (Host, Match blocks) |
| **purple** | Tags via `# Tags:` comments in SSH config |
| **russh** | Session names with tags for grouping |
| **sugar-wishlist** | None - exact name matching only |

**Key Feature:** wishlist's **hints system** allows transforming discovered hosts with glob matches. This is powerful for applying defaults to host groups.

### 2.3 Key Management

| Tool | Approach |
|------|----------|
| **wishlist** | `identity_files` array (tries first available) |
| **skm (Go)** | Named key management with prompt UI, SSH agent integration |
| **sshs** | Reads from SSH config |
| **sugar-wishlist** | Single `identity_file` field |

**Observation:** wishlist supports multiple identity files with fallback - a good pattern to adopt.

### 2.4 Jump Host / Proxy Support

| Tool | Support |
|------|---------|
| **wishlist** | `proxy_jump: "user@host:22"` |
| **sshs** | ProxyCommand, ProxyJump from SSH config |
| **russh** | `jump: "session_name"` references another session |
| **sshm-rs** | Full port forwarding (local, remote, dynamic) |
| **purple** | Visual tunnel management |
| **sugar-wishlist** | **NONE** - significant gap |

**Critical Gap:** No `ProxyJump` or `ProxyCommand` support in sugar-wishlist.

### 2.5 Port Forwarding Options

| Tool | Support |
|------|---------|
| **wishlist** | `remote_command` execution support |
| **sshm-rs** | Local, remote, dynamic port forwarding UI |
| **purple** | Visual tunnel monitor |
| **russh** | Procedures (multi-command sequences) |
| **sugar-wishlist** | Only basic connect |

**Observation:** sugar-wishlist focuses on interactive shell; advanced port forwarding is niche but valuable for advanced users.

### 2.6 Additional Features in Comparable Tools

| Feature | Tools That Have It |
|---------|-------------------|
| Agent forwarding (`forward_agent`) | wishlist |
| Remote command execution | wishlist, russh (procedures) |
| TTY request control | wishlist |
| Connection timeout | wishlist |
| Environment variables (`set_env`, `send_env`) | wishlist |
| Link/URL metadata | wishlist |
| Cloud provider sync | purple (16 providers), sshm-rs |
| Docker/Podman management | purple |
| Password storage (keyring) | sshm-rs |
| File transfer (SFTP) | purple, sshm-rs |
| Command snippets | russh, sshm-rs, purple |
| MCP server for AI | purple |

---

## 3. Specific Improvements for sugar-wishlist

### 3.1 High Priority (Low Effort, High Value)

#### P1.1: Add `proxy_jump` Support
**Current:** No proxy support
**Add:** `proxy_jump` field to Endpoint, translate to `-J` flag in ssh argv

```php
// Endpoint.php addition
public readonly ?string $proxyJump = null;

// Launcher.php - in toSshArgv()
if ($this->proxyJump !== null) {
    $argv[] = '-J';
    $argv[] = $this->proxyJump;
}
```

**Source:** wishlist `_example/config.yaml:L43` - `proxy_jump: "user@host:22"`

**Effort:** 2-3 hours

---

#### P1.2: Add `description` Display in Picker
**Current:** `displayLine()` shows only name and host:user
**Add:** Show description as second line when available

```php
// Picker.php - modify draw()
private function draw(array $matches): void
{
    // ...
    foreach ($matches as $i => $e) {
        $marker = $i === $this->cursor ? "\x1b[1;36m▸\x1b[0m " : '  ';
        $line   = $e->displayLine();
        fwrite($this->out, "{$marker}{$line}\r\n");
        if ($e->description !== null && $e->description !== '') {
            $desc = \SugarCraft\Core\Util\Ansi::dim($e->description);
            fwrite($this->out, "   {$desc}\r\n");
        }
    }
}
```

**Source:** sugar-wishlist `Picker.php:L122-136` (current draw method)

**Effort:** 1 hour

---

#### P1.3: Add `identity_files` Array with Fallback
**Current:** Single `identity_file` string
**Add:** Array of paths, ssh uses first available

```yaml
# Example YAML
- name: production
  host: prod.example.com
  identity_files:
    - ~/.ssh/prod_key
    - ~/.ssh/id_ed25519
```

**Source:** wishlist `_example/config.yaml:L52-54`

**Effort:** 2 hours

---

### 3.2 Medium Priority (Moderate Effort)

#### P2.1: Import from ~/.ssh/config
**Current:** Only JSON/YAML file formats
**Add:** Parse SSH config format, extract Host blocks

```php
// Config.php - add SSH config parser
public static function parseSshConfig(string $raw): array
{
    $rows = [];
    $current = null;
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (preg_match('/^Host\s+(.+)$/i', $line, $m)) {
            if ($current !== null) {
                $rows[] = $current;
            }
            $current = ['name' => $m[1]];
            continue;
        }
        if ($current !== null && preg_match('/^(HostName|User|Port|IdentityFile|ProxyJump)\s+(.+)$/i', $line, $m)) {
            $key = strtolower($m[1]);
            $current[$key === 'hostname' ? 'host' : $key] = $m[2];
        }
    }
    if ($current !== null) {
        $rows[] = $current;
    }
    return $rows;
}
```

**Source:** sshconf Python library pattern (sshconf.py reads SSH config non-intrusively)

**Effort:** 4-6 hours

---

#### P2.2: Add `forward_agent` Option
**Current:** No agent forwarding control
**Add:** Boolean to enable `-A` flag

```php
// Endpoint.php
public readonly bool $forwardAgent = false;

// Launcher.php - in toSshArgv()
if ($this->forwardAgent) {
    $argv[] = '-A';
}
```

**Source:** wishlist `_example/config.yaml:L36` - `forward_agent: true`

**Effort:** 1 hour

---

#### P2.3: Add `request_tty` Option
**Current:** Always requests TTY (via pcntl_exec)
**Add:** Control via `-t` / `-T` flags

```php
// Endpoint.php
public readonly ?bool $requestTty = null; // null = auto, true = force, false = disable

// Launcher.php - handle in dispatch
if ($this->requestTty === true) {
    $argv[] = '-t';
} elseif ($this->requestTty === false) {
    $argv[] = '-T';
}
```

**Source:** wishlist `_example/config.yaml:L39` - `request_tty: true`

**Effort:** 1 hour

---

#### P2.4: Add `connect_timeout` Option
**Current:** No connection timeout
**Add:** `-o ConnectTimeout=N` option

```php
// Endpoint.php
public readonly ?int $connectTimeout = null;

// Launcher.php - in toSshArgv()
if ($this->connectTimeout !== null) {
    $argv[] = '-o';
    $argv[] = "ConnectTimeout={$this->connectTimeout}";
}
```

**Source:** wishlist `_example/config.yaml:L42` - `connect_timeout: 10s` (parse "10s" suffix)

**Effort:** 2 hours

---

### 3.3 Lower Priority (Higher Effort)

#### P3.1: Hints System (Glob Pattern Matching)
**Current:** Exact name matching only
**Add:** Hints array with glob patterns that apply to matching endpoints

```yaml
# Example YAML with hints
hints:
  - match: "*.local"
    port: 23234
    user: admin
  - match: "prod-*"
    forward_agent: true
```

**Source:** wishlist `_example/config.yaml:L68-89` - hints section

**Effort:** 6-8 hours

---

#### P3.2: Remote Command Execution
**Current:** Interactive shell only
**Add:** `remote_command` field to run specific command

```php
// Launcher.php - modify dispatch
public function dispatch(Endpoint $e, string $sshBinary = '/usr/bin/ssh'): void
{
    $argv = $e->toSshArgv($sshBinary);
    if ($e->remoteCommand !== null) {
        $argv[] = $e->remoteCommand;
    }
    ($this->executor)($argv[0], array_slice($argv, 1));
}
```

**Source:** wishlist `_example/config.yaml:L29` - `remote_command: uptime -a`

**Effort:** 2 hours

---

#### P3.3: Environment Variable Support
**Current:** No env var handling
**Add:** `set_env` and `send_env` arrays

```php
// Endpoint.php
public readonly array $setEnv = [];
public readonly array $sendEnv = [];

// Launcher.php - in toSshArgv()
foreach ($this->setEnv as $env) {
    $argv[] = '-o';
    $argv[] = "SetEnv={$env}";
}
```

**Source:** wishlist `_example/config.yaml:L56-63`

**Effort:** 3 hours

---

#### P3.4: Link/URL Metadata
**Current:** No link field
**Add:** Optional link object with name and URL for display

```yaml
# Example YAML
- name: production
  host: prod.example.com
  link:
    name: Dashboard
    url: https://prod.example.com/admin
```

**Source:** wishlist `_example/config.yaml:L45-47`

**Effort:** 2 hours

---

## 4. Implementation Roadmap

### Phase 1: Essential Gaps (Week 1)
1. Add `proxy_jump` support (P1.1)
2. Add `identity_files` array with fallback (P1.3)
3. Add `description` display in picker (P1.2)

### Phase 2: Feature Parity (Week 2)
4. Add `forward_agent` (P2.2)
5. Add `request_tty` control (P2.3)
6. Add `connect_timeout` (P2.4)
7. SSH config file import (P2.1)

### Phase 3: Advanced Features (Week 3-4)
8. Hints system with glob patterns (P3.1)
9. Remote command execution (P3.2)
10. Environment variable support (P3.3)
11. Link/URL metadata (P3.4)

---

## 5. Configuration Format Evolution

### Recommended YAML Format (Backward Compatible)

```yaml
# wishlist.yml - sugar-wishlist config
#
# Basic endpoint
- name: production
  host: prod.example.com
  port: 22
  user: deploy

# Full-featured endpoint
- name: staging
  host: stage.example.com
  user: admin
  port: 2222
  description: "Staging environment for testing"
  identity_files:
    - ~/.ssh/staging_key
    - ~/.ssh/id_ed25519
  forward_agent: true
  request_tty: true
  connect_timeout: 10
  proxy_jump: "bastion.example.com"
  remote_command: uptime
  set_env:
    - ENV=staging
    - DEBUG=1
  send_env:
    - LC_*
    - LANG
  link:
    name: Admin Panel
    url: https://stage.example.com/admin

# Hints - apply defaults to matching hosts
hints:
  - match: "*.local"
    user: admin
    port: 2222
  - match: "prod-*"
    forward_agent: false
    connect_timeout: 30
```

### JSON Format (Equivalent)

```json
[
  {
    "name": "production",
    "host": "prod.example.com",
    "user": "deploy"
  },
  {
    "name": "staging",
    "host": "stage.example.com",
    "user": "admin",
    "port": 2222,
    "description": "Staging environment for testing",
    "identity_files": ["~/.ssh/staging_key", "~/.ssh/id_ed25519"],
    "forward_agent": true,
    "request_tty": true,
    "connect_timeout": 10,
    "proxy_jump": "bastion.example.com",
    "remote_command": "uptime",
    "set_env": ["ENV=staging", "DEBUG=1"],
    "send_env": ["LC_*", "LANG"],
    "link": {"name": "Admin Panel", "url": "https://stage.example.com/admin"}
  }
]
```

---

## 6. Testing Recommendations

### 6.1 Unit Tests
- Config parsing: JSON, YAML, SSH config formats
- Endpoint: toSshArgv() output correctness
- Host pattern matching (when hints added)

### 6.2 Integration Tests
- Full dispatch flow with mocked executor
- TTY raw mode handling
- Signal handling (SIGINT, SIGTERM)

### 6.3 Snapshot Tests
- Picker rendering with various endpoint counts
- Filter matching behavior

---

## 7. Dependencies

### Current Dependencies
- `sugarcraft/candy-core` (for Ansi utilities)
- PHP 8.3+

### No New Dependencies Required
All improvements can be implemented with vanilla PHP:
- Custom YAML parser already in place
- SSH config parsing with regex
- No external YAML library needed

---

## 8. References

### Upstream
- **wishlist:** https://github.com/charmbracelet/wishlist (1494 stars, MIT)
- **wishlist config:** https://github.com/charmbracelet/wishlist/blob/main/_example/config.yaml

### Go Tools
- **skm:** https://github.com/TimothyYe/skm (1010 stars) - SSH key manager
- **ssm:** https://github.com/elliot40404/ssm (49 stars) - Simple SSH Manager

### Rust Tools
- **sshs:** https://github.com/quantumsheep/sshs (1485 stars) - TUI SSH using ~/.ssh/config
- **russh:** https://github.com/lacrioque/russh - TOML config with procedures
- **sshm-rs:** https://github.com/bit5hift/sshm-rs - Full-featured TUI
- **purple:** https://github.com/erickochen/purple (6200+ tests) - Cloud sync, MCP server

### Python Tools
- **sshconf:** https://github.com/sorend/sshconf (101 stars) - SSH config library
- **ssh-config-manager:** https://github.com/zietbukuel/ssh-config-manager - CLI tool
- **ssh-cli:** https://pypi.org/project/ssh-cli/ - Full CLI with config management

### SSH Config Reference
- **ProxyJump:** https://man7.org/linux/man-pages/man5/ssh_config.5.html (ProxyJump section)
- **ProxyCommand:** https://man7.org/linux/man-pages/man5/ssh_config.5.html (ProxyCommand section)

---

## Appendix A: Endpoint Field Comparison

| Field | wishlist | sugar-wishlist | Status |
|-------|----------|----------------|--------|
| name | ✅ | ✅ | OK |
| host | ✅ | ✅ | OK |
| address | ✅ (host:port) | ✅ (separate) | OK |
| port | ✅ | ✅ | OK |
| user | ✅ | ✅ | OK |
| description | ✅ | ⚠️ (stored, not displayed) | Needs display |
| identity_file(s) | ✅ (array) | ⚠️ (single) | Needs array |
| forward_agent | ✅ | ❌ | Needs add |
| request_tty | ✅ | ❌ | Needs add |
| connect_timeout | ✅ | ❌ | Needs add |
| proxy_jump | ✅ | ❌ | **Needs add** |
| remote_command | ✅ | ❌ | Needs add |
| set_env | ✅ | ❌ | Needs add |
| send_env | ✅ | ❌ | Needs add |
| link | ✅ | ❌ | Needs add |
| identity_files (fallback) | ✅ | ❌ | Needs add |
| hints/patterns | ✅ | ❌ | Needs add |

---

## Appendix B: effort Estimates Summary

| Priority | Feature | Effort | Impact |
|----------|---------|--------|--------|
| P1.1 | proxy_jump | 2-3h | High |
| P1.2 | description display | 1h | Medium |
| P1.3 | identity_files array | 2h | High |
| P2.1 | SSH config import | 4-6h | High |
| P2.2 | forward_agent | 1h | Medium |
| P2.3 | request_tty | 1h | Low |
| P2.4 | connect_timeout | 2h | Medium |
| P3.1 | hints system | 6-8h | Medium |
| P3.2 | remote_command | 2h | Medium |
| P3.3 | set_env/send_env | 3h | Low |
| P3.4 | link metadata | 2h | Low |

**Total Estimated Effort:** 26-34 hours across 4 weeks
