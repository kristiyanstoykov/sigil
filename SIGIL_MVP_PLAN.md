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
- ADR-009: Pluggable object-storage backends (local / MinIO / AWS S3)
- ADR-010: HSM-resident root wrapping key + gated unwrap
- ADR-011: Pluggable signer backend (CQES)
- ADR-012: Delivery receipts + the application seal — QERDS-modelled (eIDAS Art. 43-44, ETSI EN 319 522)

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
- **TODO — honest wording on the visible stamp.** The signature stamp says
  "Qualified electronic signature" and "Qualified time-stamped", and both claims
  are false in every current configuration: Sigil's CA is in no trusted list, and
  FreeTSA is not a qualified TSA (with the `none` provider there is no RFC 3161
  timestamp at all). The mechanism to fix it already exists — `appearance.line1`
  is threaded through `PadesSignRequest` → `bin/sign_pdf.py` → `bin/sigil_stamp.py`
  (`DEFAULT_LINE1`), which is how the delivery seal already says "Electronic seal
  (delivery receipt)" instead. Deliberately left as-is for now; decide the final
  wording before the defense, since a reviewer will read the stamp.

### The expiry sweep aborted partway (found 2026-08-18, ✅ fixed 2026-08-19)

`sigil:signing:sweep` walked the overdue requests and, once it had erased one
unsigned document, the *next* iteration's flush threw:

    A new entity was found through the relationship 'DocumentVersion#document'
    that was not configured to cascade persist operations for entity: Document@…

**Cause: bulk DQL `DELETE`s.** `DocumentEraser::erase()` and
`DocumentKeyGrantRepository::deleteForDocumentAndUser()` both emptied tables with
`DELETE FROM ... ` DQL, which never reaches the unit of work. The rows went, the
entities stayed in the identity map, and the next flush walked one, found it
pointing at something Doctrine no longer tracked, and treated it as a new entity.

**Impact.** The hourly cron aborted at the first erase-then-close ordering. Every
overdue request after that point stayed open with its signers still holding their
turn, and the command exited non-zero. Order-dependent, which is why a
two-request test passed for months.

**The fix: everything goes through the ORM.** No hand-written DQL and no
`detach()` anywhere in the path - `remove()` plus a flush, and Doctrine drops the
entities from the identity map itself.

- `DocumentEraser::erase()` loads the grants (`DocumentKeyGrantRepository::findForDocument()`)
  and removes grants, versions and the document, deepest first.
- `DocumentKeyGrantRepository::deleteForDocumentAndUser()` reads its rows with a
  query builder and removes them.
- `SweepSigningRequestsCommand` removes the `SigningRequest` **before** erasing
  the document. Its row would go anyway - the FK is `onDelete: CASCADE` - but a
  cascade the database performs is one Doctrine never sees, so the request and
  its signers would stay managed over deleted rows. `SigningRequest::$signers`
  gained `cascade: ['remove']` so the signer rows follow (`orphanRemoval` alone
  fires on collection removal, not on removing the owner).

**The regression test is the ordering, not the deletion.** Both requests in
`testSweepDeletesAnUnsignedDocumentAndKeepsASignedOne` had the same deadline, so
which one the sweep hit first was down to Postgres; the unsigned one is now a day
older, which pins the erase-then-close order. The test also asserts the exit code
and runs the command with `setCatchExceptions(false)`, so a repeat surfaces as the
ORM exception rather than as "1 is not 0".

**Not done: hoisting the flush out of the per-request path** so one bad row cannot
strand the rest of a run. It is entangled with **F-08** (`AuditLogger::log()`
flushes and commits the caller's whole unit of work, including the one inside
`erase()` itself), so it waits for F-08.

---

### Information architecture rework (agreed 2026-08-15)

Three surfaces, each answering exactly one question, instead of one document's
life scattered across five pages.

1. **`/signing-requests` → To sign / Sent / History.** ✅ Done. The request
   lifecycle from the user's side; the badge counts only turns that are actually
   theirs. Absorbs the "history of what I declined" ask: closing revokes the
   decliner's grant, so History is the only list that can still show it.
2. **`/documents` → the library.** ✅ Done. One table over
   `findVisibleTo()`, a **Role** column (Owner / Signer) saying why the row is
   visible, and `?role=` / `?status=` filter chips. Delivery adds one more role.
3. **Dashboard → the one place that mixes roles.** ✅ Done. Real queries
   throughout, on Able Pro's Default dashboard grid: four stat tiles, "needs your
   action" and "waiting on others", the certificate rail, an activity feed read
   off the audit log, and a 6-month chart from the same source. The old
   `awaiting_delivery` framing is gone - the tile is "Delivered to you", with
   nothing to do.
4. **Sidebar groups**: Work (Dashboard, Documents, Signing requests) / Evidence
   (Receipts, Audit log) / Account (Certificates).

Not doing: folding signing requests into Documents as a tab. An action queue
sorts by urgency and empties itself; a library sorts by name and only grows.

### Delivery — being served (✅ BUILT 2026-08-15)

**A delivery is not a signature request.** It is hand-to-hand registered mail:
the recipient is *served* a document. Nothing is asked of them, they cannot
refuse it, and the sender gets sealed proof it reached them. This is Borica's
"препоръчана поща" - registered mail with return receipt - and it is the eIDAS
Art. 43-44 QERDS instrument proper.

What exists today is only the *signature request*, which happens to deliver the
document as a side effect of granting each signer their turn. Delivery in its own
right has never been built, and the earlier reading of ADR-012 - that the
per-turn key grant is the whole of Sigil's delivery story - was too narrow.
It describes how a signature request delivers; it is not a delivery flow.

**Scope:**

- `Delivery` entity: sender, document, one or more recipients, `sentAt`,
  per-recipient `deliveredAt`. **Unordered** - a delivery has no turn, unlike the
  signing queue. All recipients are served at once.
