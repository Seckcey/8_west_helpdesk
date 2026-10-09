# Westy 1.26.13 — October 9 release record

Safeharbor's selected backend is deployed and the paired private Full 1.26.13
installer was delivered to `8WV-FRANKIE`. Frankie owns installation and physical
testing. Delivery does not establish successful installation, browser/desktop
takeover, visual acceptance or full Codex parity.

This checkpoint records the actual October 9 release. The
[October 8 record](westy-control-repair-release-2026-10-08.md) retains its original
source, installer and evidence dates. All times below are UTC unless identified
as Pacific time.

## Source and production

| Item | Verified identity or outcome |
| --- | --- |
| Selected Safeharbor source | `d35c657a185e06d54d1012b87fe4fff8f0284a40` |
| Safeharbor source integration | [PR #196](https://github.com/Seckcey/8_west_helpdesk/pull/196), merged as `36fa16d8c010cbf43bcd096c85324c3a1c82dd30` |
| Selected changes | Previous accepted control repair plus [branding #189](https://github.com/Seckcey/8_west_helpdesk/pull/189), [computer clock #192](https://github.com/Seckcey/8_west_helpdesk/pull/192) and final repair #196 |
| Exact source CI | Validate [37907121840](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37907121840) and mobile [37907121694](https://github.com/Seckcey/8_west_helpdesk/actions/runs/37907121694), successful |
| Reviewed LF archive | SHA-256 `847154e2a900499e1a14fbd2cbdadc2ddef404adfd7fe904d87aaef11c089aae`, 6,087,517 bytes |
| Installed cache-stamped artifact | SHA-256 `de8e5574549bc0e30eb42e78bc26700368df5525fc9644c92b1c72d516547767` |
| Actual Safeharbor installation | 11:29:29–11:29:33; one agreed graceful Apache reload at 11:29:50–11:29:51 (4:29 AM Pacific) |
| Runtime checks | All 15 required source pins match; Apache active; public login HTTPS 200 with no redirect |
| Reports | Original sender, recipients and report scope restored active at 11:31:33 (4:31 AM Pacific); existing cron unchanged |
| Paired Milepost runtime | `f068406c6e6f07df85d89f073384eee8e31776ea`, deployed by [37926284358](https://github.com/Seckcey/8westit_webapp/actions/runs/37926284358); all 449 runtime files verified; one agreed graceful reload at 11:52:04 |

The deployed source is a selected candidate, rather than every application change
in GitHub main. Catalog, reply-feedback, employee-device access and other
intervening application/schema changes are outside this release. This factual
documentation update does not deploy those changes. Held authentication/cache
[PR #181](https://github.com/Seckcey/8_west_helpdesk/pull/181) remains separate.

The computer clock consumer accepts the existing Milepost clock shape. Missing
optional `computer_time` uses an explicit UTC fallback without refusing device
or tool access. See [computer-local-time behavior](WESTY_COMPUTER_LOCAL_TIME.md)
and [companion branding](westy-companion-branding.md). Actual laptop display
acceptance remains with Frankie.

## Evidence and its limits

The source archive was independently reviewed by both app owners: 378 raw Git
files, 358 application files, eight derived assets and 382 safe archive members;
282 packaging PHP lints passed. The same implementation worker's earlier
synthetic Coastline verification passed 529 checks, including 37 replay guards.
These are source and synthetic results, not physical laptop acceptance.

The existing root schema inspector ran once at 11:44:23. It verified the complete
table, both exact trigger definitions and the same four existing rows/data digest.
The restricted application-user diagnostic at 11:45:05 confirmed the three
required columns and current runtime pins. Its zero visible triggers reflect
restricted visibility, not missing triggers. No schema or data mutation occurred.

The schema proof was consumed by the existing reviewed readiness verifier at
12:03:38, within its original lease; the paired Milepost review ran at 12:05:44.
The proof expired at 12:14:23 for new invocations. Those successful historical
reviews remain evidence; the proof must not be retimed or reused for new work.
Both readiness reviews made zero provider requests. Existing unknown execution
history remains recorded; these results do not establish a globally idle endpoint.

At 12:10:03, the actual signed-in customer service and devices startup GETs
returned 200 without cache reuse. AI and tools were available, four devices were
listed and `8WV-FRANKIE` was present. The verification tab was closed while the
existing signed-in tab was retained. No assistant message or control action was
submitted by this service check.

Original provider results retain their genuine times and source identities:
Milepost 00:35:07 and Safeharbor 00:56:15. Complete relevant provider/resolver/
identity source trees, server configuration hashes and current AI selection were
independently checked for equivalence. No new provider probe was required or
performed, and the original tests are not described as fresh tests of the newly
deployed commits. Paired private readiness was sealed by the distinct Milepost
attestor at 12:13:13, preserving the original human and material timestamps.

## Signed installer and actual delivery

The private signing [run 37938173802](https://github.com/Seckcey/8westit_webapp/actions/runs/37938173802)
completed successfully against controller
`75259ce732b2ead80a099d587f000cd50c2b12de`. Independent package verification
covered all 18 artifact files, all 11 embedded MSI payloads and three valid,
timestamped signatures. Versions are Agent 1.26.13, Companion 0.1.9 and x64
Full MSI 1.26.13.0. This was a private delivery, not a general release.

The existing laptop stager verified the actual destination at
`2026-10-09T14:09:27.5866823Z` (7:09 AM Pacific):

| Item | Destination evidence |
| --- | --- |
| Computer | `8WV-FRANKIE` |
| File | `C:/Users/FrankGonzalez/Downloads/Westy-Full-1.26.13-37938173802.msi` on that laptop |
| Size | 116,875,264 bytes |
| SHA-256 | `803a5fb2513c76cd2a6d72d158731a8d38fe2006efc1aba12b80a3f98adcf65f` |
| Signature | Valid 8 West publisher; timestamp present; certificate `B3567B019D5736855E5682C9184E4227979925A0` |
| MSI | x64, version 1.26.13.0 |
| Execution | `Executed=false`; stager returned to idle hold |

The four temporary signing activation fields were closed by normal Milepost
[PR #662](https://github.com/Seckcey/8westit_webapp/pull/662), merge
`2880c83ec402a998d8ef160adaad43042708c32a`. The exact original closed policy
bytes were restored. Installation and physical acceptance are still Frankie's
next step; no agent installed this MSI or claimed an end-to-end control pass.

## Cleanup and retained recovery

Safeharbor's own temporary installer/hasher controls were removed after use.
After both downstream users explicitly released custody, its eight exact
predeploy/schema temporary files and two empty task directories were removed
at 12:19:21. An independent check at 12:20:07 confirmed their absence and
unchanged configuration, report cron and active report activation. Owned
Coastline scratch was also cleaned. Foreign controls, shared parents,
persistent locks, uploads and recovery material were preserved.

The previous Safeharbor `e50ad5198d9267fb1fa469c7a303f365de2b6694` archive,
original report tuple, reviewed inverse overlay and immutable current release
marker remain retained. Recovery order is the old Milepost backend
`cc25a818ba03312d7dd0880b247db24904cf0d1f` first, then old Safeharbor `e50ad519`.
Safeharbor recovery uses the reviewed six-path quarantine overlay and only its
mapped empty directory, followed by the existing archive installer under the
report mutex and restoration of the original report tuple. It requires a
separately agreed recovery/reload window. No recovery was invoked; an unknown
or partial result must be investigated before replay.

This release made no migration, configuration/provider/recipient change, new
canary, new backup or native installation. Existing worker, release and technical
lock ownership remains intact. Safeharbor has no remaining owned temporary
container, process, reload window or transport cleanup from this release.

The immutable Safeharbor evidence is retained outside the application repository
under coordinator key `047d1af0a10d6406a78c6201`, release directory
`releases/westy-control-12613-sh-segment-20261009-01a11f40/`.
The complete 81-file evidence map has SHA-256
`48e5e93a782851743ea3321cc7b219aeb6a510d3410df56bb101789da5da5548`;
the delivery receipt SHA-256 is
`2819ecf87e53966deb711334492dcaae8b2343e5ea5de31a661cbf3051442953`.
Protected server recovery material remains in the existing release-evidence
directory. These references retain the underlying observations without
publishing credentials or claiming new tests.
