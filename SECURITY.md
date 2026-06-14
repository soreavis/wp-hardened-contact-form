# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | Yes       |

## Scope

This project is a set of **template files** for a hardened WordPress + Sage contact form. It does not run as a hosted service and stores no credentials itself — secrets (Resend API key, Turnstile keys) live in your `.env`, which is gitignored. `.env.example` ships placeholder-only.

The security-relevant concern here is a **bypass**: a spam/abuse pattern that defeats one of the documented defense layers, or a validation rule where the JS and PHP sides disagree in an exploitable way.

## Reporting a Vulnerability

**Do not open a public issue for a bypass or vulnerability.**

Please report privately via [GitHub Security Advisories](https://github.com/soreavis/wp-hardened-contact-form/security/advisories/new).

Include:
- Which layer is bypassed
- The payload shape / steps to reproduce
- Potential impact
- Suggested fix (if any)

You'll get a response as soon as possible. Please allow time to assess and patch before any public disclosure.
