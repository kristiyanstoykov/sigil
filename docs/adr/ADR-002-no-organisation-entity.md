# ADR-002: No Organisation entity — individual-centric trust model

**Date:** 2026-05-24  
**Status:** Accepted

## Context

Should users belong to an Organisation (company/country hierarchy), similar to how Evrotrust works with its Corporate portal for server tokens?

## Decision

No Organisation entity for MVP. Individual-centric trust model, similar to Borica's approach.

Users may optionally provide `company` and `position` fields on their profile. These fields exist **only** to populate the X.509 certificate Subject fields:
- `company` → `O=` (Organisation)
- `position` → `OU=` (Organisational Unit)

There is no org-level access control, no shared document pools per organisation, no admin hierarchy within a company.

## Consequences

- Simpler data model — User is the root entity, no join table to organisations
- X.509 certs carry company affiliation as metadata, not as a system-enforced access boundary
- Multi-tenant / corporate features deferred to Future Work
- If org hierarchy is added later, the `company` / `position` fields on User become the migration starting point
