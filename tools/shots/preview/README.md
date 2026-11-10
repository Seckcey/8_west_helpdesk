# Look previews

Static pages that load the real stylesheets from `app/public/assets/css/` so a reviewer can see the
Safeharbor look without a server, a database or a sign-in. Every name, address and number in them is
made up.

| Page | What it shows | Where the markup comes from |
|---|---|---|
| `queue.html` | Staff queue | copied from `app/lib/render.php` and `app/public/index.php` |
| `queue-first-run.html` | Staff queue in a new workspace, with Get set up | copied from `app/public/index.php` and `app/lib/first_steps.php` |
| `ticket.html` | Staff ticket | copied from `app/public/ticket.php` |
| `clients.html` | Clients list and number tiles | copied from `app/public/clients.php` |
| `team.html` | Team page with Invite a teammate and the backup sign-in disclosure | copied from `app/public/users.php` |
| `signin.html` | Staff sign-in | copied from `app/public/login.php` |
| `portal-home.html`, `portal-home-first-run.html`, `portal-devices.html`, `portal-ticket.html`, `portal-signin.html` | Customer portal (`portal-home-first-run.html` is an owner's Home before any computer exists) | rendered by the real templates (see below) |

## Open them

Serve the repository root with any static server and open a page, for example:

```bash
python -m http.server 8765 --bind 127.0.0.1
```

Then visit `http://127.0.0.1:8765/tools/shots/preview/queue.html`. Add `?theme=light`, `?theme=dark` or
`?theme=system`, or use the switch in the lower-left corner. Opening the files straight from disk also
works in most browsers.

The staff pages carry no app JavaScript, so menus, the palette and the timer do not respond. The
portal's phone menu opens when `portal-sidebar` gets the `is-open` class.

## Regenerate the portal pages

```bash
node tools/shots/preview/build-portal.mjs
```

It reuses the synthetic fixture from `tools/shots/portal-contract.test.mjs`, renders it with the real
`app/lib/portal_render.php`, swaps the fixture's names for made-up ones and rewrites asset paths. It
refuses to write a page that still contains a fixture name, because this repository is public. It needs
`php` on the path and nothing else.

The staff pages are hand copies: if a template's markup changes, update the matching preview by hand.
