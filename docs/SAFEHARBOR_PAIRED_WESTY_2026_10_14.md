# October 14 paired Westy release receipt

Safeharbor source `2c552151580cd9df7a00cb28b24e2dc9304ce1a6` was installed
first, followed by Milepost source `a0c709ba9021e7b6c0fa2d3b10a0446a5b2129fc`.
The human instruction “You handle both updates” transferred the bounded
Safeharbor script execution claim; the agreed Milepost executor retained the
second update. Neither operation authorized another application, installer,
schema change, new controller, configuration key or product flag.

## Safeharbor runtime and rollback

The clean exact-commit worktree ran the unchanged `deploy/deploy.sh` once.
Its tool return was truncated, so completion was established independently
from the installed tree and records; the deployment was not blindly replayed.
The record mtime was October 14, 13:50:27 UTC (6:50 AM Pacific).

| Evidence | Actual value |
|---|---|
| Clean source artifact SHA-256 | `6f5e22d2170c32892b4eaecf7a982f64f7f06ac9307bdaa72948a51210832c7c` |
| Complete deployed artifact SHA-256 | `e3d7448714fdec668aabb736dcd6a22199d8071153bb9418f8efb842acddf325` |
| Current/immutable record SHA-256 | `d482ce18a3315f9ec65a9c678beb772fabefaba87804300668d2b2f4a0861593` |
| Unchanged protected configuration SHA-256 | `6f2306ea34c41afc874d345d96fd6b0539aab4de30699fc07f7d10868033bee2` |
| Actual pre-update code archive SHA-256 | `5328005ce6f335f2ab6db80a7e2f23b1581dc444d5b6c8afdf9c085558609ece` |
| Restored pre-update runtime artifact SHA-256 | `067432593287105a2740ee872b81525073c98c4efc2ce7be1e78e5de660f5ede` |

The actual runtime archive is retained root-only at
`/var/lib/8west-release-evidence/safeharbor-paired-yolo-20261014-01a137cd/rollback-c54-actual/actual-current-code.tar.gz`.
It captures the installed `c54d2252a17aafe01df4ac16f24e0f4ff4abf17a` code;
scratch restoration matched the complete runtime hash and 402 ownership/mode
entries. A rebuilt clean Git inverse is separate evidence and is not described
as the retained runtime archive. Private configuration and report bindings
remain server-only. No new database capture or restore was performed.

The full c54-to-2c source gap contained six reviewed PRs (#227–#232), 19 changed
paths, and no tracked database, configuration, deploy-control or brand change.
This includes existing-window guidance, intake-purpose filtering, model/effort
controls, long data receipts, neutral member wording and the typed optional YOLO
proxy/UI. PR #232 Validate run 38565493170 and exact-main Validate run 38568182979
succeeded. The deployed full hash and current/immutable records were independently
compared, rather than treating CI or a release marker alone as runtime proof.

## Existing report schedule restored

The existing manager disabled new report launches for the code update, then
passed preflight and restored ACTIVE state. The release-only rebind follows
[the report contract](business-reports-contract.md): unchanged delivery code and
protected configuration permit preserving the original real sender, customer,
client, schedule, recipient, Graph acceptance, recipient confirmation and archive
evidence while rebinding the actual release/artifact fields. All ten private
values and original canary clocks were compared exactly. Actual gate review time
was 13:55:35 UTC. No replacement email or fabricated canary was used.

The new control directory contains exactly the unchanged manager, cron, hasher
and new bound manifest, under root-only ownership. Bundle SHA-256 is
`fed70ebee8b95148b9bf25ca9a45e2ef8e7ef8ea89bb7fb2e7e77a89f93bba84`;
activation SHA-256 is
`046420a4edaf222d4bfb9c9c469dee2e633dcaac9b4767cbeb034edf0e7e5c1b`.
Configuration bytes and ubuntu:www-data 0640 ownership/mode remained unchanged.

Read-only postflight preserved 775 schema columns with digest
`20ebbadc027bee2876263e89279f0837078434e02be824fda999a14b5cb2cb5d`.
Report deliveries and delivery attempts each remained 11 submitted / 2 uncertain;
mail queue remained 2 total / 0 unsent / 0 retry eligible. Uncertain work was
not reset or retried. The existing scheduler's active verification passed.

Suite and Safeharbor app kernel locks were held during the update. The owning
session exited successfully after release; at 13:59:00 UTC its custodian PID and
both own FLOCK records were absent. Lock files were preserved. Only then was
the exact runtime/backup/report/postflight/release handoff sent to Milepost.

## Milepost second update and live UI acceptance

Milepost installed A0 at 14:03:00 UTC (7:03 AM Pacific). Protected preaudit
38576750188, apply 38576960497 and postaudit 38577148978 succeeded. Its executor
independently verified 466/466 file hashes with no extra or mismatched files,
unchanged protected configuration and login HTTP 200 after graceful Apache
reload. The deployment changed two files with no deletions. Its actual backup
contains the preceding DC versions of both replaced reader/writer files;
the compatible decoder was unchanged. The executor released its own suite
lock before handing over preference acceptance. Original job 4446 and other
unknown execution history were preserved.

Normal existing 8 West ID sign-in reached both the Safeharbor staff queue and
the actual customer-owner portal. Before Milepost changed, the new Safeharbor
UI correctly omitted YOLO against the older API. After A0, reload showed
“Run commands without asking me (YOLO)” checked. The preceding production
preference snapshot had no stored YOLO key, so this establishes the deployed
missing-key default in that account.

The authorized reversible same-account test then:

1. Recorded the initial checked YOLO choice, all six tool restriction choices,
   the Allow unresolved-result preference and the ask-before-automatic-check
   choice.
2. Unchecked YOLO and used Save permissions; the UI reported success.
3. Reloaded and verified that YOLO remained unchecked.
4. Restored the exact initial checked choice, saved, and reloaded.
5. Compared every initial checkbox and the unresolved-result preference;
   all choices matched exactly.

Two normal preference saves advanced the revision and materialized an explicit
stored true YOLO value in that account. This intentional acceptance change is
distinct from the clean pre/post deployment preference baseline. No other
choice was changed, no raw database/profile/session write was used, and no chat,
provider or device command was issued. Screenshots and the initial/OFF/restored
choice receipt are retained in the task's paired-release evidence folder.

The preserved “Ask me before each automatic computer check” choice was checked,
so this account's `automatic_diagnostics` preference remains false. Under the
reviewed Milepost #717 rule, that existing choice can still require approval
even when YOLO is true. Tenant policy overrides, administrator/SYSTEM work and
the existing safety-review approval rules also remain in force. The test did
not turn off the account's explicit automatic-check approval preference or
establish that all commands now run without prompts.

## Remaining acceptance and recovery boundaries

This receipt proves the paired backend runtime and the normal signed-in
preference UI. It does not establish physical computer execution, every customer
path, or completion of the broader customer v2 rollout. Milepost's existing
isolated Coastline database fixture passed all 303 assertions, including older
payload omission behavior, and its temporary resources were removed. That
finite synthetic proof remains separate from the live preference evidence.

This paired update did not invoke or recreate the original 8WV inactive event.
Its subsequent separately authorized one-event recovery and signed-in selector
proof are recorded in the [R1 recovery receipt](SAFEHARBOR_R1_ORIGINAL_EVENT_RECOVERY_2026_10_14.md).
Retained unknown delivery history remains unknown; the intake-purpose selector
change alone did not establish that original event's delivery. No
installer, other-app runtime, identity, provider, financial or schema release
is included. This documentation-only closeout does not change deployed code.
