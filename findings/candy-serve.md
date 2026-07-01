# Audit: candy-serve

**Library:** SugarCraft/candy-serve  
**Date:** 2026-06-30  

---

## 1. SECURITY ISSUES

### 1.1 [HIGH] Shell Injection in Git Ref Names
**Location:** `src/Git/GitDaemon.php:473`, `src/Git/ReceivePack.php:129`

```php
$escapedRef = \escapeshellarg($ref);
$updateRefCmd = "git -C {$repoPath} update-ref {$escapedRef} {$escapedNew} {$escapedOld} 2>&1";
```

`$ref` is validated only by checking `$oldHash` and `$newHash` are 40-char hex. But `$ref` itself can contain shell meta-characters. A ref like `refs/heads/$(malicious-command)` passes because the hash check is on different fields.

**Recommendation:** Validate `$ref` against git ref naming rules:
```php
if (!\preg_match('/^refs\/[a-zA-Z0-9._\/.-]+$/', $ref)) {
    $this->writePacket($socket, "ng {$ref}: invalid ref name");
    return;
}
```

---

### 1.2 [HIGH] Path Traversal in Repo Name Handling
**Location:** `src/SSH/SSHServer.php:97-104`

Regex `\/[^"\'\s]+` allows `..` in the path. While `basename()` is used, the initial capture includes traversal characters.

**Recommendation:**
```php
if (!\preg_match('/^[a-zA-Z0-9._-]+$/', $repoName)) {
    \fwrite(\STDERR, "Invalid repo name: {$repoName}\n");
    return 1;
}
```

---

### 1.3 [HIGH] Hardcoded Bearer Token in LFS Responses
**Location:** `src/LFS/LFSHandler.php:200-201`, `213`

```php
'header' => ['Authorization' => 'Bearer lfs-token'],
```

Placeholder token returned in all LFS URLs — anyone with network access can download/upload LFS objects without auth.

**Recommendation:** Implement proper per-request LFS authentication with time-limited tokens.

---

### 1.4 [MEDIUM] Empty Password Authentication Bypass
**Location:** `src/HttpSmartProtocol/Server.php:566`

```php
if ($user->password !== null && !\hash_equals($user->password, $password)) {
    return null;
}
return $user;
```

When `$user->password` is `null`, the `!== null` check evaluates to `false`, skipping password verification entirely. Any user with `password = null` can authenticate with any password.

**Recommendation:**
```php
if ($user->password === null) {
    return null;  // deny all Basic auth attempts with no password
}
if (!\hash_equals($user->password, $password)) {
    return null;
}
return $user;
```

---

### 1.5 [MEDIUM] Incomplete SSH Key Validation
**Location:** `src/User.php:82`

```php
if (!\preg_match('/^((?:ssh|ecdsa|sk)-[A-Za-z0-9@.-]+)\s+([A-Za-z0-9+\/=]+)(\s+.+)?$/', $key)) {
```

Regex accepts any base64-like string with no minimum length. A 1-char blob would pass.

**Recommendation:** Add minimum length validation per key type (ed25519: 64 chars base64, RSA: 256+).

---

## 2. BUGS & CORRECTNESS

### 2.1 [HIGH] Repo Init() Runs Git in Wrong Directory
**Location:** `src/Repo.php:145`

```php
\exec('git init --bare 2>&1', $out, $rc);
```

`git init --bare` runs in CWD, not `$this->path`. Bare repo is created at wrong location if CWD ≠ repo path.

**Recommendation:**
```php
$cmd = 'git -C ' . \escapeshellarg($this->path) . ' init --bare 2>&1';
\exec($cmd, $out, $rc);
```

---

### 2.2 [HIGH] Array Modification During Iteration
**Location:** `src/Git/GitDaemon.php:213-223`

```php
foreach ($this->clients as $idx => &$client) {
    if ($idleTimeout > 0 && ($now - $client['last_activity']) > $idleTimeout) {
        $this->closeClient($idx);  // calls array_splice, modifying $this->clients
        continue;
    }
}
```

`closeClient()` calls `array_splice($this->clients, $idx, 1)` during iteration. Index shifting causes skipped or re-processed elements.

