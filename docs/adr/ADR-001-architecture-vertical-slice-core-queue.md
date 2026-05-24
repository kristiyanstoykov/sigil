# ADR-001: Architecture — Vertical Slice + Core module + Queue

**Date:** 2026-05-24  
**Status:** Accepted

## Context

Sigil needs a clear module structure that scales across ~8 domain areas (Auth, Certificate, Document, Signing, Mailer, Receipt, AuditLog, Dashboard) without creating tight coupling. Options considered:

1. **Flat Symfony default** (`src/Entity/`, `src/Controller/`, etc.) — doesn't scale, hard to navigate
2. **Hexagonal / Ports-and-Adapters** — heavy ceremony for a thesis project; three full layers per module
3. **CQRS everywhere** — interesting but overkill; adds indirection without payoff on simple reads
4. **Vertical Slice** — each module is a self-contained slice; modules own their Controller, Entity, Form, Repository, Service
5. **Vertical Slice + Core module + Queue only where async is genuinely needed** — chosen

## Decision

Vertical Slice with a named `Core` module as shared kernel. CQRS is used naturally only where Symfony Messenger is already involved (async messages = commands by another name). No forced CQRS on synchronous writes.

**`Core` contains:** traits (`HasUuid`, `HasTimestamps`), base exceptions, shared value objects. Zero business logic.

**Queue (Messenger async transport) is used only for:**
- `SignDocumentMessage` — signing is slow and involves subprocess
- `SendWelcomeEmailMessage` / `SendSigningRequestEmailMessage` — email is I/O, not on the critical path
- `GenerateReceiptMessage` — PDF generation, async post-signing

Everything else is synchronous direct calls.

## Consequences

- No `src/Entity/`, `src/Controller/`, `src/Repository/` at root — those empty scaffold dirs were deleted
- `doctrine.yaml` scans all of `src/` (`dir: '%kernel.project_dir%/src'`) instead of a single Entity dir
- Adding a new feature means adding a new module directory; it cannot reach into another module's Controller or Form
- `AuditLog` module is a dependency of everything else but depends on nothing — safe to call from any module
