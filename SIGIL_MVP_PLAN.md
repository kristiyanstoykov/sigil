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
- Per-certificate PIN, used both for verification and as a key-derivation source for the encrypted private key
- PDF upload with envelope-encrypted at-rest storage
- PAdES-B-T signing (PAdES baseline + RFC 3161 timestamp) with attached/detached and visible/invisible variants
- Document routing between registered users with state machine
- Signed PDF delivery receipts with audit trail
- Tamper-evident audit log (hash-chained)
- Modern, minimalist UI in light theme with subtle glass accents

### Out of Scope for MVP (mention as Future Work in thesis)
- Face scan + Bulgarian ID OCR registration flow
- Identification dossier PDF generation (Borica/Evrotrust style)
- Mobile applications
- Dark theme
- Trusted CA inclusion (Adobe AATL, EU Trusted Lists)
- Hardware Security Module (HSM) integration
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
- **Sans-serif:** Inter (via `@fontsource/inter`, self-hosted for GDPR)
- **Monospace:** JetBrains Mono (for hashes, fingerprints, technical IDs)
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
| Async/queue | Symfony Messenger + Redis transport | For background signing jobs |
| Cache | Redis | Sessions, rate limiting, Messenger transport |
| CSS framework | Tailwind CSS 4 (via symfonycasts/tailwind-bundle) | Utility-first, fast iteration, no Node.js required |
| Component library | Flowbite (Tailwind components) | Pre-built modern components, imported via AssetMapper |
| Icons | Lucide | Clean line icons, consistent |
| Auth | Symfony Security Bundle + scheb/2fa-bundle | Standard, well-documented |
| Crypto / X.509 | phpseclib v3 | Pure PHP, mature, handles CA + cert generation |
| PAdES signing | pyHanko (Python) via Symfony Process | Best-in-class open-source PAdES library |
| Timestamping | FreeTSA (RFC 3161) | Free public TSA |
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

### Module Structure — Vertical Slice + Core + Queue

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

This structure enables swapping infrastructure without touching business logic (e.g., replace `PyHankoAdapter` with a SetaPDF adapter, or replace local filesystem storage with S3) — both arguments are discussed in the thesis as Architecture Decision Records.

### Architecture Decision Records (ADRs)
Maintain a `docs/adr/` directory with short markdown ADRs for each major decision:
- ADR-001: PIN-derived key encryption for private keys
- ADR-002: Envelope encryption for document storage
- ADR-003: pyHanko via Process for PAdES signing
- ADR-004: Async signing via Symfony Messenger
- ADR-005: Self-signed CA scope and trust model
- ADR-006: Hash-chained audit log for tamper evidence

These become thesis content directly.

---

## Domain Model (Initial)

Core entities (will evolve):

- **User** — authentication identity, profile fields needed for certificate (full name, email, optional EGN), 2FA settings
- **Certificate** — X.509 certificate per user, validity dates, fingerprint, status (active/revoked/expired), encrypted private key, PIN hash, failed PIN attempt counter
- **CertificateAuthority** — root CA metadata, the CA's own certificate and (encrypted) private key
- **Document** — uploaded file metadata (filename, MIME type, hash, size, owner, upload time, encrypted DEK reference)
- **DocumentVersion** — each signing produces a new version; original is preserved
- **SigningRequest** — routes a document from sender to recipient(s), tracks state via Symfony Workflow
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

### Certificate PIN Model
- PIN set by user on certificate issuance (6–12 digits, validated for entropy)
- PIN stored in DB as Argon2id hash (verification only)
- PIN also used as input to a separate KDF (Argon2id with different salt + larger memory cost) to derive the AES-256 key that encrypts the private key
- Private key never stored in plaintext, never written to disk unencrypted, never logged
- Failed PIN attempts tracked; certificate auto-locked after 5 failures within 1 hour
- Locked certificates require account password + 2FA to unlock

### Document Storage Encryption (Envelope Encryption)
- Per-document AES-256-GCM data encryption key (DEK), randomly generated
- Document encrypted with DEK; ciphertext written to filesystem
- DEK encrypted with server master key (KEK) loaded from environment
- Encrypted DEK stored in DB alongside document metadata
- Document SHA-256 hash stored separately for integrity verification
- Storage abstracted behind `DocumentStorageInterface` for future S3/MinIO support

### Audit Logging
- Append-only `audit_log` table
- Each entry contains: event type, actor, target, timestamp, event-specific payload, hash of previous entry
- Tamper-evident: any modification breaks the hash chain
- Events logged: login success/failure, 2FA challenges, certificate issuance, PIN attempts, document upload/download, signing operations, routing actions, admin actions

