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

- Keep the token file (`token_file`, default `data/apikeys.txt`) private and use long random tokens. Only requests with a valid token may make the server download from addresses they choose (marker icon URLs, attribution image URLs, custom tile URLs).
- Downloads from other servers are limited by the `remote` setting (time, size, redirects; http and https only) and image sizes by `limits`.
- Turn off `display_errors` in production (see the README).
