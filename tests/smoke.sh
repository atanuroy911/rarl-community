#!/usr/bin/env bash
# CI smoke test: serves the site with PHP's built-in server, signs in as a
# member and as admin, and loads every page. Fails on HTTP 5xx, PHP errors or
# warnings, or a syntax error in any inline <script> (checked with node).
# Usage: tests/smoke.sh   (needs php, curl, node; DB_* env set; ADMIN_PASSWORD_HASH for 'ci-admin')
set -uo pipefail
cd "$(dirname "$0")/.."

PORT=8123
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
php -S 127.0.0.1:$PORT >"$WORK/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/index.php" && break; sleep 0.3; done

FAILED=0
fail() { echo "  FAIL $1"; FAILED=1; }

check() { # check <label> <jar> <path>
  local label="$1" jar="$2" path="$3" out="$WORK/page.html" code
  code=$(curl -s -L -b "$jar" -c "$jar" -o "$out" -w '%{http_code}' "$BASE/$path")
  if [ "$code" -ge 500 ]; then fail "$label $path → HTTP $code"; return; fi
  # PHP messages look like "<b>Warning</b>:  … on line <b>3</b>" (html_errors) or "Warning: … on line 3".
  local perr='(Fatal error|Parse error|Warning|Deprecated|Notice)(</b>)?: .* on line (<b>)?[0-9]+|Uncaught (Error|Exception|TypeError)'
  if grep -qE "$perr" "$out"; then
    fail "$label $path → PHP error: $(grep -oE "$perr" "$out" | head -1 | sed 's/<[^>]*>//g' | cut -c1-220)"
    return
  fi
  # Every inline script must parse.
  python3 - "$out" "$WORK/js" <<'PY'
import re, sys, os
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
os.makedirs(sys.argv[2], exist_ok=True)
for f in os.listdir(sys.argv[2]): os.remove(os.path.join(sys.argv[2], f))
for i, m in enumerate(re.finditer(r'<script(?![^>]*\bsrc=)(?![^>]*type="application/ld\+json")(?![^>]*type="text/tailwindcss")[^>]*>(.*?)</script>', html, re.S | re.I)):
    open(os.path.join(sys.argv[2], f'{i}.js'), 'w', encoding='utf-8').write(m.group(1))
PY
  for js in "$WORK"/js/*.js; do
    [ -s "$js" ] || continue
    if ! node --check "$js" 2>"$WORK/node.err"; then fail "$label $path → script error: $(grep -m1 -E 'SyntaxError' "$WORK/node.err")"; fi
  done
  echo "  ok   $label $path ($code)"
}

echo "Public pages"
G="$WORK/guest.jar"
for p in index.php events.php videos.php publications.php resources.php community.php directory.php partners.php people.php search.php?q=robot verify.php verify.php?id=nope login.php register.php register-individual.php register-lab.php forgot-password.php sitemap.php robots.php; do
  check guest "$G" "$p"
done

echo "Member pages"
M="$WORK/member.jar"
curl -s -c "$M" -b "$M" -o "$WORK/login.html" "$BASE/login.php"
TOKEN=$(grep -o 'name="csrf_token" value="[^"]*"' "$WORK/login.html" | head -1 | sed 's/.*value="//;s/"//')
curl -s -c "$M" -b "$M" -o /dev/null --data-urlencode "csrf_token=$TOKEN" -d "email=ci@example.com&password=ci-password" "$BASE/login.php"
for p in dashboard.php membership.php profile.php events.php community.php "share.php?type=event&id=999"; do check member "$M" "$p"; done
grep -q 'Sign in\|name="password"' "$WORK/page.html" && fail "member login did not work"

echo "Admin pages"
A="$WORK/admin.jar"
curl -s -c "$A" -b "$A" -o /dev/null -d "step=password&u=admin&p=ci-admin" "$BASE/admin/login.php"
for p in index.php members.php member-check.php import-members.php sections.php publications.php community.php events.php videos.php resources.php people.php \
         certificates.php "certificates.php?view=history" membership-certificates.php templates.php newsletter.php compose-email.php email-queue.php email-check.php \
         settings.php security.php partnerships.php plans.php migrate.php backup.php standing.php "standing.php?show=below" id-cards.php "id-cards.php?show=nophoto"; do
  check admin "$A" "admin/$p"
done
grep -q 'name="u"' "$WORK/page.html" && fail "admin login did not work"

if grep -qE 'PHP (Fatal|Parse)' "$WORK/server.log"; then fail "server log has PHP errors:"; grep -E 'PHP (Fatal|Parse)' "$WORK/server.log" | head -5; fi
[ "$FAILED" = 0 ] && echo "All smoke checks passed." || { echo "Smoke checks FAILED."; exit 1; }
