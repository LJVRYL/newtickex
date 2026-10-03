# Shared checkout links — selective release

Public `str-` references are source aliases, not proof of ownership. A server
session and opaque form token own a new 128-bit payment reference. Reload/back
and identical submissions keep that reference; independent browser sessions
get distinct purchases even with identical buyer data. `comprar.php` is the
explicit entry for another purchase. A form from a lost session never pays
silently: entered data is rendered with an actionable verification message.

Payload freezes on the first validated pay request (provider, event, server
amount, normalized email, name/DNI, ticket lines, affiliate). A changed payload
after that boundary cannot become a second charge. Ready retries do not rewrite
the order. PRG `rid` continuation requires the same session/account owner.
Mobile random capabilities retain their exact `mobile-` reference and existing
server-side event/email/expiry/already-started guards and return contract.

Before provider I/O, the existing claim transitions atomically from `creating`
to `dispatching`. That state cannot be reacquired by stale/failed retry logic.
Ready means provider response and auditable local order were persisted. An
ambiguous transport/persistence result remains `dispatching` and requires
verification instead of another dispatch. No claims, orders or holds are
deleted, no dedupe disabled, and no schema migration is required.

## Evidence and tests

Both reported IDs were verified against live logs/DB without exporting PII:
their public refs already had ready claims/pending orders. The first was from
October 1, the second from minutes earlier. Fingerprint conflict occurs before
gateway. Historical claim guard predates the recent mobile guard; the mobile
guard only handles `mobile-`, so it did not cause these `str-` failures. Exact
changed POST fields cannot be reconstructed from those logs.

`python3 str/tests/shared_checkout/run.py` runs real checkout forms, PHP session
locks, two PHP workers and synthetic SQLite. Provider and mail boundaries are
intercepted; no production orders/reserves/payments are used. Tests include
new guest/logged buyer, used shared links, identical independent data,
editing email/type/quantity, server price tampering, initial/consecutive
concurrent double click, old order/claim/hold preservation, foreign form/RID,
lost session, transport and persistence failure, confirmed-order retry,
mobile and both providers. Native server PHP 5.6 matrix also passed, as did
PHP 5.6 claims/mobile/holds and actual production issuance/confirmation tests
(test read reprepared for SQLite 3.7 after schema bootstrap). Syntax passed
PHP 5.6 and 7.4. OAuth/encryption and reserve reconciliation regressions passed
7.4: existing MP token encryption uses native `random_bytes`, absent in 5.6;
the synthetic tokens were prepared in 7.4, not through a production account.

## Deploy

`manifest.json` contains exact pre/post hashes. Production differs from the
feature branch before this release; apply its two exact baseline patches and
the new helper, never the feature branch's complete checkout. Reproduce with
`python3 build_candidate.py BASELINE_STR OUTPUT_STR`; hashes must match.
Deploy helper, claim guard, then checkout using staged files and atomic rename.
Verify baseline hashes immediately before replacing each existing file.
Use Ferozo runbook and `credential_ref: ferozo-prod-root`; no secrets in this
release. Commit/push isolated branch before release. Do not merge main or
overwrite the shared dirty checkout as part of this release.

Backup location: `/root/tickex-backups/20261003/shared-checkout-intent/`.
Back up exact files plus SQLite through native `.backup`; verify integrity,
hashes and an isolated backup-of-backup restore before deploy. Snapshot stays
root-only and is never copied into Git or restored over new orders.

## Rollback and recovery

Restore only the prior `checkout_totalcoin.php` after checking the current
release hash. **Retain the one-line claim guard excluding `dispatching` from
stale acquisition**, even during rollback: the old unrestricted guard could
create a second provider request for an ambiguous dispatch. Leave the unused
new helper in place. No DB restoration, claim reset or hold release is part of
rollback. Syntax-check and smoke the restored checkout.

For `dispatching` without a persisted order, reconcile the provider using the
server reference and retained operational evidence. Do not mark failed or
release capacity based only on elapsed time. A terminal state must be verified
with the provider before any manual recovery. A lost provider response is a
deliberate support case, not an invitation to retry charging.

Post-deploy smoke uses GET only: independent sessions on the same public ref
must get different hidden own refs, same session must retain its ref, no-cache
must be present, and a foreign `rid` must never expose payment URL. Confirm
all deployed SHA-256 hashes, lint both runtimes and DB quick_check. No real
payment or intentional order/reserve mutation is authorized for QA.
