#!/usr/bin/env bash
# Unit tests need no core, database or network. With DRIFT_DB_PORT set, tests/integration/
# runs too, against core's scratch MySQL and the core at SHOPCLASS_CORE (or ../shopclass); it
# skips on a core without shop mode.
set -u
cd "$(dirname "$0")/.."
fail=0
for t in tests/*.php; do
    echo "── $t"
    php "$t" | tail -3
    [ "${PIPESTATUS[0]}" -eq 0 ] || fail=1
done
if [ -n "${DRIFT_DB_PORT:-}" ]; then
    for t in tests/integration/*.php; do
        echo "── $t"
        php "$t" | tail -3
        [ "${PIPESTATUS[0]}" -eq 0 ] || fail=1
    done
fi
exit "$fail"
