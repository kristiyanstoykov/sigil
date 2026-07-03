# Sigil — MVP Plan

> **Sigil — Signum Veritatis** *(The Mark of Truth)*
> Master's Thesis Project — Cybersecurity, Technical University of Sofia
> Target defense window: **28.09 – 05.10.2026**

---

## Project Overview

Sigil is a Symfony-based web application for digitally signing PDF documents, modeled after services like Evrotrust and Borica. The MVP allows authenticated users to upload PDFs, sign them with a server-stored cryptographic certificate (PIN-protected), choose between attached/detached and visible/invisible signatures, embed RFC 3161 timestamps, and route documents to other registered users for counter-signing. Signed documents produce technically valid PAdES signatures that PDF readers (Adobe Acrobat, etc.) recognize as cryptographically valid, though not as trusted (the issuing CA is self-signed and not in Adobe's AATL).

The architecture is built for extensibility from day one: new file types, signature formats, and signature variants can be added without modifying core logic.

---

## Goals & Non-Goals

### MVP Goals
- User registration and authentication with 2FA
- Self-hosted Certificate Authority issuing per-user X.509 certificates
- Per-certificate PIN that is the PKCS#11 token's User PIN (keys generated in-token, never exported); DB stores only an Argon2id hash of the PIN
- PDF upload with envelope-encrypted at-rest storage
- PAdES-B-T signing (PAdES baseline + RFC 3161 timestamp) — **attached only for MVP**, visible/invisible variants. Detached (.p7s) stays behind the strategy abstraction (`SignatureFormatInterface` variant config) so it can be plugged in later without core changes.
- Visible signatures use **automatic placement**: start at the bottom-left of the page, fill rightward, skipping rectangles already occupied by existing signature widgets (enumerated via pyHanko). No drag-drop placement UI.
- Document routing between registered users with state machine
- Signed PDF delivery receipts with audit trail
- Tamper-evident audit log (hash-chained)
- Modern, minimalist UI in light theme with subtle glass accents

### Out of Scope for MVP (mention as Future Work in thesis)
- Face scan + Bulgarian ID OCR registration flow (the `User.egn` field is kept in the domain model now so this can bind identity later; EGN is stored encrypted at rest and never displayed in admin views)
- Detached (.p7s) signatures — abstraction in place, implementation deferred
- Drag-drop visible-signature placement (MVP uses automatic bottom-left placement)
- Identification dossier PDF generation (Borica/Evrotrust style)
- Mobile applications
- Dark theme
- Trusted CA inclusion (Adobe AATL, EU Trusted Lists)
- **Certified** hardware HSM / QSCD and qualified-signature status — note that a **SoftHSM2 software token IS in the MVP** for correct key-custody architecture (keys generated in-token, never exported; see ADR-005). Certified hardware and the certified SAM/SAD sole-control mechanism (CEN EN 419 241-2 / CSC API) are future work.
- Post-quantum **signatures** (ML-DSA / SLH-DSA) — not yet supported by pyHanko / PAdES / CMS; isolated behind `PadesSignerInterface` for later. (Storage encryption is already quantum-resistant.)
- Multi-language UI (Bulgarian/English) — English only for MVP

---

## Branding & Visual Identity

### Name & Tagline
- **Name:** Sigil
- **Tagline (subtitle):** *Signum Veritatis*
- **Logo concept:** Stylized "S" letterform that flows into a handwritten signature stroke — defined letter at top, dissolving into a flourish at the bottom. Single-color, lighter blue on darker blue background.
- **Logo color:** `#3B82F6` (blue-500) on dark surfaces; `#1E3A8A` (blue-900) on light surfaces

### Color Palette

**Primary**
- Primary: `#1E3A8A` (blue-900) — buttons, links, accents
- Primary hover: `#1E40AF` (blue-800)
- Primary light: `#3B82F6` (blue-500) — for logo on dark, secondary accents

**Neutrals**
- Background: `#FAFAFA`
- Surface: `#FFFFFF`
- Surface elevated: `#FFFFFF` with shadow
- Border: `#E5E7EB`
- Text primary: `#0A0A0A`
- Text secondary: `#6B7280`
- Text disabled: `#9CA3AF`

**Semantic**
- Success: `#059669` (signed, verified)
- Warning: `#D97706` (pending, in progress)
- Danger: `#DC2626` (rejected, failed, expired)
- Info: `#2563EB` (neutral notifications)

### Typography
- **Sans-serif:** Sofia Sans (self-hosted variable font, OFL — `assets/fonts/Sofia_Sans/`; no external font origins, satisfies the zero-external-origin CSP)
- **Monospace:** system monospace stack (for hashes, fingerprints, technical IDs); a self-hosted mono face can be added the same way later
- **Sizes:** Tailwind defaults; body 14–16px, modest heading scale

### Design Language
- **Foundation:** Material 3 (elevation, motion, button hierarchy)
- **Accent:** Subtle glass effects (`backdrop-filter: blur`) on:
  - Sidebar navigation
  - Modals (especially PIN entry)
  - Toast notifications
  - Selected document cards (on hover/active)
  - Signature placement panel (when dragging signature box on PDF)
- **NOT glass:** headers, tables, forms, primary content cards (kept solid for readability)
- **Border radius:** 8px buttons, 12px cards, 16px modals
- **Shadows:** Material 3 elevation system, subtle (`shadow-sm`, `shadow-md` from Tailwind)

### Animation Principles
- 150ms button hover (scale 1.02 + brightness)
- 200ms card hover (subtle lift)
- 250ms modal entry (scale 0.95 → 1.0 + fade, ease-out)
- 200ms page transitions via Turbo
- 300ms status change transitions (color/icon morph)
- Skeleton loaders for async content (gentle pulse, not aggressive shimmer)
- No FABs, no ripple effects, no bouncy easing

### Theme
- **MVP:** Light theme only
- **Architecture:** CSS custom properties throughout so dark theme can be added later by swapping variable values

---

## Technology Stack

| Layer | Choice | Rationale |
|---|---|---|
| Backend framework | Symfony 8.x | Mature, batteries-included, examiner-friendly |
| Language | PHP 8.4 (8.5 locally) | Modern PHP, strong typing, enums, readonly properties |
| Database | PostgreSQL 16 | Better than MySQL for binary data, JSON, integrity constraints |
| ORM | Doctrine 3 | Standard with Symfony |
| Frontend templates | Twig | Symfony default |
| Frontend interactivity | Hotwire (Turbo + Stimulus) | SPA-like UX without JS framework overhead |
| Real-time updates | Mercure | Server-sent events for async signing status |
| Async/queue | Symfony Messenger + Redis transport | For background signing jobs. *Currently on doctrine transport; Redis container + migration land with Phase 7* |
| Cache | Redis | Sessions, rate limiting, Messenger transport |
| CSS framework | Tailwind CSS 4 (via symfonycasts/tailwind-bundle) | Utility-first, fast iteration, no Node.js required |
| Component library | Flowbite (Tailwind components) | Pre-built modern components, imported via AssetMapper |
| Icons | Lucide | Clean line icons, consistent |
| Auth | Symfony Security Bundle + scheb/2fa-bundle | Standard, well-documented |
| Crypto / X.509 | phpseclib v3 | Pure PHP, mature, handles CA + cert generation |
| Key custody | SoftHSM2 + PKCS#11 (swappable to hardware HSM by config) | Keys generated in-token, never exported — ADR-005 |
| Data encryption | `ext-sodium` AES-256-GCM via `EncryptionServiceInterface` | No Symfony crypto component; one NIST primitive + versioned self-describing envelope — ADR-004, ADR-006 |
| PAdES signing | pyHanko (Python) via Symfony Process | Best-in-class open-source PAdES library |
| Timestamping | FreeTSA (RFC 3161) + local TSA container fallback | Free public TSA; a small self-hosted RFC 3161 TSA in compose removes the demo's only internet dependency |
| File storage | Local filesystem (abstracted via interface) | Simple for MVP, swappable to S3 later |
| Transactional email | Brevo (formerly Sendinblue) — free tier 300 emails/day | Official Symfony bridge (`symfony/brevo-mailer`), no credit card required for free tier |
| Containerization | Docker + Docker Compose (2 containers: app + db) | Reproducible for thesis defense |
| Code quality | PHPStan level 8, PHP CS Fixer, PHPUnit | Demonstrates rigor |
| Static frontend tooling | Symfony AssetMapper + symfonycasts/tailwind-bundle | No Node.js needed for CSS pipeline |

---

## Architecture Principles

These patterns must be enforced from the first commit and discussed in the thesis as architectural decisions.

### Strategy Pattern for File Types
Abstract `SignableFile` with concrete implementations:
- `PdfFile` (MVP)
- Future: `DocxFile`, `XmlFile`, `GenericFile`

Each declares supported MIME types, max size, available signature formats, and validation rules.

### Strategy Pattern for Signature Formats
`SignatureFormatInterface` with implementations:
- `PadesSignature` (MVP)
- Future: `CadesSignature`, `XadesSignature`

Each knows how to produce and verify its format.

### Strategy Pattern for Signature Variants
Configuration on the strategy: attached vs detached, visible vs invisible. Visible signatures carry placement metadata (page, x/y, dimensions, appearance template).

### Service Wiring
Symfony's tagged services + service locators. When a user uploads a file, the system asks the file type strategy: "what signatures do you support?" and shows exactly those options.

### Dependency Injection Everywhere
No static calls. No `new` in business logic. All collaborators injected. Makes the system testable and architecturally clean.

### Module Structure — Vertical Slice + Core + Queue and Hexagonal-ish Boundaries

The application is organized as self-contained feature modules. Each module owns its entities, services, repositories, controllers, and (where needed) async message classes. A `Core` module provides shared plumbing (UUID traits, value objects, base exceptions) that all other modules may depend on.

```
src/
├── Core/         # Traits, value objects, base exceptions — zero business logic
├── Auth/         # User entity, registration, login, 2FA, password reset
├── Certificate/  # CA, per-user X.509 certs, PIN model, key encryption
├── Document/     # Upload, envelope encryption, versioning, storage abstraction
├── Signing/      # PAdES, pyHanko adapter, signing requests, Symfony Workflow
│   └── Message/  # SignDocumentMessage → async queue (Symfony Messenger)
├── Mailer/       # Email templates + async dispatch via Messenger
├── Receipt/      # Delivery receipt PDF generation → async queue
└── AuditLog/     # Hash-chained append-only log — depends on nothing, called by all
```

**Dependency rule:** modules depend on `Core` and on other modules only where the domain genuinely requires it (e.g., `Signing` depends on `Certificate` and `Document`). Circular dependencies are forbidden.

**Queue rule:** only three types go async — signing, email, and receipt generation. Everything else (PIN check, upload, profile update) is synchronous. This keeps async complexity isolated to the modules that truly need it.

This structure enables swapping infrastructure without touching business logic (e.g., replace `PyHankoAdapter` with a SetaPDF adapter, or replace local filesystem storage with S3) — both arguments are discussed in the thesis as Architecture Decision Records. The boundaries are enforced with interfaces at the seams (signer, storage, encryption), not with a strict four-layer split — entities use Doctrine attributes and modules use Symfony directly (ADR-001).

### Architecture Decision Records (ADRs)
Maintain a `docs/adr/` directory with short markdown ADRs for each major decision. Accepted so far (`docs/adr/`):
- ADR-001: Architecture — vertical slice + Core + queue
- ADR-002: No Organisation entity
- ADR-003: TOTP 2FA via scheb/2fa-bundle + Google Authenticator
- ADR-004: Three-layer envelope encryption for document storage
- ADR-005: Private-key custody via SoftHSM2 + PKCS#11 *(supersedes the earlier "PIN-derived key encryption" idea)*
- ADR-006: Crypto agility (versioned envelope) + MVP algorithm suite
- ADR-007: Synchronous hash signing; two independent asyncs; sole-control threat model
- ADR-008: PIN verification & lockout — hash-first gate, token counter as backstop, desync tripwire

Still to write: pyHanko-via-Process for PAdES signing, self-signed CA scope & trust model, hash-chained audit log for tamper evidence.

These become thesis content directly.

---

## Domain Model (Initial)

Core entities (will evolve):

- **User** — authentication identity, profile fields needed for certificate (full name, email, optional EGN), 2FA settings
- **Certificate** — X.509 certificate per user, validity dates, fingerprint, status (active/revoked/expired), signing-algorithm default, PIN hash (Argon2id), failed PIN attempt counter. **No private key is stored** — the key lives in the user's PKCS#11 token (ADR-005); the entity references the token/key labels only.
- **CertificateAuthority** — root CA metadata and the CA's own certificate. The CA private key also lives in a PKCS#11 token, not in the DB.
- **UserEncryptionKey** — per-user KEK, stored wrapped by the application root key (ADR-004).
- **Document** — uploaded file metadata (filename, MIME type, SHA-384 hash, size, owner, upload time).
- **DocumentVersion** — each signing produces a new version; original is preserved. Each version has its own DEK.
- **DocumentKeyGrant** — one row per (user, document version) with access: the version's DEK wrapped by that user's KEK. Sharing inserts a grant; revoking deletes it (ADR-004).
- **SigningRequest** — routes a document from sender to recipient(s), tracks state via Symfony Workflow
- **SigningJobToken** — single-use authorization for an async signing job: random ID bound to user + document version, short expiry, consumed atomically exactly once by the worker. Guarantees only user-initiated jobs execute; no credential ever enters the queue (ADR-007).
- **Signature** — per-signature record (signer, certificate used, format, variant, timestamp, position if visible, signed at, signing request reference)
- **DeliveryReceipt** — generated PDF receipt with audit trail, itself signed by system certificate
- **AuditLogEntry** — append-only, hash-chained log entries for security-relevant events

---

## State Machines

### SigningRequest Workflow (Symfony Workflow component)

States: `draft → sent → viewed → signed | rejected → completed`

Transitions:
- `send` — draft to sent
- `view` — sent to viewed (recipient opened it)
- `sign` — viewed to signed
- `reject` — viewed to rejected
- `complete` — signed/rejected to completed (final state, receipt generated)

The Symfony Workflow component auto-generates state diagrams in DOT/Mermaid format — drop these directly into the thesis.

### Certificate Status

States: `pending → active → revoked | expired`

---

## Security Architecture

### Authentication
- Email + password (Argon2id hashed)
- Email verification on registration
- Password reset via signed token
- TOTP 2FA via `scheb/2fa-bundle` (mandatory for MVP)
- Session timeout: 30 minutes idle, 8 hours absolute
- Rate limiting on login (5 attempts / 15 min per IP, 10 attempts / hour per email)

### Certificate PIN Model & Key Custody (ADR-005)
- Each user gets **one PKCS#11 token** (SoftHSM2 in the MVP, accessed only via PKCS#11 so a hardware HSM swaps in by config later).
- The signing key (and the CA key) is **generated inside the token and never exported** — only the signature comes out. **No private key is ever written to disk**, not even to tmpfs.
- PIN set by user on certificate issuance (6–12 digits, validated for entropy). **The certificate PIN IS the token's User PIN.**
- PIN supplied at signing to open the PKCS#11 session, then discarded — **never** stored, queued, or logged. The DB keeps **only an Argon2id hash** of the PIN, for verification and lockout.
- The PIN is **never** used to derive an encryption key — a PIN-derived key can't be re-wrapped for a second user and would break sharing. *(This reverses the earlier PIN-derived-key sketch.)*
- Failed PIN attempts tracked; certificate auto-locked after 5 failures within 1 hour.
- **Dual lockout counters — decided (ADR-008):** hash-first gate — the PIN is verified against the Argon2id hash *first* and the PKCS#11 session is opened only on a match, so **the token never sees a wrong PIN**. The DB counter is the single source of truth ("N attempts remaining"); the HSM retry counter is a defense-in-depth backstop. Hash-match-but-token-rejects = desync tripwire → lock + high-severity audit. Unlock = password + fresh TOTP; forgotten PIN or hard-locked token = certificate re-issue. SO-PIN reset path explicitly rejected (server-held credential would undercut sole control).
- Locked certificates require account password + 2FA to unlock. Forgotten PIN = key unrecoverable = re-issue certificate (matches smart-card behaviour).
- **Sole-control caveat:** this prevents the server signing on its own, but not a server compromised at the moment of signing substituting a different hash — the certified fix (CEN EN 419 241-2 / CSC API) is future work.

### Document Storage Encryption (Three-Layer Envelope, ADR-004)

Defends against a **storage-layer breach** (DB dump + object-store leak), **not** against a compromised app server (server-side signing makes that impossible — stated in the threat model).

Key hierarchy (each key wraps the one below):
- **Root key** (one per app, 256-bit AES) — wraps every per-user KEK. Lives only in env/KMS (`SIGIL_ROOT_KEY`), **never** in the DB.
- **Per-user KEK** (one per user) — created at registration, stored wrapped by the root key (`UserEncryptionKey`). Enables per-user isolation and crypto-shredding (delete KEK ⇒ all that user's files unrecoverable ⇒ clean GDPR erasure).
- **Per-file-version DEK** (random 256-bit AES) — encrypts the bytes with AES-256-GCM (96-bit nonce, 128-bit tag). Stored wrapped by the relevant user's KEK in `DocumentKeyGrant`, **one row per user with access**.

Rules:
- Only **wrapped** keys are persisted; raw keys exist only transiently in memory. Object storage only ever holds ciphertext.
- The DEK is **not** a column on the file row — it lives in `DocumentKeyGrant`, which is what makes sharing possible.
- **Sharing re-wraps, never re-encrypts:** unwrap the DEK, re-wrap under the recipient's KEK, insert a grant — ciphertext untouched (instant even for large files). **Revoke** = delete the grant row.
- All crypto via `EncryptionServiceInterface`; envelope is versioned/self-describing (`algo_id ‖ nonce ‖ ciphertext ‖ tag`, `algo_id` in the GCM AAD) so old files stay decryptable across algorithm changes (ADR-006).
- Document SHA-384 hash stored separately for integrity verification.
- Storage abstracted behind `DocumentStorageInterface` for future S3/MinIO support.
- File limit 20 MB → buffer whole files in memory (chunked/streaming AEAD only if the limit rises substantially).

### Audit Logging
- Append-only `audit_log` table
- Each entry contains: event type, actor, target, timestamp, event-specific payload, hash of previous entry
- Tamper-evident: any modification breaks the hash chain
- Events logged: login success/failure, 2FA challenges, certificate issuance, PIN attempts, document upload/download, signing operations, routing actions, admin actions

### HTTP Security
- HTTPS only (HSTS with preload)
- Strict CSP with **zero external origins** — all assets self-hosted (AssetMapper JS, locally compiled Tailwind, fontsource fonts, Lucide icons); no CDN, no outgoing links. `script-src 'self'` + nonces for any unavoidable inline; inline `style=` attributes in templates to be migrated to utility classes so `style-src 'self'` can be enforced
- X-Frame-Options: DENY
- X-Content-Type-Options: nosniff
- Referrer-Policy: strict-origin-when-cross-origin
- CSRF protection on all forms (Symfony default)
- Secure session cookies (HttpOnly, Secure, SameSite=Lax)

### File Upload Validation
- MIME type check via content sniffing (not just extension)
- Magic byte verification for PDFs
- Max file size (e.g., 20 MB MVP)
- Filename sanitization
- Stored with random UUID, original filename only in metadata
- Antivirus scan via ClamAV (optional, document as future hardening)

### Threat Model
Conduct STRIDE analysis covering:
- **Spoofing:** auth, 2FA, certificate ownership
- **Tampering:** document integrity, audit log chain, signature verification
- **Repudiation:** signed audit trail, timestamps, signer certificate binding
- **Information disclosure:** envelope encryption, TLS, PIN protection
- **Denial of service:** rate limiting, file size limits, queue backpressure
- **Elevation of privilege:** Symfony voters, separation of admin/user roles

This becomes a dedicated thesis chapter.

---

## PAdES Signing Flow (Detailed)

### Sign Operation (User Initiates)

1. User selects document, signature format (PAdES), variant (attached/detached, visible/invisible)
2. If visible: user places signature box on PDF preview (page, x, y, width, height)
3. User enters certificate PIN
4. Backend verifies PIN against stored Argon2id hash (rate-limited)
5. **Key step (synchronous, in-request — ADR-007):** the PIN opens the user's PKCS#11 token session; pyHanko signs the document hash **inside the token** via deferred/interrupted signing (prepare → hash → sign in token → embed). The PIN is discarded immediately after; no private key ever leaves the token. **The PIN is never queued.**
   a. Backend loads the document and decrypts it (three-layer envelope decryption)
   b. Builds signing parameters (variant, position, appearance)
   c. pyHanko produces the PAdES-B signature via native PKCS#11
6. **Keyless remainder (Messenger worker):** request RFC 3161 timestamp from FreeTSA and embed it (PAdES-B-T) → re-encrypt the signed PDF and store as a new `DocumentVersion` (new DEK + grants) → write audit-log entries → publish status update.
   - **Single-use job token:** when the user confirms with the PIN (step 5), a one-time `SigningJobToken` (random ID bound to user + document version, short expiry, consumed atomically exactly once) is created; the queued message carries **only this token ID**. The worker validates and consumes it before doing anything — no job that wasn't user-initiated moments earlier can execute, and no credential ever enters the queue. This mirrors the Borica/Evrotrust initiate-then-process interface shape and feeds the sole-control chapter.
7. Frontend (subscribed to the Mercure topic) transitions the UI to "Signed".

The pending/notify *experience* for documents routed between users comes from the `SigningRequest` workflow (human-wait async), which is independent of whether the keyless remainder runs in a worker.

### Detached Signature Variant (deferred — abstraction only in MVP)
- The variant config on the signature strategy models attached/detached from day one, but only attached is implemented for MVP
- Future: pyHanko produces a separate `.p7s` (PKCS#7) detached signature file; both files made available for download

### Visible Signature Placement (automatic)
- No drag-drop. Placement service enumerates existing signature widget rectangles in the PDF (via pyHanko), then places the new signature at the first free slot on a grid starting **bottom-left, filling rightward** (wrapping upward if a row is full)
- Deterministic and collision-free — described as an algorithm in the thesis rather than UI code
- Invisible variant also supported; nothing else

### pyHanko Integration
- Run as subprocess via `Symfony\Component\Process\Process`
- Signs via **native PKCS#11**: pyHanko is given the module path + token/key labels and the PIN (passed in-memory, never written to disk). **No private-key PEM is ever materialised** (ADR-005).
- Input: temporary working directory with the PDF, the signer certificate (PEM — public material only), and a signing config JSON
- Output: signed PDF and/or `.p7s`
- Wrapper service: `App\Signing\Service\PyHankoAdapter implements PadesSignerInterface`
- Any temp files (PDF only, never keys) in tmpfs (`/dev/shm`) and securely deleted (overwrite + unlink)
- pyHanko called with explicit version pin in Docker image

### Verification
- App provides a verification endpoint that re-runs pyHanko in verify mode
- Shows: signature validity, signer certificate, certificate chain to Sigil CA, timestamp validity, signed-at time, any modifications since signing
- Acrobat will show "Signed and all signatures are valid" if document is intact, with the warning that "Signer's identity is unknown" — exactly the desired behavior for a self-signed CA

---

## Frontend Pages (MVP)

### Public
- Landing page (Sigil, tagline, brief explanation, login/register CTAs)
- Login (with 2FA flow)
- Register (with email verification)
- Password reset

### Authenticated
- **Dashboard** — recent documents, pending signing requests, certificate status card
- **Documents** — table of all owned documents with filters (status, date range, search)
- **Document detail** — PDF preview (left), metadata + signature list + audit trail (right), action buttons (sign, send, download, verify)
- **Upload** — drag-drop zone, metadata form
- **Sign flow** — multi-step with stepper component:
  1. Choose signature format (PAdES — only option in MVP)
  2. Choose variant (attached only in MVP; visible/invisible)
  3. If visible: automatic placement (bottom-left, filling rightward, avoiding existing signatures) — preview shown, no drag-drop
  4. Review + enter PIN
  5. Confirmation with progress
- **Inbox** — incoming signing requests from other users
- **Sent** — outgoing signing requests, status tracking
- **Certificate** — credential card showing user's certificate (subject, issuer, validity, fingerprint, status), button to renew, change PIN
- **Profile** — account settings, password change, 2FA management
- **Audit log (user view)** — read-only timeline of user's own audit events

### Admin (statistics & monitoring — built last, Phase 10/11)
- Privacy-preserving by design: aggregate statistics and process monitoring **without exposing personal data** (no document contents, no EGN, counts and states only)
- Dashboard: user counts, certificates issued/active/revoked, signing volume, signing-request funnel (sent → viewed → signed/rejected)
- Certificate list (issue/revoke)
- CA management (view CA cert, monitor issuance)
- System audit log

---

## Project Phases & Timeline

Working backwards from defense window of late September 2026, with documentation written in parallel.

> **Re-baseline (02.07.2026):** ~12–13 weeks remain to the defense window. Phases 1–2 are done (auth, 2FA, email verification, password reset, login throttling). Immediate order: **tests + PHPStan/CI first** (while the codebase is small), then remaining rate limiting (PIN endpoints), then Phase 3. Phase durations below are compressed accordingly; the pyHanko-over-PKCS#11 end-to-end spike is the first task of Phase 3 since SoftHSM2 and pyHanko are already in the Docker image. Multi-signer routing, async signing, and the admin statistics section are all **kept in scope**; the cuts are detached signatures (abstraction only) and drag-drop placement (automatic placement instead). Mercure is the only nice-to-have.

### Phase 0 — Decisions Locked (this week)
- Confirm September defense window
- Email Setasign for academic license (fallback in case pyHanko hits a blocker)
- Set up Git repo, project skeleton

### Phase 1 — Foundation (Weeks 1–2)
- Symfony 8.x project scaffold ✅
- Docker Compose: `app` container (PHP 8.4-cli-alpine) + `db` container (PostgreSQL 16-alpine) ✅
- Tailwind CSS 4 via `symfonycasts/tailwind-bundle` + Flowbite JS via AssetMapper ✅
- Brand color palette as CSS custom properties + `@theme` variables ✅
- Brevo transactional email configured (`symfony/brevo-mailer`, free 300 emails/day — add `MAILER_DSN=brevo+api://API_KEY@default` to `.env.local`)
- Sofia Sans variable font self-hosted via AssetMapper ✅ (replaced the Inter/fontsource idea; zero external font origins)
- Lucide icons set up
- PHPStan level 8, PHP CS Fixer, PHPUnit configured
- Base layout with sidebar, top bar, glass-styled sidebar background
- CI pipeline (GitHub Actions): tests, static analysis, code style on every push

### Phase 2 — User Management & Auth (Weeks 3–4)
- User entity, registration, login, email verification
- Password reset
- 2FA via TOTP
- Profile page
- Rate limiting on auth endpoints
- Session security configuration
- First ADRs written

### Phase 3 — Certificate Authority, Key Custody & PIN Model (Weeks 5–6)
- Stand up SoftHSM2 + PKCS#11 in the Docker image; app talks pure PKCS#11 (ADR-005)
- Generate root CA (one-off command); CA key generated **inside a token**, never exported
- Optional: intermediate CA for proper chain
- Certificate issuance service (key generated in the user's token; phpseclib/openssl used only for the public cert)
- PIN flow: setup, verification, lock-out, change — PIN = token User PIN; DB stores only Argon2id hash
- Three-layer envelope encryption (`EncryptionServiceInterface`, root key → KEK → DEK) + `UserEncryptionKey`/`DocumentKeyGrant` (ADR-004, ADR-006)
- Certificate management UI (credential card, fingerprint display)
- Unit + integration tests for crypto operations

### Phase 4 — Document Upload & Storage (Week 7)
- Document entity + DocumentVersion entity
- Upload form with validation (MIME, magic bytes, size)
- Envelope encryption implementation
- `DocumentStorageInterface` + filesystem implementation
- Documents list page with filters
- Document detail page with PDF preview (PDF.js)
- Tests for upload, encryption, retrieval, integrity verification

### Phase 5 — PAdES Signing Core (Weeks 8–10) — **highest risk, allocate buffer**
- pyHanko Docker image setup
- `PadesSignerInterface` + `PyHankoAdapter`
- Sign command + handler (sync first, then move to Messenger)
- Attached variant (detached kept behind the strategy abstraction, not implemented)
- Invisible signature working end-to-end
- Visible signature with automatic placement (bottom-left fill, existing-signature collision avoidance)
- Verification endpoint
- Test signed PDFs against Adobe Acrobat — confirm "valid signature, identity unknown" outcome
- Integration tests for full sign-verify cycle

### Phase 6 — Timestamping (Week 11)
- FreeTSA integration via pyHanko
- Upgrade signatures to PAdES-B-T
- Display timestamp validity in verification UI
- Handle TSA failures gracefully (retry, fallback TSA)
- Local RFC 3161 TSA container in compose as offline fallback (demo has zero internet dependencies)

### Phase 7 — Async Signing (Week 12)
- Keyless remainder moved to Symfony Messenger (Redis transport); key step stays sync in-request (ADR-007)
- `SigningJobToken` — single-use, user-initiated job authorization (see PAdES Signing Flow)
- Frontend status: short-poll on signing status is sufficient; Mercure real-time push only if time allows (nice-to-have, not required)
- Error states and retry handling (retry must re-check the job token's document-version binding, never re-sign)

### Phase 8 — Document Routing & Receipts (Weeks 13–14)
- SigningRequest entity + Symfony Workflow configuration
- Send-to-user UI (recipient lookup by email)
- Inbox + Sent pages
- Email notifications on routing events
- Delivery receipt PDF generation (template + signed by system cert)
- Multi-signer support (sequential workflow)

### Phase 9 — Audit Log & Hardening (Week 15)
- Hash-chained audit log implementation
- Audit log viewer (user + admin)
- CSP, HSTS, all security headers
- Final rate limiting tuning
- STRIDE threat model document (drafts thesis chapter)
- Penetration test against own app (basic OWASP checks)

### Phase 10 — Polish & Documentation Push (Weeks 16–18)
- UI polish, animations, empty states, loading skeletons
- End-to-end testing pass
- Performance check (signing throughput, page load times)
- Write remaining thesis chapters
- Generate ER diagram from Doctrine schema
- Generate workflow diagrams from Symfony Workflow
- Generate sequence diagrams (PlantUML) for key flows
- Select 10–15 pages of representative code for thesis appendix

### Phase 11 — Buffer & Defense Prep (Weeks 19–20)
- Bug fixing, edge cases
- Demo script for thesis defense
- Backup demo environment (in case live demo fails)
- Final thesis review, citation check (university-specific style)
- Practice defense

---

## Documentation Plan (50–60 pages A4 + 10–15 pages code)

Suggested chapter structure (adjust to TU Sofia requirements):

1. **Introduction** (3–4 pages) — context, motivation, eIDAS landscape, Bulgarian e-signature providers, problem statement, contributions, structure
2. **Theoretical Background** (8–10 pages) — cryptographic primitives, PKI, X.509, digital signatures, PKCS#7/CMS, PAdES standard, RFC 3161 timestamping, eIDAS regulation
3. **Related Work / Existing Solutions** (3–4 pages) — Evrotrust, Borica, DocuSign, Adobe Sign — comparison
4. **Requirements Analysis** (3–4 pages) — functional, non-functional, security requirements
5. **System Architecture** (8–10 pages) — high-level architecture, layered design, strategy patterns, component diagram, ER diagram, key sequence diagrams (sign flow, verify flow), state machines
6. **Security Architecture** (6–8 pages) — threat model (STRIDE), authentication, certificate PIN model, envelope encryption, audit log, defense-in-depth
7. **Implementation Highlights** (8–10 pages) — chosen technologies and rationale, CA setup, signing pipeline, async architecture, frontend interactivity model
8. **Testing & Validation** (3–4 pages) — test strategy, signature validation against Acrobat, threat model verification
9. **Conclusions & Future Work** (3–4 pages) — what was achieved, limitations, planned extensions (face/OCR, identification dossier, mobile, HSM, EU trust list)
10. **References** (2–3 pages) — academic + standards (RFCs, ETSI, eIDAS)
11. **Appendix A — Selected Code** (10–15 pages) — annotated key implementations
12. **Appendix B — ADRs** (optional, can also be inline)

### Documentation Discipline
- Write each chapter at the end of the relevant phase, while context is fresh
- Maintain a `docs/thesis/` directory with chapter-per-file markdown
- Convert to LaTeX or Word at the end (whichever TU Sofia accepts)
- Capture screenshots as you build features (don't try to recreate them later)

---

## Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| pyHanko integration fails or produces invalid PAdES | Medium | High | Allocate buffer in Phase 5; have SetaPDF-Signer as fallback (academic license request sent early) |
| FreeTSA unavailable during demo | Low | Medium | Implement TSA fallback to alternative public TSA; cache last good timestamp for demo |
| Acrobat doesn't recognize signature as valid | Medium | High | Test against Acrobat very early in Phase 5, not at the end |
| pyHanko PKCS#11 signing path harder than the PEM path (less-traveled code path) | Medium | High | End-to-end spike **first task of Phase 3**: token keygen → pyHanko PKCS#11 sign → Acrobat-valid PDF, before building Certificate entities around it |
| Schedule compression (~13 weeks remain vs 16 planned as of 02.07.2026) | High | High | Re-baselined plan; detached signatures and drag-drop already descoped; Mercure is the designated next cut; prioritize Phases 3–6 as critical path |
| SoftHSM token store lost (volume deleted) ⇒ all keys + CA unrecoverable by design | Low | High | Back up token directory together with the DB; document as deliberate HSM-ops trade-off in thesis |
| Documentation falls behind code | High | High | Write per-phase, not at end; capture screenshots and diagrams continuously |
| Scope creep into Phase 9 face/OCR features | High | High | Strictly defer to thesis "Future Work" — do not implement |
| Solo work, illness or disruption | Medium | High | Build buffer weeks (Phase 11); prioritize Phases 1–7 as critical path |
| Mercure adds complexity beyond payoff | Low | Low | If problematic, fall back to short-poll on signing status |

---

## Definition of Done (MVP)

The MVP is complete when:

- A new user can register, verify email, set up 2FA
- A user can be issued a Sigil-CA-signed X.509 certificate, set a PIN
- A user can upload a PDF, which is stored encrypted at rest
- A user can sign a PDF (PAdES-B-T), choosing attached/detached and visible/invisible
- The signed PDF opens in Adobe Acrobat showing valid signature with "Signer's identity is unknown" warning
- A signed timestamp is verifiably present and shown in the app's verification view
- A user can send a document to another registered user for signing
- The recipient can review, sign or reject; the sender is notified in real time
- A delivery receipt PDF is generated, itself signed by the system certificate
- An audit log records every security-relevant event in a tamper-evident chain
- The app passes PHPStan level 8, all tests pass, code style is clean
- The app runs cleanly via `docker compose up` from a fresh checkout
- Documentation reaches 50+ pages with all required chapters drafted

---

## Immediate Next Steps (This Week)

1. ✅ Create Symfony 8.x project scaffold with Docker Compose (2 containers: app + db)
2. ✅ Tailwind CSS 4 + Flowbite configured with Sigil brand color palette
3. Sign up for Brevo (free) → get API key → add `MAILER_DSN=brevo+api://YOUR_KEY@default` to `.env.local`; run `composer require symfony/brevo-mailer`
4. Email Setasign about academic licensing for SetaPDF-Signer (fallback insurance)
5. Build base layout shell — sidebar, top bar, glass-styled sidebar background
6. ✅ ADRs established in `docs/adr/` (ADR-001..007); key crypto/custody decisions now recorded (ADR-004..007)
7. Create empty interfaces: `SignableFile`, `SignatureFormatInterface`, `PadesSignerInterface`, `DocumentStorageInterface`, `EncryptionServiceInterface` — lock in the strategy pattern from day one
8. Set up CI pipeline (GitHub Actions) running PHPStan + tests on every push
9. Begin Chapter 1 (Introduction) and Chapter 2 (Theoretical Background) of the thesis in parallel — these don't depend on code

---

*Sigil — Signum Veritatis*