- Sending re-wraps the DEK for every recipient (`DocumentSharer::grantVersion` -
  the same mechanism, no new crypto) and records the consignment moment. Since
  Sigil does not track retrieval (ADR-012, Borica not Evrotrust), consignment
  *is* delivery: `deliveredAt` is the grant timestamp and the receipt is sealed
  immediately.
- A sealed receipt per delivery, naming the document hash, the sender, every
  recipient and the moment each was served.
- Email notification to each recipient.
- No decline, no acceptance, no read receipt. **Delivery cannot be refused** -
  that is what makes it a delivery (ADR-012 §2).
- Not revocable. This is the opposite of the share that was just removed: a share
  was silent, revocable access with no artifact; a delivery is one-way, attested
  and permanent.

**What has to change to support it:**

- `DeliveryReceipt::$outcome` is typed to `SigningRequestStatus`, and
  `$signingRequestId` is a required column. Both assume the only thing worth
  sealing a receipt for is a signature request. The receipt needs a source
  type + source id (`signing_request` | `delivery`) and an outcome that covers
  `Delivered`.
- `templates/receipt/delivery_receipt.html.twig` renders a signer table; a
  delivery receipt has recipients, not signers.
- `SealReceiptOnRequestClosed` listens for `SigningRequestClosed`; deliveries
  need their own event and subscriber.

**Decisions taken while building:**

- **Independent of signature requests.** A delivery does not consume the
  one-request-per-document rule, and a document may be delivered any number of
  times. They answer different questions.
- **No deadline.** Registered mail has none, and there is nothing to wait for.
- **In the UI:** the Deliver panel and the "Delivered to" list live on the
  document page; a recipient finds the document in their library badged
  **Recipient**. There is no separate inbox and no count, because nothing is
  pending for someone who has already been served - which is what was wrong with
  the dashboard stub's `awaiting_delivery` framing.

### Delivery + upload flow — REWORK NEEDED (raised 2026-08-16)

The delivery flow works but its shape is wrong. Four changes:

**1. The entry point belongs at upload, not on the document page. ✅ BUILT
2026-08-18.** An upload lands on `/documents/{id}/sign`, and for the owner of a
document with no signature request that page now opens with **"What is it for?"** -
four purpose cards: *I sign it myself*, *Others sign and I sign too*, *Others
sign I don't*, *Deliver it*. Choosing one opens a centred modal; self-signing
keeps the certificate + PIN form in place (two clicks, no navigation), and the
other three lead to the signer/recipient composer.

Rules that hold it together, all pure CSS - no JavaScript in the chooser at all:

- Selection uses **`group-has-[#id:checked]`**, not `peer-checked`: a peer only
  reaches later siblings and the option labels are nested, so `peer-checked`
  silently does nothing here.
- A fifth **`pp-none` radio** is the default and is what Back and the close
  button select - a radio cannot be unchecked by clicking a label, only replaced.
- Every failed-PIN redirect carries **`?purpose=self`** so the modal reopens on
  the form the user was filling; a pure-CSS modal would otherwise close and leave
  only a flash behind.
- **"Others sign, and I sign too"** links with `?include_me=1`, which seeds the
  picker with the owner (`SignerEligibility` decides the badge). It is only a
  seed: the row is ordinary from there on - **movable**, removable - and
  `create()` re-checks eligibility server-side.
- The two signature options are disabled without a usable certificate, and both
  are disabled once the document has spent its one request, each with the reason
  stated inline.
- The PDF viewer is **collapsed behind a "Read the document" row under `lg`** and
  always open above it, so the four options are above the fold on a phone.
  Deliberately *not* `<details>`: a closed `<details>` hides its content with
  `content-visibility` on a shadow-DOM slot that author CSS cannot override, so
  `lg:block` would not reopen it on desktop. Tested, not assumed.
- The document header (title, `Original`, size, fingerprint) sits above both
  columns; the left column used to repeat the filename directly under it.

Still open on this point: the modal does not lock body scroll behind it (not
possible in CSS alone - needs the Stimulus controller), and the step-2 composer
is still its own page rather than a step inside the modal.

**2. A delivered document is no longer a Draft. ✅ BUILT 2026-08-18.** It reads
**Delivered** and that state is genuinely terminal, enforced in the domain rather
than only in the UI:

- `Document::$deliveredAt` carries the fact. It sits on the entity, not in the
  `Delivery` module, because `Signing` has to read it and the two modules may not
  depend on each other - which answers the "another seam" question this item
  raised. `Delivery` remains its only writer.
- `DeliveryService::deliver()` refuses a second delivery, `SigningRequestService::create()`
  refuses a request, and `DocumentSigner::assertMaySign()` refuses a signature.
  The controllers stop earlier so nobody reaches a form that could only fail.
- `DocumentStatusResolver` returns `Delivered` ahead of everything else, and the
  document page replaces the "What next" row with a note saying the file is
  finished and to upload it again to serve it on anyone else.

**This reverses ADR-012 §2's "repeatable and independent".** A delivery seals a
receipt naming a fixed audience over a finished document; serving the same file
again, or signing it afterwards, would contradict that receipt. The converse does
*not* hold - a signature request never blocks a delivery, it only blocks another
request, and self-signing then delivering is the ordinary path.

Needs: a `DocumentDisplayStatus::Delivered` case, and the resolver has to consult
the `Delivery` module as well as `Signing`. Note the dependency direction -
`DocumentStatusResolver` lives in `Signing`, so either it moves or the delivery
half arrives through another seam.

**3. On the recipient's side a delivery is the first thing in "Needs your
action"** - above every signature request - and the action is to **view** it.

