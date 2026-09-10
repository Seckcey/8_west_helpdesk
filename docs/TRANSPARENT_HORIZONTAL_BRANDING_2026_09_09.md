# Transparent horizontal branding — September 9, 2026

The active horizontal branding uses Frankie's supplied `safeharbor-logo-horizontal-transparent-20260909.png`.
The source PNG is copied unchanged, with real alpha transparency and dimensions
1851 x 513. Display rules preserve the complete artwork without cropping.

Source SHA-256: `f61e01db9a8831558de2a989c9782caa30310992f778479a5cb1fbbbde170c91`.

Square app icons, avatars, historical assets and product workflows are unchanged.
Dated asset URLs avoid reuse of the earlier opaque image from browser/CDN caches.
This is a presentation-only release with no database or dependency changes.

## Production acceptance — September 10, 2026 UTC

Frankie requested the supplied transparent horizontal logos across the suite and confirmed implementation with `begin`. The existing safe-release authorization covered deployment to Milepost EC2.

- Live source: `9d57eed6ae5c806210a387bedba5f83e952a18be`; [implementation PR](https://github.com/Seckcey/8_west_helpdesk/pull/119).
- Exact release validation: [successful CI](https://github.com/Seckcey/8_west_helpdesk/actions/runs/34430861656).
- Protected backup: `/srv/8west/backups/safeharbor/20260910T025914Z-pre-transparent-branding`.

The complete artifact digest is e208a7f4de8167d0f14f24c120f2fd53bf65cf5d6afa0a41780253a07942b529. The first overlay attempt correctly refused the obsolete 20260907 horizontal PNG left by the previous deployment. An exact comparison found that single extra file with no missing or different release files. Its original bytes were retained in the verified rollback backup, and the normal installer then completed with the exact source and deployed digests. The report scheduler was paused through its protected manager and restored active with unchanged delivery code/configuration and the original real canary evidence; no replacement email was sent. Exact-main CI passed on its second attempt after an existing publication-lock timing test failed once. Signed-in Queue and its phone navigation displayed the full logo.

The public logo returned HTTP 200 and its SHA-256 matched the supplied source PNG above. The rendered logo retained its full artwork with no page-width overflow in the checked desktop and 390px layouts. This verification covers branding and the existing signed-in navigation; it does not certify unrelated product workflows.
