#!/usr/bin/env bash
# The original bootstrap targeted /var/www/safeharbor and could recreate an
# obsolete, over-privileged layout on the shared production host.
set -Eeuo pipefail

printf '%s\n' \
  'Safeharbor setup-server.sh is retired and intentionally makes no changes.' \
  'Use deploy/README.md for a reviewed rebuild plan or' \
  'deploy/prepare-atomic-layout.sh for the guarded one-time legacy conversion.' >&2
exit 1