**Recommendation:** Collect stale indices first, then close in reverse order.

---

### 2.3 [HIGH] Temp File Leak on Exception
**Location:** `src/Git/GitDaemon.php:309-321`

```php
$stdinFile = \tempnam($tmpDir, 'git-stdin-');
// ...
if ($handler instanceof UploadPack) {
    $this->handleUploadPack($socket, $handler, $stdinFile);
}
@\unlink($stdinFile);  // Only runs if no exception
```

If `handleUploadPack`/`handleReceivePack` throws, temp file is never deleted.

**Recommendation:** Wrap in try/finally.

---

### 2.4 [MEDIUM] Non-Functional Config Property
**Location:** `src/HttpSmartProtocol/Server.php:326`, `435`

```php
$maxBytes = $this->config->maxPackBytes ?? 268435456;
```

`Config` class has no `maxPackBytes` property. Config option is completely non-functional.

---

### 2.5 [MEDIUM] `$maxBytes` Loaded from Config But Never Used in Upload Pack
**Location:** `src/HttpSmartProtocol/Server.php:260-275`

`handleUploadPack` uses local constant `268435456` instead of loading from config. Upload pack size limit cannot be configured.

---

## 3. ERROR HANDLING GAPS

### 3.1 [MEDIUM] Git Error Output Discarded
**Location:** `src/Git/GitDaemon.php:482`, `src/Git/ReceivePack.php:144-155`

```php
\exec($updateRefCmd, $out, $rc);
if ($rc !== 0) {
    $this->writePacket($socket, "ng {$cmd['ref']}: pre-receive hook declined");
    return;
}
```

`$out` (git error message) is discarded. User gets only generic message.

**Recommendation:**
```php
if ($rc !== 0) {
    $errorMsg = \implode("\n", $out) ?: 'unknown error';
    $this->writePacket($socket, "ng {$cmd['ref']}: {$errorMsg}");
    return;
}
```

---

### 3.2 [MEDIUM] Silenced symlink Failure in Repo Init
**Location:** `src/Repo.php:160`

```php
@\symlink('/usr/share/git-core/templates/hooks', $hooksSrc);
```

Failure is completely silent. Could degrade functionality without logging.

---

## 4. PERFORMANCE PROBLEMS

### 4.1 [MEDIUM] O(n²) String Concatenation in Packfile Transfer
**Location:** `src/HttpSmartProtocol/Server.php:261-275`, `src/Git/GitDaemon.php:329-337`

```php
$packData = '';
while (!\feof($pipes[1])) {
    $chunk = \fread($pipes[1], 65536);
    $packData .= $chunk;  // O(n²) memory reallocation
}
```

PHP strings are immutable. Each ` .= ` reallocates entire string.

**Recommendation:** Use `stream_get_contents($pipes[1])` or collect in array and `implode()` at end.

---

### 4.2 [LOW] Unnecessary preg_split vs explode
**Location:** `src/Repo.php:223`

```php
$parts = \preg_split('/\s+/', $line, 2);
```

`preg_split` with simple regex is slower than `explode`.

**Recommendation:** `\explode(' ', $line, 2)`

---

## 5. MEMORY LEAKS

### 5.1 [MEDIUM] Undisposed File Handle from LocalStorageBackend::read
**Location:** `src/LFS/LocalStorageBackend.php:48-59`

```php
public function read(string $oid) {
    $handle = @\fopen($path, 'rb');
    if ($handle === false) {
        throw new \RuntimeException("Cannot open LFS object {$oid}");
    }
    return $handle;  // Caller must fclose()
}
```

Returns raw resource. If caller throws mid-stream, file descriptor leaks.

**Recommendation:** Provide `readAll(string $oid): string` convenience method.

---

### 5.2 [LOW] Osc52 Event Queue Unbounded Growth
**Location:** `src/Clipboard/Osc52.php:82`, `101`

If `pendingEvents()` is never called, `$this->events` array grows indefinitely.

**Recommendation:** Add `const MAX_EVENTS = 1000` with eviction.

---

## 6. PHP 8.3/8.4 COMPATIBILITY

### 6.1 [LOW] Missing `maxPackBytes` Property in Config
**Location:** `src/Config.php`