**4. Open question this raises, settle before building.** ADR-012 §2 says Sigil
records consignment and deliberately not retrieval ("do not add read receipts
without revisiting this decision"), and the dashboard tile currently says
*Nothing to do*. "You need to view it" is close to ETSI category E handover.
Decide which of these it is:

- a **UI nudge only** - the row disappears once the recipient opens the document,
  nothing is recorded, ADR-012 is untouched; or
- a **recorded acknowledgement** - the moment of viewing enters the audit log and
  the receipt, which is a real change to ADR-012 and to what a Sigil receipt
  attests.

The first keeps the Borica model. The second is closer to Evrotrust, which was
explicitly rejected once already - so it needs a deliberate reversal, not a
side effect of a UI change.

### Emails — proper HTML templates (TODO, raised 2026-08-16)

**Research answer first: Brevo templates are NOT required.** The transactional
send endpoint takes raw `htmlContent` from the caller, and Symfony's
`brevo+api://` transport already posts our rendered Twig that way. Templates stay
in the repo, versioned with the code, and no marketing-tool round trip is needed.

**One real constraint: Brevo's transactional API does not support inline (CID)
images.** Attachments work (URL or base64), but an `<img src="cid:...">` in the
body does not render. So the footer logo has to be either:

- a **hosted PNG** served by the app at a stable absolute URL and linked with
  `<img src>` - note every Sigil logo is SVG today and most mail clients will not
  render SVG, so this needs a PNG export; or
- a **styled text wordmark**, no image at all - immune to blocked-image settings
  and to the tracking-pixel look, but not the actual mark; or
- switching the DSN to Brevo's **SMTP relay** instead of the API, where CID may
  work. Unverified, and it changes `MAILER_DSN` - only worth it if the embedded
  mark matters more than the simpler transport.

**What the emails need:**

- A **centred container with a max width** (~600px, the transactional norm), not
  a body that stretches edge to edge. Table-based, since flex/grid are unreliable
  in Outlook.
- A **footer carrying the Sigil logo** on every email, plus the "why you are
  getting this" line that already exists.
- A real header/brand band rather than a bare `<h1>`.

**Current state:** `templates/emails/base.html.twig` is a bare unstyled shell -
no container, no width cap, no logo, no preheader text. Eight templates extend
it. Also missing and worth folding into the same pass: a **plain-text
alternative** (`TemplatedEmail::textTemplate()`), which mail clients and spam
filters both expect.

Sources: [Brevo send-transac-email reference](https://developers.brevo.com/reference/sendtransacemail),
[Anymail's Brevo notes on inline images](https://anymail.dev/en/v10.2/esps/brevo/),
[Brevo community thread on embedded images](https://community.brevo.com/t/does-transactional-email-support-embedded-image/6665).

### Live notifications (TODO, raised 2026-08-16)

Something happens to a document and the person it concerns should be told **as it
happens**, not on their next page load: someone sent a document for signature or
delivery, or someone signed one you requested.

**Decisions already taken** (Kristiyan, 2026-08-16):

- **Transport: a Mercure hub.** Real SSE, and it is what the stack table and
  ADR-007 already commit to. Cost: a new service in `compose.yaml`, JWT config
  and one more thing that has to be running for the demo.
- **Notifications are stored, with a bell list.** A `Notification` entity plus an
  unread count and dropdown. Push alone loses every notification that arrives
  while the recipient is offline - which is exactly the one that matters.

**Current state:** nothing exists. The header bell is a dead stub
(`title="Notifications - coming soon"` in `templates/layout/app.html.twig`), and
**Mercure is not installed** - no bundle, no hub service - despite being named in
the stack table and ADR-007.

**Shape:**

- `src/Notification/`: `Notification` entity (recipient, type, title, body, url,
  `readAt`), a type enum owning its icon and tone, repository
  (`findRecentFor`, `countUnreadFor`, `markAllReadFor`), and a `Notifier` that
  **stores first and pushes second** - a hub outage must never fail signing or
  delivering, the same rule `Mailer::trySend` follows.
- Link the document by plain UUID and resolve the URL at creation, like
  `DeliveryReceipt` does, so "your turn to sign X" survives X being erased.
- Private per-user topic `/users/{uuid}/notifications`, subscriber JWT minted by
  the app so a subscriber can only ever get its own stream.
- Bell dropdown with an unread badge, plus a toast on arrival.

**The events do not exist yet, and this is the forcing function for the deferred
event-model work.** `SigningRequestClosed` and `DocumentDelivered` exist;
"request sent", "your turn" and "someone signed your document" do not - those are
inline `SigningRequestNotifier` calls today. Rather than bolt a second inline
call next to each, dispatch domain events and let both Mailer and Notification
subscribe. That is the proposal recorded earlier ("we could trigger events for
signing and so on, so the audit log can hook on it"); this feature is the reason
to finally do it.

### Audit chain - tamper-evidence against a database-level attacker (TODO, decided 2026-08-16)

Raised by the code review in `docs/review/REVIEW-2026-08-16.md` (F-01), which is
the fullest write-up. **Decided: fix the code, not the claim.** The chain must be
tamper-evident against an attacker holding the database, since this is a
cybersecurity diploma project and that is the property the ADRs already imply.

**The problem.** `AuditLogEntry::__construct` computes
`entryHash = sha256(previousHash || canonicalPayload)`, and `sigil:audit:verify`
recomputes the same public function. Every input is a column of the same row, so
anyone with DB write access can delete an entry, renumber, rehash every following
row, and the verifier reports "Audit chain intact". Today the chain detects
accidental corruption and a naive single-row `UPDATE`. It does not detect a
deliberate rewrite, which is the only thing "tamper-evident" is used to mean.

**Layer 1 - key the chain.**
`entryHash = HMAC-SHA256(K_audit, previousHash || canonicalPayload)`, where
`K_audit` is generated inside the PKCS#11 token with `SENSITIVE=true,
EXTRACTABLE=false`, exactly like the ADR-010 root key. A DB dump, a SQL
injection or a DBA then has every input and still cannot forge an entry.

Do **not** spawn the Python driver per audit entry - that is a ~100ms process
start on a path that runs several times per signature. ADR-010 already blesses
the right pattern: *"KEKs can be unwrapped once per session and held transiently,
so the cost is per-session, not per-file."* Unwrap `K_audit` once per process and
hold it in memory. That weakens it only against an attacker who can read process
memory, which is host compromise, which ADR-010 already scopes out and which
Layer 2 covers. Against the database-level attacker this is aimed at, an
in-memory key is exactly as strong as a token round-trip per call.

**Layer 2 - anchor the head externally.** This is the honest completion, and the
part worth defending in front of a committee. Under our own threat model a
host-level attacker gets `K_audit` too and can rewrite and re-MAC the whole
chain; no internal secret can fix that, by construction. An external anchor can:
periodically (every N entries, or hourly from the existing sweep cron) take the
current head `entryHash` and obtain an **RFC 3161 timestamp** over it, storing the
token. The TSA's signature is the TSA's - a fully compromised Sigil cannot forge
it - so any rewrite is bounded to the window since the last anchor, provably, to
a third party. `TsaProviderInterface`, `FreeTsaProvider` and the local dev
responder already exist and are wired; this is a new caller, not new
infrastructure.

**Migration.** Existing entries carry unkeyed hashes. Do not recompute them -
that is precisely the operation the design exists to make impossible. Add a
scheme/version discriminator to the entry, have `sigil:audit:verify` check
pre-cutover entries with SHA-256 and post-cutover ones with the HMAC, and record
the cutover sequence. Anchor the head immediately after cutover. Same
self-describing-artifact discipline as the envelope's `algo_id` and the root
key's scheme byte.

**What can then be claimed:** "the chain is unforgeable by a database-level
adversary, and any rewrite by a host-level adversary is bounded to the window
since the last third-party anchor and is detectable by comparing the anchored
head." The current sentence does not survive cross-examination; that one does.

### Hash agility - put the chain digest behind a registry (TODO, raised 2026-08-16)

**Do this in the same pass as the audit-chain section above**, not separately.
That work replaces `sha256(...)` with an HMAC and therefore has to add a
per-entry algorithm discriminator anyway; adding the seam at the same time is
nearly free, and doing it afterwards means migrating the chain twice.

**First, correct the premise, because it matters for the thesis.** SHA-256 is
**not** quantum-threatened in the way ECDSA and RSA are, and ADR-006 already says
so ("Audit-log hash chain: SHA-256 acceptable for the chain"; "Only the
*signature* side has a quantum gap; storage is already quantum-resistant").

- Shor's algorithm breaks RSA and ECDSA outright - that is a **catastrophic**,
  structural break, and it is why ADR-006 records PQC signatures as the intended
  direction.
- Hash functions face only **Grover**, a quadratic speedup on preimage search:
  SHA-256 preimage goes from 2^256 to 2^128 work. 2^128 is still infeasible.
- For collisions, the classical birthday bound is already 2^128 for SHA-256.
  Quantum collision search (BHT) is theoretically ~2^85 but needs on the order of
  2^85 quantum memory, which is why NIST does not treat it as a practical threat.
- NIST's practical guidance is that a hash needs roughly **doubled output length**
  for the same margin under Grover. SHA-256 clears the bar; SHA-384 (already the
  document/signature digest here) gives comfortable headroom.

So do **not** write "we made the hash swappable because SHA-256 is quantum-broken"
in the thesis. It contradicts our own ADR-006 and hands an examiner a free
correction. The honest sentence is: *the hash is not the quantum-exposed part;
it is behind a seam for the same crypto-agility reason as the cipher registry,
and moving to SHA-384/SHA-512 for extra Grover margin is then a config change
rather than a migration.*

**The real reasons to do it, which are good enough on their own:**

1. **Consistency with ADR-006.** The whole architecture is "one seam, versioned
   self-describing artifacts": `CipherAlgorithmInterface` + `CipherAlgorithmRegistry`
   keyed by a stable algo id, `algo_id` stamped into every envelope,
   `SignatureAlgorithmRegistry` for suites, backend ids stamped into storage keys.
   The audit chain digest is the one primitive still hardcoded outside all of it.
2. **The construction is currently written twice** and must be kept in step by
   hand - `AuditLogEntry.php:87` and `AuditVerifyCommand.php:42` each spell out
   `hash('sha256', $previousHash . $canonicalPayload)`. A registry removes the
   duplication that would otherwise let the writer and the verifier drift apart,
   which is the worst possible bug in a verification tool.
3. It is a prerequisite for the audit-chain fix regardless.

**Design constraints, learned from the envelope:**

- **Bind the algorithm id into what is hashed**, exactly as `EnvelopeEncryptionService::bindAad`
  binds `algo_id` into the GCM AAD. Otherwise an attacker who can write the
  algorithm column claims an entry was made with a weaker algorithm and the
  verifier obliges - the same downgrade attack the envelope already defends
  against, and `EnvelopeEncryptionServiceTest::testAlgoIdDowngradeIsRejected` is
  the test to copy.
- **Store the algorithm id per entry**, not as a global setting. Old entries must
  stay verifiable under the algorithm that produced them; rehashing history is
  precisely the operation the chain exists to prevent.
- Keep the id format the codebase already uses (`AES-256-GCM/v1` style), so the
  chain reads like the rest of the system.

**Other hardcoded digests worth folding into the same seam** (found while
scoping this):

- `EnvelopeEncryptionService::deriveKey` - `hash_hkdf('sha256', ...)`, line 82.
- `EnvelopeEncryptionService::mac` - `hash_hmac('sha384', ...)`, line 87. The
  interface docblock hardcodes the claim too (`EncryptionServiceInterface.php:52`).

These two are lower priority than the chain: they are internal, their outputs are
not stored with an algorithm marker, and changing them is a breaking migration for
`ContentHasher` output. Worth listing in the ADR as known-fixed rather than
pretending they are agile.

**Also spotted:** `src/Certificate/Algorithm/Rsa3072Sha384.php` still exists and is
still autoconfigured into `SignatureAlgorithmRegistry`, although ADR-006 records
RSA as **dropped on 2026-07-25** ("it is removed"). Nothing selects it - the
default is fixed to `EcdsaP384Sha384` and there is no user choice - so it is
harmless, but it is live dead code contradicting an ADR. Delete it with this pass.

### KDF and MAC agility - same seam for deriveKey and mac (TODO, decided 2026-08-16)

Agreed: make the two remaining hardcoded digests dynamic like everything else,
migrating so existing `ContentHasher` output is not invalidated.

**Current state.** Two hardcoded choices behind one service:

```php
// src/Core/Crypto/EnvelopeEncryptionService.php:82
return hash_hkdf('sha256', $inputKeyMaterial, 32, $context);   // deriveKey
// src/Core/Crypto/EnvelopeEncryptionService.php:87
return hash_hmac('sha384', $message, $key, true);              // mac
```

`ContentHasher::hash()` composes both: derive a MAC key from the root key under
the context `sigil:document-content-hash/v1`, then HMAC the plaintext with it.
`EncryptionServiceInterface.php:52` hardcodes the claim in its docblock too.

**Important correction on "default sha384 so nothing breaks".** That is right for
`mac`, which is *already* HMAC-SHA-384, and wrong for `deriveKey`, which is
HKDF-**SHA-256**. Changing `deriveKey` to SHA-384 changes the derived MAC key,
which changes every `ContentHasher` output. So to preserve existing values the v1
suite must pin **today's** pair exactly:

| Function | v1 default (must not change) | Why |
|---|---|---|
| `deriveKey` | HKDF-**SHA-256**, 32-byte output | changing it changes the MAC key, hence every stored hash |
| `mac` | HMAC-**SHA-384**, 48 raw bytes | already the current value; 96 hex chars matches the column |

Unifying both on SHA-384 is a perfectly reasonable *future* suite - it is just a
v2, not the v1 default, and it produces different output by design.

**Good news that lowers the cost a lot: nothing ever recomputes `contentHash`.**
Verified 2026-08-16 - it is written once by `DocumentVersionWriter` and
`ReceiptWriter`, and thereafter only ever *displayed* (`documents/show.html.twig`,
`signing/sign.html.twig`, the receipt) or copied into an audit payload. There is
no comparison path and no `sigil:document:verify` command. Integrity at rest is
actually enforced by the AES-GCM tag on decrypt, not by this field; the field is
an evidentiary record. So switching suites cannot make a check fail - old rows
simply keep values computed under the old suite.

**The one structural requirement: store the suite id next to every stored digest.**
Add it as a column on `document_version` and `delivery_receipt`, the same way
`algo_id` is stamped into every envelope and the backend id into every storage
key. Without it, changing the default silently orphans every existing hash, since
nothing records which suite produced it.

**Do not repeat the ADR-010 mistake here.** The root key's scheme byte is
self-describing and *nothing routes on it at runtime* (F-03), which is why a
partial migration bricks users. Add the column **and** the code that reads it in
the same change, not the marker first and the router later. Note
`ContentHasher::MAC_CONTEXT` already ends in `/v1`, so a versioning hook exists in
the context string; that alone is not enough, because the version has to be
recorded per row to be recoverable.

**Priority: lower than the audit chain.** These are internal and nothing depends
on their agility today. The reason to do them is consistency - after this, every
symmetric primitive in the system is behind a registry with a stable id, and the
sentence "all crypto goes through one seam" in ADR-006 is true without an
asterisk. Fold the `EncryptionServiceInterface` docblock fix in at the same time.

### DISCUSS - what should the receipt's document digest actually be? (raised 2026-08-16)

Not a decision yet. Review finding F-35; full write-up in
`docs/review/REVIEW-2026-08-16.md`. Two correct design goals are in direct
conflict and the code currently satisfies one while the receipt's label claims
the other.

**The situation.** `ContentHasher` is a **keyed** HMAC-SHA-384 under a
root-derived key, not a plain digest. That was a deliberate call in the 2026-07-11
security review and the reasoning is good: under ADR-004's threat model (DB dump
plus object-store leak) a plain plaintext hash is a **confirmation oracle** -
an attacker with a candidate file hashes it and learns whether Sigil holds it,
without decrypting anything. That is a real privacy harm on its own (confirming a
specific contract, or a specific person's ID document, is in the system).

But the receipt renders that value as:

```twig
<td class="k">Document digest</td><td class="hash">{{ documentHash }} <span class="muted">(SHA-384)</span></td>
```

A recipient who runs `sha384sum` on the file they were delivered gets a mismatch,
and cannot reach the correct value - the MAC key derives from the root key, which
lives in the PKCS#11 token. ADR-012 grounds the receipt in Evrotrust's stated
receipt content, *"a hash extracted from the document content"*, so the one field
that looks independently checkable is the one only Sigil can check. Also note the
four entity docblocks (`DocumentVersion.php:21,54`, `DeliveryReceipt.php:58,69`)
describe it as a plain SHA-384, which is simply wrong whatever we decide.

**Options.**

1. **Relabel only.** Call it "Document fingerprint (Sigil keyed digest)" and state
   on the receipt that Sigil verifies it, not the reader.
   *For:* zero risk, keeps the oracle defence fully intact, honest immediately.
   *Against:* the receipt loses the independently-verifiable-digest property that
   makes a QERDS receipt useful to a counterparty, which is a real reduction in
   what the artifact is worth as evidence.
2. **Plain SHA-384, rendered inside the sealed PDF only.** Compute it at seal
   time; keep the keyed value in the DB column.
   *For:* the holder can verify; the plaintext DB column keeps no oracle; the
   sealed PDF is encrypted at rest and only released to participants who already
   hold the document, so they learn nothing new.
   *Against:* two digests to compute, store and explain, and the receipt has to be
   clear about which is which.
3. **Plain SHA-384 everywhere, including the DB column.**
   *For:* simplest, fully verifiable, matches what a reader expects.
   *Against:* reintroduces exactly the confirmation oracle `ContentHasher` was
   built to remove, reversing an accepted security decision.

**Questions to settle before choosing.**

- Does the thesis need the receipt digest to be **third-party verifiable**? If the
  receipt is argued as a QERDS-style artifact under ADR-012, probably yes, and
  option 1 weakens that argument.
- Is the DB-dump confirmation oracle still a threat we care about, given the
  attacker in that scenario already holds the ciphertext? (Yes - the oracle
  reveals *which known file* it is without breaking the encryption, which is the
  point.)
- Does option 2's split - keyed for storage, plain inside the sealed artifact -
  need its own ADR, or is it an amendment to ADR-012 §1?

### DISCUSS - should the inline notifier and audit calls become events? (raised 2026-08-16)

Not a decision. Re-raise of the "event-driven audit log" idea already referenced
in the Live notifications section above; this entry is the place to actually
settle it.

**The observation.** Sigil has 2 domain events for roughly 12 state changes.
`SigningRequestClosed` and `DocumentDelivered` exist only because a module rule
forbade a direct dependency (Signing and Delivery must not know Receipt).
Everywhere else the producer calls its collaborators inline:

| State change | How it notifies / records today |
|---|---|
| Document uploaded | `DocumentNotifier` inline + audit inline |
| Version written (upload or signature) | audit inline in `DocumentVersionWriter` |
| Access granted / revoked | audit inline in `DocumentSharer` |
| Signature request created | notifier inline + audit inline |
| Turn advanced | notifier inline + audit inline |
| Document signed | audit inline + `notifySigned` inline |
| Certificate issued / revoked / held / unlocked | audit inline |
| PIN failed / locked / desync | audit inline in `PinGate` |
| Receipt sealed / seal failed | audit inline |
| Document erased | audit inline **before** the delete |
| Request closed | **event** |
| Delivery made | **event** |

**The case for converting.**

1. **Live notifications force the N x M problem.** That item already commits to
   in-app notifications for "request sent", "your turn", "someone signed". Each of
   those moments would then have two consumers (Mailer and Notification), and
   without an event every new consumer means editing every producer.
2. **The seam already worked twice**, and the module rule that motivated it is
   generic - a Notification module would be one more thing Signing, Document and
   Certificate must not depend on.
3. **Audit vocabulary is currently scattered** across about a dozen services, each
   picking its own action string, payload shape, `subjectType` and severity, with
   no single place to read the vocabulary off.
4. **It would make "happened" and "was audited" the same fact.** ADR-012 makes the
   audit log the evidence repository, but today a new state change can ship with
   its audit line forgotten and no test would catch it.

**The case against, or at least for doing less of it.**

1. **Audit ordering is deliberate in places and events blur it.**
   `DocumentEraser` audits *before* deleting, because afterwards there is no
   document to describe; `PinGate` audits inside the failure path before throwing.
   As subscribers these need the event to carry a **snapshot**, not an entity
   reference, or they will read state that is already gone.
2. **Failure policy differs by consumer and would have to be made explicit.** The
   two existing listeners deliberately swallow `\Throwable` because a receipt must
   never fail a close. Audit is the opposite - "every entry includes the chain
   hash, no exceptions" is a stated invariant, so an audit listener must never
   swallow. Two classes of listener with opposite policies is exactly the kind of
   distinction that gets got wrong silently.
3. **It would widen F-08 rather than fix it.** `AuditLogger::log()` currently
   flushes and commits the whole unit of work at whatever point the caller invokes
   it. Moving more work into listeners spreads that timing problem across more
   call sites. **Sequencing conclusion: fix F-08 first, then consider this** - not
   the other way round.
4. **Debuggability, which matters for a thesis.** `$this->auditLogger->log('document.uploaded', ...)`
   sitting in the method that uploads is greppable and obvious to a reader. A
   subscriber mapping an event to an audit action is one indirection away, and a
   committee reading the code benefits from the direct version.
5. **Cost.** Twelve state changes means roughly twelve event classes plus
   subscribers, each a place to get the payload wrong, a few weeks before a
   defense.

**A middle position worth considering.** Convert only where there is genuinely
more than one consumer, or where a module rule requires it:

- **Notifications: yes.** That is the real N x M case, and it is the one Live
  notifications actually needs.
- **Audit: probably not, or only at the moments that already have an event.**
  Audit has exactly one consumer, has ordering constraints (erase-before-delete),
  and has the opposite failure policy from the notification listeners.

**Questions to settle before choosing.**

- Is the trigger the Live-notifications work? If so, scope the event set to
  exactly the moments notifications need, rather than all twelve.
- Should audit move at all, given one consumer and the ordering constraints?
- What is the failure policy per listener class, and how is it enforced rather
  than remembered?
- Do events carry entities or snapshots? `DocumentEraser` forces snapshots for at
  least one case, so the answer is probably "snapshots, always", which makes the
  event classes bigger.
- Does F-08 get fixed first? (Recommended - see above.)
- If notifications move to events, listener **priorities** become load-bearing
  (sealing before "your receipt is ready"), which today nothing declares. See the
  F-38 note in Review follow-ups.

### Certificate revocation - CRL / OCSP (TODO, deprioritised 2026-08-16)

Raised by the review (F-06). **Deliberately not on the priority list** - recorded
here so the gap is a decision rather than an oversight, which is most of what the
finding actually asked for.

**What was not previously known:** a CA (Sigil, Borica, Evrotrust alike) is the
party that publishes revocation status, and a verifier learns it from a URL baked
into the certificate at issuance (the CRL Distribution Points extension) or from
an OCSP responder. `CertificateIssuer::revoke()` currently sets a DB status and
deletes the token, which no external verifier can see, so the certificate keeps
verifying as valid outside Sigil forever. The CA cert is already issued with
`crl_sign` (`bin/issue_cert.py:53`) and nothing consumes it.

**Where the current argument holds.** "The certificate was valid when signing, and
the timestamp plus the content hash prove the file was not modified" is correct
as far as it goes: the RFC 3161 token proves *when*, the SHA-384 on
`DocumentVersion` and the PAdES byte range prove *what*.

**Where it stops.** Neither proves the certificate was *not revoked* at that
moment - that is the one question only the issuer can answer, and Sigil currently
answers it to nobody. The sharper practical consequence: user certificates are
valid 365 days and Sigil produces PAdES-B-T. Reaching **B-LT** requires embedding
validation material (chain plus CRL or OCSP responses) in the PDF's Document
Security Store; **B-LTA** adds archival timestamps. Without revocation data B-LT
is unreachable, so roughly a year after signing a verifier sees an expired
signing certificate with no revocation information and cannot establish it was
valid at signing time. Every signature has about a one-year clean-verification
life. Worth stating in the thesis as a known bound rather than being asked.

**If it is ever picked up,** three tiers:

- **Floor (~2h):** add `subject_key_identifier` + `authority_key_identifier` to
  `issue_cert.py` (correct chain building, costs nothing), and write the CRL gap
  into an ADR as stated scope.
- **Middle (~1d):** a `/crl` route serving a CA-signed CRL built from
  `CertificateRepository`, plus a `crl_distribution_points` extension. All three
  ingredients exist unconnected - the CA key has `crl_sign`, the driver already
  builds and signs DER with it, and the repository already stores `revokedAt` /
  `revocationReason`.
- **Full (~2d):** embed the CRL at signing time (pyHanko supports it) to produce
  PAdES-B-LT, so signatures verify indefinitely.

Note `Certificate::hold()` has the same property: it is a Sigil-internal state
with no external expression, despite being modelled on the CRL `certificateHold`
reason code.

### Review follow-ups - smaller items (raised 2026-08-16)

From `docs/review/REVIEW-2026-08-16.md`. Decided but deferred; no code changes
made yet.

- **ADRs 004-012 and CLAUDE.md are not in git** (F-34). `.git/info/exclude:9`
  carries a blanket `docs/*`, so `git add` skips them silently and always has.
  Nine ADRs carrying the entire security argument exist as one copy on one disk
  with no history. Replace the blanket rule with a precise `.gitignore` entry for
  the vendored theme only (`/docs/design/able-pro-tailwind-v1.2.0/`), then
  `git add docs/adr/`. **Two minutes, and everything else here is advice about
  files that currently have no backup.** Note this plan file is tracked; the
  review document is not.
- **Unwrap rate limiting + audit** (F-07). ADR-010 promises per-user and global
  unwrap ceilings and an audit entry; neither exists. Decided: the PIN gate on
  download was never intended (holding access is enough to download), so ADR-010
  §3's "extend the same gate to download" sentence should be deleted. Build the
  limiter in `KeyManagementService::userKek` so every envelope path is covered,
  and add the missing audit entry - including in `ReceiptDownloader`, which today
  logs nothing at all (F-18).
- **Receipt audience** (F-17). `ReceiptGenerator::participants()` grants a receipt
  key to every listed signer, including ones the turn never reached. Decided: the
  audience is everyone the document actually reached - signers whose turn came,
  plus delivery recipients. `signerRows()` twelve lines above already computes
  exactly this as `deliveredAt`. Correct ADR-012 §4, which says "the requester
  plus every signer".
- **Fail loudly when the seal is missing** (F-05). A seal failure during the
  expiry sweep is swallowed, and the document is erased a few lines later, so the
  receipt can never be regenerated - the "safe to re-run later" comment is true
  for every path except the one the subscriber was made synchronous for. Check
  `ReceiptSealer::isReady()` at sweep start and refuse to run, and skip the erase
  when no receipt was produced.
- **Login leaks verification state** (F-31). `UserChecker::checkPreAuth` runs
  before the password is verified, so any wrong password against an unverified
  account redirects to `/verify/resend` with the address prefilled. That is an
  unauthenticated enumeration oracle, and it undoes the enumeration-safety work
  on the resend endpoint itself. Move the check to `checkPostAuth` and add the
  negative test - its absence is why this went unnoticed.
- **Sign double-submit** (F-09). No lock on the request; two concurrent POSTs
  both produce a real token signature and the second 500s on the version-number
  unique index after its ciphertext blob is already written. Pessimistic lock in
  `DocumentSigner::sign`.
- **PKCS#11 wrapper untested** (F-04). `Pkcs11RootKeyWrapper`, the scheme byte,
  the in-token GCM AAD binding and `sigil:root-key:migrate` have no test - CI
  aliases `RootKeyWrapperInterface` to the env wrapper. Add `sigil:root-key:init`
  to CI next to the two init commands already there.
- **Partial root-key migration bricks users** (F-03). Nothing routes by scheme
  byte at runtime; `Pkcs11RootKeyWrapper::unwrapKek` hard-rejects `0x01` blobs,
  so an interrupted migration leaves those users unable to decrypt anything. Add
  a dispatching wrapper for the migration window - which is what makes the
  self-describing scheme byte actually pay off.
- **Threat model.** The "Threat Model" section above plans a STRIDE chapter;
  what is missing is the consolidation. ADR-004 (storage breach), ADR-005/007
  (sign-moment window, sole control), ADR-010 (host compromise of SoftHSM) and
  ADR-012 (unattended seal) each state one honest concession, in four places.
  The chapter needs one explicit **out of scope, and why** list - that is the
  section most projects skip and the one that earns the most credit.
- **PIN lockout stays as designed.** Considered and rejected: replacing the
  5-per-hour soft lock with a permanent card-style hard lock. The current design
  is not weaker (the DB counter is authoritative, rate-limited, and unlock
  re-proves password **plus** a fresh TOTP - two factors, which a smart card
  cannot do), and a permanent lock turns one typo into a re-issue and a new
  keypair. Do document that the 6-8 digit rule is scoped to *user* PINs; the
  server-held CA/seal/root token PINs never pass through `assertValidPin` and
  should be required to be high-entropy.
- **Two closing paths, one implementation needed** (F-37). `recordSignature`
  hand-rolls the closing sequence for the completed case instead of calling
  `close()`. They are equivalent today, but nothing keeps them so - and both the
  F-05 fix and the F-01 audit-chain work modify exactly that sequence, so each
  would have to be applied twice or be silently missing from the most common
  terminal state. Fold the completed case into `close()` and branch the
  notification choice on status inside it.
- **Declare listener priorities before notifications land** (F-38 and the Live
  notifications item above). Today each domain event has exactly one listener, so
  ordering never matters. The moment a Notification subscriber joins
  `SigningRequestClosed` and `DocumentDelivered`, it does - sealing has to happen
  before any "your receipt is ready" message, and nothing currently expresses
  that. Separately, `CertificateEnrollmentSubscriber`'s docblock asserts that
  `TwoFactorEnrollmentSubscriber` "runs first" while both sit at priority 0; that
  is harmless only because their guards are mutually exclusive on
  `isGoogleAuthenticatorEnabled()`. Either declare the priorities or fix the
  comment to name the real mechanism.
- **Stale docs** (F-30). `docs/HANDOFF-signing-flow.md` and
  `docs/session-summary-july-07-2026.txt` are previous-session handoffs. Delete.

### MAYBE - make the theme interchangeable (raised 2026-08-17, lowest priority)

Last item on the queue on purpose. Surveyed, costed, **not** committed to: the
presentation layer is the one part of Sigil the committee does not grade, and
this competes with 38 review findings and two undecided architecture questions.
Recorded so the coupling is a known quantity rather than a surprise.

**Where Able Pro actually reaches.** Less deep than it looks, except in one place:

| Layer | Coupling | Portable? |
|---|---|---|
| Brand tokens (`@theme static` + `:root` in `app.css`) | colors, fonts, radius, type scale | yes, already theme-agnostic |
| PHP (`src/`) | two enums return `text-success-600` / badge washes (token-level); `SidebarMenuProvider` carries its own inline SVGs | yes, except `iconClass()` returning Tabler names |
| Tests | selectors are `href` / `action` / form-name only, **zero** theme-class assertions | yes - a swap cannot break the suite |
| `app.css`, ~150 of 533 lines | `.pc-header`, `.pc-sidebar`, `.btn*`, `.alert-*` patches | no |
| Shell, ~240 lines (`base`, `layout/app`, `components/sidebar`, `auth/base_auth`) | `pc-header` / `pc-sidebar` / `pc-container` structure | no |
| **26 page templates, 3419 lines** | `card` x79, `btn-*` x84, `alert*` x46, `modal` x24, `badge` x20, `form-control`/`form-label` x36, `ti ti-*` x122 (42 distinct icons) | no - **this is the whole bill** |
| `assets/behaviors/able_pro.js`, 260 lines | implements the `data-pc-toggle` contract | no, but self-contained |

Two things the survey turned up that are worth knowing regardless of whether this
gets built:

- **`assets/able-pro/js/` holds apexcharts, simple-datatables and dragula.** Those
  are generic libraries misfiled under the theme directory, which makes the
  boundary look worse than it is.
- **There is no visual regression net.** `PageSnapshotTest` renders every page and
  asserts 200, so a botched re-theme still passes CI silently. Anything past
  Level 1 below needs a screenshot-diff harness built *first*.

**Level 1 - make the boundary real (~half a day).** Move the three libraries to
`assets/vendor/`; split `app.css` into `brand.css` (portable tokens) and
`able-pro.css` (component patches); add an `icon('clock')` Twig function mapping
semantic names to `ti ti-*`, and change `DocumentDisplayStatus::iconClass()` and
`CertificateDisplayStatus` to return semantic names. **No page template changes.**
This is the only level worth doing on its own: it buys the architecture-chapter
line (the same strategy-seam principle as `PadesSignerInterface` /
`DocumentStorageInterface` / `EncryptionServiceInterface`, applied to
presentation) for about a tenth of the cost.

**Level 2 - a component vocabulary (~3-5 focused days).** Roughly 12-15 Twig
components (card, btn, badge, alert, field, modal, table, page-header, stat,
empty-state) so no page template ever names an Able Pro class. Building the
components is about a day; rewriting 3419 lines of page templates through them is
the rest, page by page, with visual regression on every one. Afterwards a theme
swap costs ~600 lines (components + shell) instead of touching 26 files. **Only
justified if a second theme is actually going to ship.**

**Level 3 - theme as a swappable bundle** (Twig namespace override, asset
manifest, theme provider). Rejected. Sigil owns exactly one theme and it was
bought; this is architecture for a requirement that does not exist.

**Open question if this is ever picked up:** is the goal "I might want a different
look before the defense" (Level 2, screenshot harness first) or "I do not want
vendor lock-in in the architecture story" (Level 1 is the whole answer)?

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
