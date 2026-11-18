# Image Generation Billing

Laravel 13, Inertia v3, Vue 3. Users generate images through the Pixelforge API and are shown the price: Pixelforge's cost plus a (configurable) 25% markup.

## Development environment: DDEV

The project runs in containers (PHP 8.4, MySQL 8, Node 22, Mailpit), so nothing is installed on the host and no one manages local PHP or Node versions. The containers also isolate dependency installs from the host machine. The same `.ddev/config.yaml` works on any machine.

```
ddev start && ddev composer setup
ddev npm run dev            # Vite
ddev artisan queue:work     # submissions and image downloads
ddev artisan schedule:work  # reconciliation, every minute
```

Set `PIXEL_FORGE_API_KEY` and `PIXEL_FORGE_WEBHOOK_SIGNING_SECRET` in `.env` before submitting generations.

Webhooks need a public URL: `ddev share` (ngrok), then set it as the webhook URL on the Pixelforge key.

Run npm through `ddev npm`.

## Decisions

**Money is an integer count of nanodollars** (1 USD = 1,000,000,000). Floats can't represent cents exactly, and Pixelforge reports cost to 9 decimal places, so nanodollars hold its figures exactly with no rounding on our side. Picodollars (10⁻¹²) would only be needed for rates finer than a nanodollar per token, which Pixelforge doesn't have. Nanodollars also leave more headroom in a 64-bit integer. Display rounding happens only in `Money::format`, on the server, and the exact figure is in the tooltip.

**The markup is integer basis points** (2500 = 25%) and is rounded up, so a user is never undercharged by a fraction. Integer maths means no float drift.

**Each generation snapshots the rates and markup it was priced with.** A later price change can't alter a past charge. The price list comes from `/v1/pricing`, cached, and a warning is logged if a finished generation's usage drifts from it.

**Charged exactly once.** The generation UUID is the `Idempotency-Key`, so a retried submission can't create a second generation. A charge is written only when a generation moves to `succeeded`, inside a locked transaction. Failed generations are never charged.

**State machine.** `GenerationStatus` is an enum that declares its allowed transitions (`pending → queued → processing → succeeded | failed`). A transition that isn't allowed is ignored, which makes out-of-order webhooks harmless. An interface plus abstract classes would add structure without adding behaviour for five states.

**Webhooks** are verified with an HMAC signature and a 5-minute timestamp tolerance. A unique key on `(provider, external_id)` deduplicates redelivered events, and repeats get a 2xx so Pixelforge stops retrying.

**Webhook and polling share one code path** (`SyncGeneration`). `generations:reconcile` runs every minute: it resubmits stuck submissions (safe, same idempotency key) and polls active generations whose webhooks were lost. Submission retries on connection errors and overloaded responses, and only fails once Pixelforge has explicitly rejected it on the last attempt.

**Integration code is namespaced** (`app/Integrations/Pixelforge`, `Provider` enum) so a second provider doesn't need a rewrite.

**Images** are downloaded to private storage as `{user uuid}/{generation uuid}/{uuid}.png` and served through an authorised route that redirects to a 10-minute signed URL. Another user's generation returns 404.

**Credits are a ledger.** Every purchase and charge is a signed row in `credit_transactions`, and `users.balance` is its running total, updated in the same transaction. A unique key per event (`purchase:{id}`, `charge:{generation id}`) means a double-click or a replayed webhook can't add or debit twice. "Buy credits" is a fake purchase.

**Generating needs enough credits for the worst case.** A submission is refused unless the user's balance, minus the estimated maximum of their unfinished generations, covers this one's estimated maximum. The user row is locked during the check, so simultaneous submissions can't spend the same credits. The real charge can still exceed the estimate, and is recorded anyway, so a balance can dip slightly below zero. Recording every cost exactly matters more than a tidy balance.

**The admin report matches Pixelforge.** `/admin/spend` shows what users paid, provider cost and profit per UTC day and user, and compares each day's provider cost with `GET /v1/usage`. Days use the UTC completion day, which is how Pixelforge bills. Admin routes share one guarded group, and non-admins get a 404. Make an admin with `php artisan users:make-admin <email>`.

**Database for cache, session and queue.** Suitable for this assessment, not something I would run in production.

## Checks

```
ddev composer ci:check
```

Runs formatting and lint, type checks (vue-tsc and PHPStan) and the Pest suite.
