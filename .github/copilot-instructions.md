# EverShelf repository rules

## Safety and ownership

- The only push/release target is `SFenton/EverShelf`. Verify remote identity:
  `origin` may point to `dadaloop82/EverShelf`; in that checkout the operator's
  fork is named `upstream`. Never push to the upstream author's repository.
- Preserve unrelated changes and secrets. Research does not authorize live
  data mutation, provider calls, activation, deployment, commits, or releases.
- Database candidates, audits, and shadow scores run on copied databases.
  Preserve immutable versions, frozen gold, provenance and rollback gates.
- Recipe ranking/filtering/deduplication/pagination belong in bounded SQL, not
  unbounded PHP hydration. Model proposals never directly activate ontology
  identity or bypass semantic review.
- Official Cookidoo instructions, preparation prose, raw provider payloads,
  image bytes, notes/tips, and unapproved fields must not be inspected, exposed,
  logged, persisted, or used as real-content test fixtures.

## Required domain contract

Before recipe, catalog, ontology, provider, database, or release work read
[the complete recipe contract](reference/recipe-contract.md). The detailed
identity, activation, field allowlist, cursor, memory, and frozen-gold rules
remain mandatory; this summary does not replace them.
