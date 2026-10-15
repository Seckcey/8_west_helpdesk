# GENERATED OFFLINE CANDIDATE /etc/cron.d/{{CRON_FILE}} for {{APP}} - not installed.
# Input digest: {{INPUT_SHA256}}
#
# Every accounted cron script runs as {{APP_USER}}, never as the frontend {{FRONTEND_USER}}.
# TMPDIR points CLI PHP at the private temporary directory. Unrouted scripts are listed as comments
# and remain open facts. Compare with the live /etc/cron.d entries before any change.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
TMPDIR={{TMP_DIR}}

{{CRON_LINES}}
