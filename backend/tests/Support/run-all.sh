#!/usr/bin/env bash
#
# The whole suite, in one command.
#
# WHY THIS EXISTS. The unit tests and the SQL schema tests were run by two
# different tools, and only one of them was ever run. The schema tests failed
# silently for weeks after a migration changed the category vocabulary --
# twelve failures nobody saw, because nothing ran them.
#
# A test that is not in the suite is not a test. This is the suite.
#
# Usage: bash tests/Support/run-all.sh
#   PGHOST / PGPORT select the database for the schema tests; when no database
#   is reachable those tests are reported as SKIPPED, never as passed.

set -uo pipefail
cd "$(dirname "$0")/../.."

FAILED=0
SKIPPED=0

echo "═══════════════════════════════════════════════"
echo " 1. Static checks"
echo "═══════════════════════════════════════════════"

LINT=$(find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors' || true)
if [ -n "$LINT" ]; then
  echo "  FAIL  php -l"; echo "$LINT" | head -5; FAILED=1
else
  echo "  PASS  php -l (syntax)"
fi

if php bin/arch-check.php > /tmp/arch.out 2>&1; then
  echo "  PASS  architecture (dependency direction)"
else
  echo "  FAIL  architecture"; cat /tmp/arch.out; FAILED=1
fi

echo ""
echo "═══════════════════════════════════════════════"
echo " 2. Unit tests"
echo "═══════════════════════════════════════════════"
php tests/Support/run-unit-tests.php || FAILED=1

echo ""
echo "═══════════════════════════════════════════════"
echo " 3. Database / schema tests"
echo "═══════════════════════════════════════════════"

PGHOST="${PGHOST:-/tmp/pgrun}"
PGPORT="${PGPORT:-5433}"

if command -v psql > /dev/null 2>&1 && psql -h "$PGHOST" -p "$PGPORT" -d devradar -c 'SELECT 1' > /dev/null 2>&1; then
  PGHOST="$PGHOST" PGPORT="$PGPORT" bash tests/Support/verify-schema.sh || FAILED=1
else
  # Reported, never silently omitted. A skipped test is a known unknown; an
  # invisible one is just a gap.
  SKIPPED=1
  echo "  SKIPPED  no database at $PGHOST:$PGPORT"
  echo "           these assert CHECK constraints, FK behaviour and index use"
  echo "           and must be run before any schema change is trusted."
fi

echo ""
echo "═══════════════════════════════════════════════"
if [ "$FAILED" -ne 0 ]; then
  echo " SUITE FAILED"
elif [ "$SKIPPED" -ne 0 ]; then
  # Never plain "PASSED" when a whole category did not run. Reporting a
  # partial run as a clean one is how the schema tests stayed broken for
  # weeks without anybody noticing.
  echo " SUITE PASSED — BUT DATABASE TESTS WERE SKIPPED"
  echo " Nothing verified the schema. Do not treat this as a full run."
else
  echo " SUITE PASSED (all categories ran)"
fi
echo "═══════════════════════════════════════════════"
exit "$FAILED"
