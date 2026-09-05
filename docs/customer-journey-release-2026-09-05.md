# Safeharbor customer journey release — September 5, 2026

Safeharbor's approved-time billing buttons, mobile suite navigation and Logbook
knowledge source are live. The existing Lifestyle billing connection stays
enabled for normal staff use, and its weekly report scheduler was restored at
`2026-09-05T09:44:37Z`. This receipt records a release and connection restoration;
it did not create a test invoice, send a new report email or exercise payments.

## Exact release and the packaging correction

| Evidence | Verified value |
|---|---|
| Application changes | [PR #100](https://github.com/Seckcey/8_west_helpdesk/pull/100), initial live source `5c6ca350e4e5f1dcc84d56204b09e9f127da48f6` |
| Follow-up packaging correction | [PR #101](https://github.com/Seckcey/8_west_helpdesk/pull/101), current live source `dbdb92a6a36a4820a0f6d77211f75d4ffc17d943` |
| Exact-main validation | [Run 33958596962](https://github.com/Seckcey/8_west_helpdesk/actions/runs/33958596962), success; PHP and browser contracts passed |
| Deployed application | `/srv/8west/apps/safeharbor/current` on the established Milepost EC2 host |
| Source artifact SHA-256 | `d9e53bdd1781b7005c2ccee9d6fd50d6cd9fa53b7fdff7558befcaf5c0df08c4` |
| Deployed artifact SHA-256 | `2029d5615050ffd8809d5ea8ca84ec2cd71167ea70ca5d761f4db9cc3075401e` |
| Immutable release marker SHA-256 | `14405e29ffa0003a8fb0458ca5d0f3f29e17fc2d0b391058b029632f6c0d419d` |
| Protected config SHA-256 | `8ba44c0862a9f9183626961010e6429e8fef93a106952c6602a8e9f8a2c9463f` |
| Public check after restoration | `https://safeharbor.8westit.com/login.php` returned HTTP 200 |

The initial September 5 artifact
`de51b9ce1718838207be32a929a90c6e9a56ac89939034bb2065860f35500fb4`
contained Windows CRLF bytes in the Linux report wrapper. Linux Bash rejected
that wrapper's syntax, so the report schedule remained stopped during repair.
PR #101 pins shell scripts and cron files to LF in `.gitattributes` and checks
the staged wrapper syntax in the existing deployment script. Its regression
reproduced 151 CRLF wrapper lines and 10 CRLF cron-template lines in the old
Windows archive; the corrected archive has zero and passes Linux Bash syntax.

The current release was deployed from an isolated clean LF checkout through
`deploy/deploy.sh`, with `core.autocrlf=false` scoped to that process. The live
wrapper and PHP runner now exactly match the previously reviewed report code:

- Wrapper SHA-256: `3df5c26b60bd81e64fbc5091fc2230ab68d7191cd9ec5d74179b6902656b14b3`.
- Runner SHA-256: `0f40f5215196653279e38bdcab2444926cdafb5247ec1f1b8078f2f45fcabad0`.

No migration was required. The deployment preserved the protected config and
application data. The initial verified backup is
`/srv/8west/backups/safeharbor/20260905-suite-journey` and includes application,
database, protected config, prior release marker and prior scheduler evidence.
The line-ending redeploy additionally preserved the immediately preceding
config/marker in `/srv/8west/backups/safeharbor/20260905-scheduler-lf-redeploy`.
These are protected operator backups; their contents are not public artifacts.

## Existing connections and practical use

The authorized configuration changes were the existing Safeharbor time export's
`enabled` and `claim_enabled` values plus the dedicated `logbook_export` block.
A host-local comparison verified report settings and every unrelated setting
were unchanged. Coastmark's separately owned receiver and mapping 3 were enabled
for the existing Lifestyle customer, client 7, agreement 1 and time line 1; its
existing hourly rate was preserved. The master provider remains excluded.

Staff now use **Time → Approved time & billing**: approve time, select **Send to
billing**, then **Open in Coastmark** to review the draft. Later approved
corrections use **Send next adjustment**. **Check billing status** resolves an
interrupted request. The connection remains enabled; these steps do not require
an operator to open and close a server-side test window for each entry.

At `2026-09-05T09:34:49Z`, a production read-only check using the new helper and
dedicated export database identity returned **In Coastmark · version 0** for
existing time entry 10 / claim 1. The actual signed receiver status returned
HTTP 200 and confirmed invoice 9 / line 17 against that exact claim. The helper
linked to `https://coastmark.8westit.com/invoices/9`. No claim, receipt, invoice,
payment or ledger write was made by this verification. Invoice 9 remains draft;
new financial actions were not used as a release test.

Logbook's read-only schema-1 source is enabled with service identity 6 and the
existing configured tenant/customer bindings. It quotes eligible public
technician replies at or before resolution, marks them low confidence, and
leaves review/publishing to Logbook. The first actual scoped import found zero
eligible Safeharbor candidates. This proves a connected empty source, not a
published knowledge article or an invitation to manufacture ticket content.

Broad customer receipt intake, report-contact lookup and managed-customer
activation workers remain off. The existing Lifestyle portal, report schedule
and time-billing connection are on. An additional customer still needs its own
real binding and agreement setup.

## Weekly reporting restoration

The scheduler was stopped at approximately `09:19Z` for the application release
and restored at `09:44:37Z`. The existing manager completed **preflight →
install-disabled → verify disabled → enable exact existing tuple → verify
active**. The `cron` service is active. No report command or canary delivery was
invoked; the restored cron entry resumes normal scheduled operation.

The reviewed controls live in
`/root/safeharbor-report-scheduler-dbdb92a6a36a4820a0f6d77211f75d4ffc17d943`.
Its bundle manifest SHA-256 is
`63e61f60dc1671381c5f572d3c347c79d201b24cdebd295ba2dfcae9cf8f1cd7`.
The new root-only activation evidence is in the corresponding
`/root/safeharbor-report-scheduler-activation-dbdb92a6a36a4820a0f6d77211f75d4ffc17d943`
directory, with SHA-256
`3c0ccc75851665bb1b2110b96d1ada188ad7750a2f549acf061bb2f6819c4e2e`.

The release/artifact/config binding and actual review time were refreshed.
The sender, customer, recipient, schedule and original real delivery evidence
were preserved unchanged:

- Graph accepted archive 4 at `2026-09-01T01:05:12Z`.
- The recipient confirmed that archive at `2026-09-01T02:36:13Z`.
- Confirmed archive SHA-256: `44563193c82d0df28c8e6a6fe2663ad0dca3e3355e3abaf483bbcc563688c25a`.

These September 1 facts are retained evidence, not a new September 5 canary.
The private recipient value was not copied into this receipt. The existing
Wednesday 09:00 Pacific Lifestyle schedule remains the only approved managed
schedule. Archive 5's September 2 scheduled Graph acceptance remains separate
from archive 4's human inbox confirmation. Historical terminal sends are not
replayed.

## Verification limits and recovery

The usability change passed 42 exporter checks, 16 staff billing checks, 14
browser checks and local desktop/mobile inspection. The packaging correction
passed its Linux archive regression and both GitHub validation jobs. These
isolated checks, signed production service verification and public HTTP checks
are separate from fresh signed-in production acceptance. That acceptance
confirmed the Time page showed entry 10, version 0 and the correct invoice link.
It also exposed a separate Coastmark problem: a fresh app session reached
Microsoft-only login instead of using the existing ID session and returning to
invoice 9. Its login handoff fix is a separate follow-up; this Safeharbor receipt
does not claim the complete app-to-app navigation already passed.

If a release needs recovery, use the existing deployment and scheduler manager
with the retained application/config/database backups and exact recorded
artifacts. Stop the report scheduler before another source deployment, then
rebind its activation to the verified release and unchanged delivery evidence.
Do not patch files in the live tree or replay a historical report to prove
recovery. Keep the existing Coastmark billing history and reviewed customer
connection intact.
