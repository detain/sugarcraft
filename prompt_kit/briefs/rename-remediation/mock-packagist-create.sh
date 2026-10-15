#!/usr/bin/env bash
# Evidence harness for scripts/packagist.sh cmd_create (T2, 2026-10-15).
# Proves the live create-package + Bearer request shape with a curl stub —
# NO real network, NO real token (fake creds only).
#
# Run:  bash prompt_kit/briefs/rename-remediation/mock-packagist-create.sh
set -u

REPO=/home/sites/sugarcraft
WORK="$(mktemp -d /tmp/opencode/packagist-mock.XXXXXX)"
STUB_DIR="$WORK/stub"
mkdir -p "$STUB_DIR"
CALLS="$WORK/calls.log"
: >"$CALLS"

# curl stub: dumps its argv (one per line, NUL-safe via %q) then answers with
# the HTTP code / body from env MOCK_CODE / MOCK_BODY.
cat >"$STUB_DIR/curl" <<'STUB'
#!/usr/bin/env bash
for a in "$@"; do printf '[%s]' "$a"; done >>"$CALLS"
printf '\n' >>"$CALLS"
# capture -w output file so the real -o temp file still gets the body
for ((i = 1; i <= $#; i++)); do
    if [ "${!i}" = '-o' ]; then
        j=$((i + 1))
        printf '%s' "${MOCK_BODY:-{\"status\":\"ok\"}}" >"${!j}"
    fi
done
printf '%s' "${MOCK_CODE:-202}"
STUB
chmod +x "$STUB_DIR/curl"

pass=0
fail=0
check() { # <label> <cond-result>
    if [ "$2" -eq 0 ]; then
        echo "PASS  $1"
        pass=$((pass + 1))
    else
        echo "FAIL  $1"
        fail=$((fail + 1))
    fi
}

run_create() { # env: MOCK_CODE MOCK_BODY PACKAGIST_TOKEN PACKAGIST_USERNAME(maybe unset)
    : >"$CALLS"
    (cd "$REPO" && PATH="$STUB_DIR:$PATH" CALLS="$CALLS" \
        bash scripts/packagist.sh create test-slug)
}

echo "== case 1: request shape (202) =="
OUT="$(MOCK_CODE=202 MOCK_BODY='{"status":"success"}' PACKAGIST_TOKEN='FAKE-TOKEN-918273' \
    PACKAGIST_USERNAME='fakeuser' run_create)"
RC=$?
check "202 -> rc0" "$([ $RC -eq 0 ] && echo 0 || echo 1)"
check "stdout is HTTP 202 + masked body" \
    "$(grep -q '^HTTP 202$' <<<"$OUT" && grep -q '"status":"success"' <<<"$OUT" && echo 0 || echo 1)"
REQ="$(cat "$CALLS")"
check "POSTs the create-package endpoint" \
    "$(grep -q -- 'https://packagist.org/api/create-package' <<<"$REQ" && echo 0 || echo 1)"
check "method is -XPOST" "$(grep -q -- '-XPOST' <<<"$REQ" && echo 0 || echo 1)"
check "Bearer username:token header present (fake creds)" \
    "$(grep -q -- 'Authorization: Bearer fakeuser:FAKE-TOKEN-918273' <<<"$REQ" && echo 0 || echo 1)"
check "content-type application/json header" \
    "$(grep -q -- 'content-type: application/json' <<<"$REQ" && echo 0 || echo 1)"
check "flat {\"repository\":<org repo url>} body" \
    "$(grep -q 'repository.*https://github.com/sugarcraft/test-slug' <<<"$REQ" && echo 0 || echo 1)"
check "OLD removed route absent" \
    "$(grep -q '_update.json' <<<"$REQ" && echo 1 || echo 0)"
check "token never rides a URL/query (no apiToken= in argv)" \
    "$(grep -q 'apiToken=' <<<"$REQ" && echo 1 || echo 0)"

echo "== case 2: 401 -> rc1 =="
OUT="$(MOCK_CODE=401 MOCK_BODY='{"message":"Invalid."}' PACKAGIST_TOKEN='t' PACKAGIST_USERNAME='u' run_create)"
RC=$?
check "401 -> rc1" "$([ $RC -eq 1 ] && echo 0 || echo 1)"
check "401 reported on stdout" "$(grep -q '^HTTP 401$' <<<"$OUT" && echo 0 || echo 1)"

echo "== case 3: 404 -> rc1 =="
OUT="$(MOCK_CODE=404 MOCK_BODY='not found' PACKAGIST_TOKEN='t' PACKAGIST_USERNAME='u' run_create)"
RC=$?
check "404 -> rc1" "$([ $RC -eq 1 ] && echo 0 || echo 1)"

echo "== case 4: missing creds -> rc2, zero curl =="
OUT="$( : >"$CALLS"; MOCK_CODE=202 PACKAGIST_USERNAME='u' env -u PACKAGIST_TOKEN \
    bash -c "cd $REPO && PATH=$STUB_DIR:$PATH CALLS=$CALLS bash scripts/packagist.sh create test-slug" 2>&1)"
RC=$?
check "missing PACKAGIST_TOKEN -> rc2" "$([ $RC -eq 2 ] && echo 0 || echo 1)"
check "  zero curl invocations" "$([ ! -s "$CALLS" ] && echo 0 || echo 1)"
OUT="$( : >"$CALLS"; MOCK_CODE=202 PACKAGIST_TOKEN='t' env -u PACKAGIST_USERNAME \
    bash -c "cd $REPO && PATH=$STUB_DIR:$PATH CALLS=$CALLS bash scripts/packagist.sh create test-slug" 2>&1)"
RC=$?
check "missing PACKAGIST_USERNAME -> rc2" "$([ $RC -eq 2 ] && echo 0 || echo 1)"
check "  zero curl invocations" "$([ ! -s "$CALLS" ] && echo 0 || echo 1)"

echo
echo "pass=$pass fail=$fail"
rm -rf "$WORK"
[ "$fail" -eq 0 ]
