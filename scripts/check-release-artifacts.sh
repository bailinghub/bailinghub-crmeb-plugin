#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if [[ $# -lt 2 || $# -gt 3 ]]; then
  echo "Usage: scripts/check-release-artifacts.sh PLUGIN.zip WEB-INSTALL.zip [SHA256SUMS.txt]" >&2
  exit 2
fi

PLUGIN_PACKAGE="$1"
WEB_PACKAGE="$2"
SHA256_FILE="${3:-}"

for command_name in "$PHP_BIN" composer; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "[bailinghub-release] required command is unavailable: $command_name" >&2
    exit 1
  fi
done

cd "$ROOT_DIR"

echo "[1/5] Validate Composer metadata"
composer validate --strict --no-check-publish

echo "[2/5] Lint PHP source"
while IFS= read -r -d '' php_file; do
  "$PHP_BIN" -l "$php_file" >/dev/null
done < <(find config scripts src tests webinstaller -type f -name '*.php' -print0)
echo "PHP lint passed"

echo "[3/5] Run standalone contract and security tests"
for test_file in tests/*_test.php; do
  if [[ "$(basename "$test_file")" == "package_contract_test.php" ]]; then
    continue
  fi
  "$PHP_BIN" "$test_file"
done

echo "[4/5] Verify signed plugin package and exact signed file set"
BAILINGHUB_PLUGIN_PACKAGE="$PLUGIN_PACKAGE" "$PHP_BIN" tests/package_contract_test.php

echo "[5/5] Cross-check source, web installer bytes and SHA256"
if [[ -n "$SHA256_FILE" ]]; then
  "$PHP_BIN" scripts/verify-release-artifacts.php "$PLUGIN_PACKAGE" "$WEB_PACKAGE" "$SHA256_FILE"
else
  "$PHP_BIN" scripts/verify-release-artifacts.php "$PLUGIN_PACKAGE" "$WEB_PACKAGE"
fi