Property doesn't exist but is referenced in `src/HttpSmartProtocol/Server.php`.

---

### 6.2 [LOW] Untyped `$proc` Variable
**Location:** Multiple files using `proc_open`

`proc_open()` returns `resource|false`. Untyped variables prevent static analysis.

---

## 7. MISSING FEATURES / INCOMPLETE PORTS

### 7.1 [HIGH] HTTP Server Buffers Entire Packfile in Memory
**Location:** `src/HttpSmartProtocol/Server.php:261-275`

TODO at line 434 admits: "stream via chunked callback for true streaming". For 256 MiB packfiles, buffering in RAM is unsustainable.

---

### 7.2 [HIGH] LFS "Concurrency" is Fully Sequential
**Location:** `src/LFS/LFSHandler.php:153-158`

```php
// For now, use sequential processing since pthreads requires extension
return $this->processObjectsSequentially($operation, $batch);
```

`concurrentTransfers` parameter has zero effect.

**Recommendation:** Use ReactPHP's `Loop` with async I/O.

---

### 7.3 [MEDIUM] SSH Key Verification Never Called
**Location:** `src/SSH/SSHServer.php:141-158`

```php
private function authenticate($stream, ?User $user): bool {
    return true;  // verifyPublicKey() is NEVER called
}
```

`User::verifyPublicKey()` at `src/User.php:97` is never invoked.

---

### 7.4 [MEDIUM] Repo Init Doesn't Configure Core Settings
**Location:** `src/Repo.php:131-164`

Fresh bare repo doesn't set `core.sharedRepository`.

**Recommendation:**
```php
\exec('git -C ' . \escapeshellarg($this->path) . ' config core.sharedRepository umask 2>&1', $out, $rc);
```

---

### 7.5 [MEDIUM] No LFS Object HTTP Handlers
**Location:** `src/LFS/LFSHandler.php:222-225`

URL paths generated but no corresponding HTTP route handlers exist.

---

### 7.6 [LOW] Mirror/Pull Schedule Not Implemented
**Location:** `src/Config.php:167`

Stored but never used. No background job system exists.

---

### 7.7 [LOW] Stats Server Not Implemented
**Location:** `src/Config.php:170`

Stored but no stats server implementation exists.

---

## 8. ASYNC/REACTPHP IMPROVEMENTS

### 8.1 [MEDIUM] GitDaemon Could Use ReactPHP
**Location:** `src/Git/GitDaemon.php` — entire file

Blocking `socket_select()` loop. ReactPHP would provide better concurrency.

---

### 8.2 [MEDIUM] LFS Handler Should Use ReactPHP PromiseAll
**Location:** `src/LFS/LFSHandler.php:128-145`

Concurrent transfer is stubbed out. ReactPHP's `Deferred` or `Promise\all()` would enable genuine concurrent I/O.

---

## 9. COMPLEXITY / CODE QUALITY

### 9.1 [LOW] Custom YAML Parser Very Limited
**Location:** `src/Config.php:216-262`

Doesn't support nested structures, multi-line strings, or anchors/aliases. Tests note "lisfs nested parsing doesn't work with indentation".

**Recommendation:** Consider replacing with `symfony/yaml`.

---

### 9.2 [LOW] Redundant Visibility State
**Location:** `src/Repo.php:37` (`$private`) vs `$isPublic`

A repo can be `isPublic=true` AND `private=true`. `private` overrides `isPublic`. Two-boolean visibility model is confusing.

**Recommendation:** Replace with a `Visibility` enum: `PUBLIC`, `PRIVATE`, `COLLABORATOR_ONLY`.

---

## Summary Table

| Severity | Count | Top Issues |
|----------|-------|------------|
| **HIGH** | 7 | Shell injection in refs, path traversal, hardcoded LFS token, empty password bypass, repo init wrong dir, array modification bug, temp file leak |
| **MEDIUM** | 12 | Discarded error output, non-functional config, LFS sequential-only, SSH key verification missing, git core config missing, O(n²) string concat, undisposed handles, etc. |
| **LOW** | 9 | Unnecessary preg_split, YAML parser limits, redundant visibility state, missing typed properties, etc. |

**Total: 28 findings**
