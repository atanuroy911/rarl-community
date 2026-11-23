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
for p in index.php events.php "events.php?event=999" "events.php?event=1&k=bad" "events.php?ics=999" videos.php publications.php resources.php community.php directory.php partners.php people.php search.php?q=robot verify.php verify.php?id=nope login.php register.php register-individual.php register-lab.php forgot-password.php sitemap.php robots.php; do
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

echo "Guest event registration (events seeded by tests/ci-db.php)"
GJ="$WORK/guest-reg.jar"
post_guest() { # post_guest <out-file> <form fields…>: submits the registration form, follows redirects, prints the final URL
  local out="$1"; shift
  curl -s -L -c "$GJ" -b "$GJ" -o "$out" -w '%{url_effective}' --data-urlencode "csrf_token=$GT" -d action=guest_register "$@" "$BASE/events.php"
}
valid_guest=(--data-urlencode "g_country=United Kingdom" --data-urlencode "g_institution=University of CI" -d g_position=researcher -d g_member=0 --data-urlencode "g_interest=Swarm robotics")
check guest-reg "$GJ" "events.php?event=1"
grep -q 'data-guest-open="1"' "$WORK/page.html" || fail "public event has no Register button for guests"
GT=$(grep -o 'name="csrf_token" value="[^"]*"' "$WORK/page.html" | head -1 | sed 's/.*value="//;s/"//')
post_guest "$WORK/g-bad.html" -d event_id=1 -d g_name=X >/dev/null
grep -q 'Please check the highlighted fields' "$WORK/g-bad.html" || fail "an incomplete guest form was not sent back with errors"
URL=$(post_guest "$WORK/g-ok.html" -d event_id=1 --data-urlencode "g_name=Ada Guest" -d g_email=ada@example.com "${valid_guest[@]}")
case "$URL" in *"events.php?guest="*) check guest-reg "$GJ" "${URL#"$BASE"/}"; grep -q 'Ada Guest' "$WORK/page.html" || fail "guest registration page does not show the guest";;
  *) fail "guest registration did not land on the private registration page (got $URL)";; esac
post_guest "$WORK/g-dup.html" -d event_id=1 --data-urlencode "g_name=Ada Updated" -d g_email=ada@example.com "${valid_guest[@]}" >/dev/null
grep -q 'Your details are updated' "$WORK/g-dup.html" || fail "registering again with the same email did not update the existing registration"
check guest-reg "$GJ" "events.php?event=2"
grep -q 'data-guest-open="2"' "$WORK/page.html" && fail "link-only event opened without its key"
check guest-reg "$GJ" "events.php?event=2&k=c1c1c1c1c1c1c1c1c1c1c1c1"
grep -q 'data-guest-open="2"' "$WORK/page.html" || fail "link-only event with its key has no Register button"
post_guest "$WORK/g-l1.html" -d event_id=2 -d k=c1c1c1c1c1c1c1c1c1c1c1c1 --data-urlencode "g_name=Ben Guest" -d g_email=ben@example.com "${valid_guest[@]}" >/dev/null
grep -q 'Ben Guest' "$WORK/g-l1.html" || fail "guest could not register for a link-only event with its key"
post_guest "$WORK/g-full.html" -d event_id=2 -d k=c1c1c1c1c1c1c1c1c1c1c1c1 --data-urlencode "g_name=Cy Guest" -d g_email=cy@example.com "${valid_guest[@]}" >/dev/null
grep -q 'fully booked' "$WORK/g-full.html" || fail "capacity was not enforced for guests"
URL=$(post_guest "$WORK/g-mo.html" -d event_id=3 --data-urlencode "g_name=Di Guest" -d g_email=di@example.com "${valid_guest[@]}")
case "$URL" in *"guest="*) fail "a guest registered for a members-only event";; esac
check admin "$A" "admin/event-registrations.php?event=1"
grep -q 'Ada Updated' "$WORK/page.html" || fail "guest is missing from the admin attendee list"
curl -s -b "$A" "$BASE/admin/event-registrations.php?event=1&export=csv" | grep -q 'Swarm robotics' || fail "attendee CSV is missing the guest's research interest"
check admin "$A" "admin/events.php"

if grep -qE 'PHP (Fatal|Parse)' "$WORK/server.log"; then fail "server log has PHP errors:"; grep -E 'PHP (Fatal|Parse)' "$WORK/server.log" | head -5; fi
[ "$FAILED" = 0 ] && echo "All smoke checks passed." || { echo "Smoke checks FAILED."; exit 1; }
