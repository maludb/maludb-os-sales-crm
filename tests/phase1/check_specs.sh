#!/usr/bin/env bash
# Phase 1 — the documents' consistency checks (2026-10-09): the registry matches the manifest, maludb-os.json's approvals[] match the
# manifest, every screen and action of the registry is claimed by exactly one slice spec, every tool of the surface is named by a slice
# and every tool a slice names exists (tests/phase1/check_specs.php). Read-only; no database, no server. Exit non-zero on any defect.
set -u
cd "$(dirname "$0")/../.."
rc=0
echo "== bin/build_action_registry.php --check"
php bin/build_action_registry.php --check || rc=1
echo "== bin/sync_approvals.php --check"
php bin/sync_approvals.php --check || rc=1
echo "== tests/phase1/check_specs.php"
php tests/phase1/check_specs.php || rc=1
if [ "$rc" -eq 0 ]; then echo "PHASE 1 CHECKS: all green"; else echo "PHASE 1 CHECKS: FAILED"; fi
exit $rc
