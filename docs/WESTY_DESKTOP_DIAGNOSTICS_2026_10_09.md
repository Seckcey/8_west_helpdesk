# Westy desktop tool availability diagnostics

Frankie's installed Full 1.26.14 failed browser and Notepad acceptance on
8WV-FRANKIE. The browser attempt obtained an active desktop-open receipt and
finished without a window-inventory call. The historical records do not retain
which desktop tools the AI provider was offered on its resumed request, so the
specific browser failure is not yet attributed.

This source adds one bounded record at each actual provider boundary, containing
only a validated operation ID, sequence, round, offered desktop tool names and
an allowlisted availability reason. It records no prompts, tool arguments or
results, account identity, URLs, screen contents, credentials, or exception text.
Each record is at most 1 KiB; existing provider-round limits and log rotation
remain in place. Logging failure does not change execution behavior.

Tool definitions and provider selection are unchanged. Current main's existing
account/device operation check is preserved. No permission preference, model,
prompt, schema or control dispatch changes are included.

## Source and focused checks

The deployed-base diagnostic source is 428f37054c5b88c0310fe64db653c963b71a8b11
over the accepted c0bab8901e7fbfcb25275dfa7696a9b7c66c93e0 runtime. Normal-main
integration is ec2b9a917914f6820b70f63a2c30b8c202412577 over
8de4045538d16570ec598cac2268cb289966d641. The only difference between the three
diagnostic files in those candidates is main's preserved operation-role check
in portal_westy_desktop.php; every other main file is unchanged.

Actual focused checks passed: 18 diagnostic assertions, 21 AI receipt checks,
53 replay assertions, PHP lint for all three changed PHP files, and diff checks.
The diagnostic fixture verifies the record exists before a synthetic provider
callback receives exactly those tool names. It makes no paid provider request.
These checks prove source behavior, not the offered tools in the historical
browser attempt or successful laptop control.

## Production boundary

Production deployment is pending. Its release must preserve the accepted
runtime and current application-artifact record under the existing suite and
report/deploy locks. The c0bab-based cut avoids introducing main's role-helper
dependency or unrelated employee-access libraries, pages or migrations. Do not
copy main's desktop file alone over c0bab: that runtime does not define the
new role helper. Record the exact deployed artifact and source relationship;
do not claim that a partial overlay is a full main deployment.

The separate native candidate 7801d38bb0ac8a855fb2fae1bda2caaec42cf14f repairs
recoverable desktop reads and transient connection handling. It is not installed
or signed as a new release yet. The accepted backup, held security work,
unknown job 4446, explicit Stop/Take control and account/device boundaries remain
unchanged. Frankie owns installation and acceptance; bounded diagnosis through
the existing laptop Codex chat does not replace Westy's own control evidence.