### HTTP Security
- HTTPS only (HSTS with preload)
- Strict CSP (no inline scripts; nonces for any unavoidable inline)
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
4. Backend verifies PIN against stored hash (rate-limited)
5. Backend derives KEK from PIN, decrypts private key in memory
6. Backend dispatches `SignDocumentMessage` to Messenger queue, returns immediately
7. Frontend shows "Signing in progress..." state, subscribes to Mercure topic
8. Messenger worker handles the message:
   a. Loads document, decrypts (envelope decryption)
   b. Loads user's certificate + decrypted private key
   c. Builds signing parameters (variant, position, appearance)
   d. Invokes pyHanko via Symfony Process with prepared inputs
   e. pyHanko produces PAdES-B signature, requests RFC 3161 timestamp from FreeTSA, embeds it (PAdES-B-T)
   f. Worker re-encrypts the signed PDF, stores as new DocumentVersion
   g. Worker writes audit log entries
   h. Worker publishes Mercure update to user's topic
9. Frontend receives Mercure event, transitions UI to "Signed" state with smooth animation

### Detached Signature Variant
- pyHanko produces a separate `.p7s` (PKCS#7) detached signature file
- Both files (original PDF + .p7s) made available for download

### pyHanko Integration
- Run as subprocess via `Symfony\Component\Process\Process`
- Input: temporary working directory with PDF, certificate (PEM), private key (PEM, decrypted in memory and written to tmpfs/memfd briefly), signing config JSON
- Output: signed PDF and/or .p7s
- Wrapper service: `App\Infrastructure\Signing\PyHankoAdapter implements PadesSignerInterface`
- Tmp files in tmpfs (`/dev/shm`) and securely deleted (overwrite + unlink)
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
  2. Choose variant (attached/detached, visible/invisible)
  3. If visible: place signature box on PDF
  4. Review + enter PIN
  5. Confirmation with progress
- **Inbox** — incoming signing requests from other users
- **Sent** — outgoing signing requests, status tracking
- **Certificate** — credential card showing user's certificate (subject, issuer, validity, fingerprint, status), button to renew, change PIN
- **Profile** — account settings, password change, 2FA management
- **Audit log (user view)** — read-only timeline of user's own audit events

### Admin (basic)
- User list
- Certificate list (issue/revoke)
- CA management (view CA cert, monitor issuance)
- System audit log

---

## Project Phases & Timeline

Working backwards from defense window of late September 2026, with documentation written in parallel.

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
- Inter + JetBrains Mono fonts (self-hosted via `fontsource`) — add in Phase 1 polish
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

### Phase 3 — Certificate Authority & PIN Model (Weeks 5–6)
- Generate root CA (one-off command, store encrypted CA private key)
- Optional: intermediate CA for proper chain
- Certificate issuance service (using phpseclib v3)
- PIN flow: setup, verification, lock-out, change
- Encrypted private key storage with PIN-derived KDF
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
- Attached + detached variants
- Invisible signature working end-to-end
- Visible signature with placement UI (Stimulus controller for drag-drop on PDF)
- Verification endpoint
- Test signed PDFs against Adobe Acrobat — confirm "valid signature, identity unknown" outcome
- Integration tests for full sign-verify cycle

### Phase 6 — Timestamping (Week 11)
- FreeTSA integration via pyHanko
- Upgrade signatures to PAdES-B-T
- Display timestamp validity in verification UI
- Handle TSA failures gracefully (retry, fallback TSA)

### Phase 7 — Async Signing & Mercure (Week 12)
- Move signing to Symfony Messenger
- Mercure publisher in worker
- Stimulus controller subscribing to Mercure topic, updating UI on signing completion
- Error states and retry handling

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
| Symfony learning curve slows progress | Medium | Medium | Allocate first 2 weeks specifically for ramp-up; use Symfony official tutorials |
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
6. Write ADR-001 (PIN-derived key encryption) as a template for further ADRs
7. Create empty interfaces: `SignableFile`, `SignatureFormatInterface`, `PadesSignerInterface`, `DocumentStorageInterface` — lock in the strategy pattern from day one
8. Set up CI pipeline (GitHub Actions) running PHPStan + tests on every push
9. Begin Chapter 1 (Introduction) and Chapter 2 (Theoretical Background) of the thesis in parallel — these don't depend on code

---

*Sigil — Signum Veritatis*
