# ADR-003: TOTP 2FA via scheb/2fa-bundle + Google Authenticator

**Date:** 2026-05-24  
**Status:** Accepted

## Context

2FA is required before any document signing. Options: custom TOTP implementation, scheb/2fa-bundle, or a cloud MFA provider.

## Decision

`scheb/2fa-bundle` v8 with `scheb/2fa-google-authenticator` provider.

- TOTP (RFC 6238) — compatible with Google Authenticator, Authy, 1Password, Bitwarden, and any standards-compliant app
- The bundle integrates directly into Symfony's security firewall — no custom authenticator to maintain
- QR codes rendered with `endroid/qr-code` v6 using `SvgWriter` (GD is built into the Docker image, but `SvgWriter` needs no image extension and stays portable to non-Docker runs)

**Setup flow:** TOTP is **not** enforced at registration. After first login the dashboard shows a warning banner. The user opts in at `/2fa/setup`, scans the QR code, and enters a code to confirm. `User::totpEnabled` flips to `true` only after successful verification.

**Key implementation note:** The scheb bundle renders the 2FA form template itself and injects its own variables — not Symfony's standard `authenticationUtils`. Template must use `authenticationError` (string key), `authenticationErrorData` (array), `checkPathRoute` / `checkPathUrl`, `authCodeParameterName`, `logoutPath`. These differ from the variables injected in a normal login form.

## Consequences

- `User` implements `Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface`
- Access control: `^/2fa$` and `^/2fa_check` require `IS_AUTHENTICATED_2FA_IN_PROGRESS`; `/2fa/setup` and `/2fa/disable` require `IS_AUTHENTICATED_FULLY` (falls through to the default `^/` rule)
- Using `^/2fa` as a prefix rule in `access_control` would block setup/disable for fully-authenticated users — use `^/2fa$` (exact match) instead
