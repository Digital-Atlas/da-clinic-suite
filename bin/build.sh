#!/usr/bin/env bash
# Build dist/da-clinic-suite.zip (unpacks to da-clinic-suite/), the file sites install and update from.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/da-clinic-suite
# Copy everything except repo-only files.
tar -cf - --exclude=./.git --exclude=./.github --exclude=./.gitignore --exclude=./.gitattributes \
  --exclude=./bin --exclude=./dist --exclude=.DS_Store --exclude=./CLAUDE.md . | tar -xf - -C dist/da-clinic-suite
(cd dist && zip -rqX da-clinic-suite.zip da-clinic-suite)
echo "Built dist/da-clinic-suite.zip"
