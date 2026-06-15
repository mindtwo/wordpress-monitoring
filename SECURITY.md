# Security Policy

## Reporting a vulnerability

Please report security issues by email to **info@mindtwo.de** — do not open a
public issue. We will confirm receipt, investigate, and coordinate a fix and
disclosure timeline with you.

## Design notes

- Secrets are never transmitted: requests carry an HMAC-SHA256 signature over
  `"{timestamp}.{payload}"`; verification uses constant-time comparison and a
  timestamp tolerance window against replays.
- External commands always run as argv arrays (no shell interpolation) with
  bounded timeouts.
- The HTTP transport enforces http(s)-only endpoints, verifies TLS and never
  follows redirects.
