# UI verification tool inputs

The development-only walkthrough and Westy check require an explicit local or
staging target and an account supplied by the operator. They have no default
production origin or seeded sign-in. These tools are outside the application
deployment package and do not change Westy's product permissions.

| Tool | Required environment variables | Optional output |
|---|---|---|
| `tools/shots/walkthrough.mjs` | `BASE`, `SH_EMAIL`, `SH_PASSWORD` | `SHOTS`, default `C:/tmp/shots` |
| `tools/shots/westy-check.mjs` | `SH_BASE`, `SH_EMAIL`, `SH_PASSWORD` | `SH_SHOT` |

Use an origin with its scheme and no trailing slash, for example
`http://localhost:8080`. Supply credentials for an existing account on that
instance through the operator's environment; do not commit them. The target
variable names remain distinct for compatibility with existing invocations.

Install the tools' existing Playwright dependencies through the established
development setup. Run the desired script from `tools/shots` after setting its
required variables. Unset, empty or whitespace-only required inputs produce a
message naming the missing variables and exit code **2**, before importing
Playwright, launching a browser, creating an output directory or contacting a
host. The Westy check retains exit code **1** for rejected sign-in or no queue.

The walkthrough changes ticket priority and sends a reply. Use disposable test
records and the intended local/staging account. No production walkthrough,
customer email or seeded-account cleanup is part of this change. The separate
login-page prefill and wider S01-03/G28 security work remain outside this subset.

Source validation for Claude candidate `4e89a6c7f8bbb70725debce7e7e9e8f8effcbec6`
passed both Node syntax checks and six no-network missing-input cases. Neither
tool was run with every input present; the browser and screenshot paths were
reviewed but have no new end-to-end execution result. This source result does
not claim production changes or close the wider security finding.
