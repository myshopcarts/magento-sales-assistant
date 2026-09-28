# Security Policy

## Security model

This extension is a **thin client**: a storefront chat widget plus a small server-side
client for a hosted backend. It contains no proprietary logic and, by design, **no
credentials and no backend endpoints in its source code**.

- **Secret handling.** Your subscription secret key is stored in Magento's **encrypted**
  configuration (an `obscure` field with the encrypted backend model). It is used only
  **server-side**, to mint short-lived browser tokens. It is **never** sent to the browser
  and **never** written to shared or exported `config.php`. The browser receives only a
  short-lived, single-purpose token.
- **No hardcoded endpoints.** Every backend endpoint (API base URL, WebSocket URL) is read
  from **Magento store configuration**, not baked into the code. The extension is portable —
  point it at any compatible backend from the admin config.
- **Least exposure in the browser.** The widget talks to the backend only via a same-origin
  token exchange and the short-lived token; the secret never reaches client-side code.

## Enforced by CI (release gate)

Every public release is produced by an automated pipeline that **blocks the release** if the
source contains any of the following. This makes *"no secrets, no hardcoded endpoints, fully
config-driven"* a **verified property of every published version**, not just a convention:

- **Secrets / credentials** — API keys, tokens, private keys, or high-entropy secret-like
  strings (entropy + pattern scan).
- **Hardcoded backend or environment-specific endpoints** — cloud API-gateway URLs,
  WebSocket URLs, cloud storage endpoints, or any host that should come from configuration.
- **Internal / private infrastructure references** — private or internal DNS names, RFC-1918
  address literals, cloud resource identifiers, or account identifiers.

Because backend endpoints must come from configuration, **any literal endpoint URL in the
code is treated as a defect** — even though it is not, strictly, a secret. Documentation and
examples use placeholder hosts (e.g. `https://your-api.example`) so a real environment-specific
URL can never be mistaken for an example.

## Reporting a vulnerability

Please report suspected security issues **privately** — do **not** open a public issue.

- Preferred: GitHub's private vulnerability reporting for this repository (Security → Report a
  vulnerability), or
- Email: `security@myshopcarts.com`.

We aim to acknowledge reports within a few business days and will keep you informed as we
investigate. Please include the version, affected file(s), and steps to reproduce.

## Supported versions

Security fixes are provided for the latest published `1.x` release. Please upgrade to the
latest version before reporting an issue.
