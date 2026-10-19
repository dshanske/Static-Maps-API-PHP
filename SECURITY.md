# Security policy

## Reporting a vulnerability

Please **don't** open a public issue for security problems. Report them privately through GitHub instead: go to the repository's **Security** tab and choose **Report a vulnerability**. Include what you found, how to reproduce it, and which version or commit you used.

You can expect an answer within a week. Fixes are released as soon as they are ready, and the report is credited in the changelog unless you prefer otherwise.

## Supported versions

| Version | Supported |
|---|---|
| Latest 1.x release | Yes |
| `main` before 1.0.0 | Fixes land on `main` |
| Older releases | No; please update |

## Running an install safely

- Give each site or caller its own client key (`php bin/clients.php add <id>`), so one can be revoked without the others, and keep `data/clients.json` readable only by PHP. Only requests with a valid key may use maps that cost you provider credit, or make the server download from addresses they choose (marker icon URLs, attribution image URLs, custom tile URLs). With `access.mode = 'restricted'`, every map needs a key.
- Downloads from other servers are limited by the `remote` setting (time, size, redirects; http and https only) and image sizes by `limits`.
- Turn off `display_errors` in production (see the README).
