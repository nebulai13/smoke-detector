# High-Level Design (HLD)

**Project:** Smoke Detector
**Status:** Draft v0.1 — 2026-11-17 — for review
**Implements:** [SRS.md](SRS.md)

---

## 1. Architecture decisions (summary)

| # | Decision | Options considered | **Chosen** | Why |
|---|---|---|---|---|
| AD-1 | Backend runtime | PHP/Slim · PHP/Laravel · Node on VPS · BaaS (Firebase/Supabase) | **PHP 8.4 + Slim 4** | Runs natively on IONOS Webhosting Plus, which most likely can't keep a Node process alive. Small, explicit and easy to unit test. |
| AD-2 | Database | MariaDB/MySQL (IONOS) · SQLite | **MariaDB 11.8 from the IONOS package** (verified) | Included, managed and backed up. Concurrent writes from many users. Reachable only from the webspace. |
| AD-3 | Client strategy | 3 native thin clients · KMP · PWA-first · Flutter/RN | **TS web + SwiftUI + Jetpack Compose, all thin** | **All game rules run on the server**, so business logic is written and tested once, in PHP. Clients only render and queue logs. |
| AD-4 | API contract | Code-first · **Contract-first OpenAPI 3.1** | **Contract-first** | One `openapi.yaml` generates typed clients for TS, Swift and Kotlin. The backend validates against it in tests (contract tests). |
| AD-5 | Auth | Email+pw · +Apple/Google · Magic link | **Email + password, JWT access (15 min) + rotating opaque refresh token** | Self-hosted, no third parties. Refresh tokens are stored hashed, so they can be revoked. |
| AD-6 | Offline | Offline-first · online-only | **Offline-first logging via client outbox + idempotent batch sync** | NFR-03 zero log loss. Users log outdoors. |
| AD-7 | Realtime | WebSockets · SSE · **polling + push** | **Push notifications + refresh on app foreground / pull-to-refresh** | Shared hosting allows no long-lived connections (C-1). |
| AD-8 | Background work | Queue worker · **cron** | **IONOS UnixCron → executable wrapper `bin/cron` every 5 min** (URL+secret fallback) | The only background mechanism available. Jobs are idempotent. |
| AD-9 | Hosting topology | API subdomain · **same origin** | **Same origin: `/` = web SPA, `/api/v1` = PHP** | No CORS, and the web refresh token can live in an httpOnly SameSite=Strict cookie. |
| AD-10 | Web framework | React · Vue · Svelte | **React 18 + Vite + TypeScript** (overridable) | Largest ecosystem for testing (Vitest, Testing Library, Playwright, MSW). |
| AD-11 | Repo & CI | **GitHub monorepo + Actions** · GitLab · manual | **GitHub monorepo** | One PR can change contract + backend + clients. macOS runners for iOS. |
| AD-12 | Delivery order | **Web → iOS → Android** | as chosen | The web client exercises the API quickly. Native clients follow against a stable contract. |

---

## 2. System context

```mermaid
flowchart LR
  subgraph Clients
    W[Web SPA<br/>React + TS]
    I[iOS app<br/>SwiftUI]
    A[Android app<br/>Compose]
  end
  subgraph IONOS["IONOS Webhosting Plus (DE)"]
    AP[Apache + .htaccess]
    API[PHP 8.4 Slim API<br/>/api/v1]
    CR[UnixCron */5<br/>bin/cron]
    DB[(MariaDB)]
    ST[Static files<br/>web build]
    MAIL[IONOS SMTP]
  end
  APNS[Apple APNs]
  FCM[Google FCM]
  WP[Web Push services]

  W -- HTTPS --> AP
  I -- HTTPS --> AP
  A -- HTTPS --> AP
  AP --> ST
  AP --> API
  API --> DB
  CR --> DB
  CR -- push outbox --> APNS & FCM & WP
  API -- verification/reset mails --> MAIL
  APNS --> I
  FCM --> A
  WP --> W
```

---

## 3. Repository layout (monorepo)

```
smoke-detector/
├── api/openapi.yaml            # single source of truth for the HTTP contract
├── backend/                    # PHP 8.4, Slim 4
│   ├── public/index.php        # front controller (document root on IONOS)
│   ├── public/.htaccess
│   ├── src/Domain/             # pure PHP: scoring, streaks, XP, plan, duels — NO I/O
│   ├── src/Application/        # use cases (LogSmoke, CheckIn, CreateDuel, FinalizeDays…)
│   ├── src/Infrastructure/     # PDO repositories, mailer, push senders, clock
│   ├── src/Http/               # Slim actions, middleware, request/response mapping
│   ├── src/Cron/               # job runner + jobs
│   ├── config/rules/v1.php     # rule table: every tunable number (SRS App. A). One file per version, never edited once released
│   ├── migrations/             # Phinx migrations
│   ├── bin/console             # CLI: migrate, cron:run, …
│   └── tests/{Unit,Integration,Contract}/
├── web/                        # React + Vite + TS
├── ios/                        # Xcode project, SwiftUI
├── android/                    # Gradle, Compose
├── shared/assets/              # sounds, animations (Lottie/Rive), badge art, i18n strings source
├── docs/                       # SRS, HLD, ADRs
└── .github/workflows/
```

---

## 4. Backend design

### 4.1 Layers
- **Domain** (pure, deterministic and fully unit-tested, no I/O and no clock). Built in step 2:
  - `Rules\RuleSet`: one immutable object with a version and every tunable number, built from
    `config/rules/<version>.php` by `RuleSet::fromArray()`. Every key is required and unknown keys are
    rejected, so nothing falls back to a hidden default.
  - `Plan`: `Baseline` (tenths of a session, integer), `BaselineCalculator` (weekly update),
    `ReductionPlan::dailyLimit`, `Goal`, `TaperSpeed`.
  - `Day`: `DayOutcome` (count, limit, checked in), `DayFinalizer` (one user-day → new streak state, XP
    awards, level change and celebration events).
  - `Streak`: `StreakCalculator` (both streaks + freezes as a state machine that emits events; a freeze
    covers the check-in streak only).
  - `Xp` / `Level`: `XpCalculator` (the whole Appendix A table), `LevelCalculator`.
  - `Competition`: `FrozenBaseline`, `Reduction` (an exact fraction), `CompetitionScoreCalculator`,
    `CompetitionScoreComparator` (the FR-COMP-03 order).
  - Still to come: `DuelJudge`, `LeagueRanker` (step 5), `BadgeEvaluator`, `HealthMilestones` (step 6).
  - Every rule number and every input has a documented upper bound (constants next to the code, far
    above any realistic value), so extreme input ends in a clear `InvalidArgumentException`, never in an
    integer overflow or an out-of-memory crash.
  - Rounding-sensitive maths uses integers only. Time comes in as a `LocalDate` or through an injected
    `Clock`, never `new DateTime()` inside the domain.
- **Application**: one class per use case. It orchestrates repositories and domain objects and owns the
  transaction boundaries.
- **Infrastructure**: PDO repositories, `SmtpMailer`, `ApnsSender`, `FcmSender`, `WebPushSender` and
  `SystemClock`, each behind an interface with fakes for tests.
- **HTTP**: Slim actions translate JSON ↔ commands. Errors use RFC 9457 `application/problem+json`.

### 4.2 Key libraries (all pure PHP, installed via Composer in CI, `vendor/` uploaded)
`slim/slim` 4, `php-di/php-di`, `robmorgan/phinx`, `firebase/php-jwt`, `symfony/mailer`,
`minishlink/web-push`, `league/openapi-psr7-validator` (tests), `phpunit/phpunit` 12,
`phpstan/phpstan` (max level), `friendsofphp/php-cs-fixer`, optional `infection/infection` (mutation testing).

### 4.3 Data model (core tables)

```mermaid
erDiagram
  users ||--o{ smoke_logs : has
  users ||--o{ day_summaries : has
  users ||--|| plans : has
  users ||--o{ consents : gives
  users ||--o{ refresh_tokens : has
  users ||--o{ devices : registers
  users ||--o{ friendships : "a/b"
  users ||--o{ user_badges : earns
  users ||--o{ xp_events : earns
  duels }o--|| users : challenger
  duels }o--|| users : opponent
  users ||--o{ feed_events : produces
  feed_events ||--o{ reactions : receives
  users ||--o{ notifications_outbox : receives
```

| Table | Key columns / notes |
|---|---|
| `users` | id, email (unique), password_hash, display_name, avatar_key, locale, timezone, email_verified_at, deleted_at |
| `consents` | user_id, type (age18, art9, privacy), version, granted_at |
| `plans` | user_id, goal_mode, target, taper_speed, default_product, start_date, rules_version |
| `baseline_history` | user_id, valid_from (local date), value DECIMAL(4,1), source (onboarding/weekly/unchanged_low_data/capped), w_mean, m_mean, w_days, m_days, rules_version. Append-only, never recalculated |
| `smoke_logs` | **id = client UUID** (idempotency), user_id, smoked_at_utc, local_date, product_type (enum: cigarette, rolled, cigar, pipe, shisha, vape, heated_tobacco, other), count, deleted (tombstone), created_at |
| `day_summaries` | user_id, local_date, count, limit, checked_in, freeze_used, status (provisional/final), xp, rules_version |
| `streaks` | user_id, type (checkin/under_limit), current, longest, freezes_held |
| `xp_events` | user_id, source, ref_id, amount, created_at — append-only ledger; XP total = sum |
| `friendships` | user_a < user_b, status (pending/accepted/blocked), requested_by |
| `duels` | id, challenger_id, opponent_id, period_days, start_date, status, winner_id, result_json (per user: frozen B₀, exact actual/expected totals in tenths, smoke-free days, check-ins, rules_version) |
| `league_results` | period_type, period_key (e.g. 2026-W47), user_id, b0_tenths, actual_tenths, expected_tenths (exact totals, not a rounded percentage), rank, final, rules_version |
| `user_badges` | user_id, badge_key, awarded_at |
| `feed_events` / `reactions` | event type + payload (no raw counts) / reaction_key, from_user |
| `devices` | user_id, platform (ios/android/web), push_token / web-push subscription, locale |
| `notifications_outbox` | user_id, type, payload, not_before, sent_at, attempts |
| `refresh_tokens` | user_id, token_hash, device_label, expires_at, revoked_at |
| `rate_limits` | key, window_start, hits |

### 4.4 API overview (`/api/v1`, full detail in `api/openapi.yaml`)
| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `/auth/verify-email`, `/auth/login`, `/auth/refresh`, `/auth/logout`, `/auth/password-reset/{request,confirm}` |
| Me | `GET/PATCH /me`, `PUT /me/plan`, `GET /me/baseline-history`, `GET /me/export`, `DELETE /me` |
| Logging | `POST /logs/sync` (batch upsert + tombstones → returns affected day summaries), `POST /days/{date}/check-in`, `GET /days?from&to` |
| Progress | `GET /me/dashboard` (today, limit, streaks, XP, level, next milestone), `GET /me/badges` |
| Friends | `GET /friends`, `POST /friends/requests` (by invite code), `POST /friends/requests/{id}/{accept,decline}`, `DELETE /friends/{id}`, `POST /friends/{id}/block` |
| Competition | `POST /duels`, `POST /duels/{id}/{accept,decline,cancel}`, `GET /duels`, `GET /duels/{id}`, `GET /leagues/{type}/{periodKey}`, `GET /leagues/xp/current` |
| Social | `GET /feed`, `POST /feed/{id}/reactions`, `POST /friends/{id}/nudge` |
| Devices | `PUT /devices/{id}` (push token), `DELETE /devices/{id}`, `GET/PUT /me/notification-settings` |

---

## 5. Key flows

### 5.1 Offline logging & sync (FR-LOG-02, NFR-03)
```mermaid
sequenceDiagram
  participant U as User
  participant C as Client (outbox)
  participant S as API
  participant D as DB
  U->>C: tap "+1"
  C->>C: create log {uuid, smoked_at, tz} → local store + outbox
  C-->>U: instant UI update + sound (optimistic)
  Note over C: when online / app foreground / periodic (WorkManager, BGTask)
  C->>S: POST /logs/sync [logs + tombstones]
  S->>S: validate: day not finalized (else per-item rejection)
  S->>D: INSERT … ON DUPLICATE KEY UPDATE (by uuid)
  S->>D: recompute day_summaries for affected dates (provisional)
  S-->>C: {accepted[], rejected[{id, reason}], days[]}
  C->>C: clear accepted from outbox, surface rejections
```

### 5.2 Day finalization & scoring (cron, every 5 min)
1. Select users whose local `date − 2 days` has passed midnight and still has provisional days.
2. For each such day: build the `DayOutcome` (count, limit from `ReductionPlan`, check-in) and run
   `DayFinalizer`. Store its result: update `streaks`, append `xp_events`, evaluate badges, mark the day
   `final`. Days are processed once each, in calendar order per user. **One
   transaction per user-day. Idempotent**: re-running is a no-op.
3. **Weekly baseline** (first run after Monday 00:00 local per user): `BaselineCalculator` → append to `baseline_history` (FR-PLAN-04…06). It uses the data known at that moment (Sat/Sun may still be provisional), and the stored value is final.
4. Finalize any duels and league periods whose days are now all final → `DuelJudge` / `LeagueRanker`.
5. Write feed events + `notifications_outbox` rows.
6. Dispatch the outbox (batch ≤ 200, retries with backoff, invalid tokens pruned).
7. Housekeeping: expire duel invites (72 h), purge accounts deleted > 30 days ago, delete expired tokens.

The job runner holds a DB advisory lock (`GET_LOCK`) so overlapping cron runs never double-process.
The time budget per run is < 50 s. Leftover work is picked up in the next run.

### 5.3 Duel lifecycle
```mermaid
stateDiagram-v2
  [*] --> proposed
  proposed --> declined
  proposed --> expired : 72h
  proposed --> accepted
  accepted --> active : next local day
  active --> forfeited : account deleted
  active --> finalizing : last day ended
  finalizing --> completed : both users' days final (+48h)
  completed --> [*]
```

### 5.4 Reduction formula (FR-COMP-03/06)
```
B0             = max(1.0, baseline_history value valid at window start (or join date))   # frozen, used everywhere below
counted_days   = days in window since max(window_start, signup_date)
expected       = B0 × counted_days
actual         = Σ count(day)             where day checked in
               + Σ max(B0, count(day))    where day NOT checked in (a streak freeze does not change this)
reduction      = 1 − actual / expected
```

The reduction is kept as the exact pair (actual, expected) in tenths of a session and compared by
cross-multiplication, so ties are detected exactly. Percentages are rounded for display only. A window
without any counted day has no reduction: that participant is unrated.


---

## 6. Client design (all three follow the same shape)

```
UI (screens)  →  ViewModel / state  →  Repository  →  ├─ Generated API client (from openapi.yaml)
                                                     └─ Local store + Outbox (offline logs, cached dashboard)
SoundManager / Haptics / Celebration overlay ← driven by server "events" in responses
```

| | Web | iOS | Android |
|---|---|---|---|
| UI | React 18 + Vite + TS | SwiftUI, iOS 17+ | Jetpack Compose, minSdk 26 |
| State | TanStack Query + small store | Observation (`@Observable`) | ViewModel + StateFlow |
| API client | `openapi-typescript` + `openapi-fetch` | `swift-openapi-generator` | `openapi-generator` (kotlin + ktor/okhttp) |
| Local store / outbox | IndexedDB (via `idb`) + Service Worker | SwiftData | Room |
| Background sync | on focus / online event | BGAppRefreshTask + foreground | WorkManager |
| Token storage | access in memory, refresh in httpOnly cookie | Keychain | EncryptedSharedPreferences / DataStore+Tink |
| Push | Web Push (VAPID) via Service Worker | APNs | FCM |
| i18n | i18next | String Catalogs | `strings.xml` (de/en) |
| Tests | Vitest, Testing Library, MSW, Playwright | Swift Testing / XCTest, XCUITest | JUnit 5, Turbine, Compose UI tests, Robolectric |

**Celebrations are server-driven.** Responses to sync and check-in include an `events[]` array (e.g.
`streak_extended`, `level_up`, `badge_earned`). The event names are fixed by the domain (`DayEventType`): `checked_in`, `under_limit_day`, `smoke_free_day`, `streak_extended`, `streak_milestone`, `freeze_earned`, `freeze_used`, `streak_broken`, `level_up`, always in that order. Negative moments are deliberately never sent as events: the end of the under-limit streak (on an over-limit day or on a day covered by a freeze) and a freeze dropped at the cap. The user sees the changed counter on the dashboard instead. Clients map each event to an animation and sound. Clients
never compute XP themselves, so the three platforms can't drift apart. The one exception is an
optimistic "+1 saved" sound/animation that plays locally.

Sounds and animations live in `shared/assets/`. They must be original or licensed (OQ-1).

---

## 7. Cross-cutting concerns
- **Time zones:** every day calculation uses the user's IANA time zone stored on the server. Logs
  carry UTC plus the client's offset. `local_date` is computed server-side. A time zone change applies
  from the next local day onward.
- **Security:** HTTPS + HSTS, Argon2id via `password_hash`, rate limiting in DB (`rate_limits`),
  JWT signed with HS256 using a secret from a config file **outside the document root**, input
  validation against the OpenAPI schema, prepared statements only, security headers via `.htaccess`.
- **Privacy:** aggregate-only friend views (FR-FRND-03), generic push payloads, JSON export, hard-delete
  job, no third-party analytics. Logs are kept indefinitely while the account exists (needed for 6-month
  windows) and are purged on deletion.
- **Errors & observability:** RFC 9457 problem responses. Logs go to a file outside the document root
  with daily rotation done by cron. `/api/v1/health` checks DB + cron heartbeat (last run time).
- **Configuration:** `config/local.php` (secrets, DB, SMTP, APNs key, FCM service account, VAPID keys)
  stays outside the document root, is never committed, and is uploaded once by hand.

---

## 8. Deployment (IONOS Webhosting Plus)

Verified on the server on 2026-11-17. Details are in the git-ignored `server.info`.

```
/home/www/                                  (SSH view; SFTP is chrooted, so it sees "/" here)
├── smoke-detector/
│   ├── releases/<sha>/                     # one full build per deploy (backend + web)
│   │   ├── app/                            # src, vendor, migrations, bin/cron (executable)
│   │   └── public/                         # index.html, assets/, api/index.php, .htaccess
│   ├── shared/config/local.php             # secrets (DB, JWT, APNs/FCM/VAPID, SMTP), uploaded once
│   └── shared/logs/
└── public/smoke-detector  ──symlink──▶  smoke-detector/releases/<sha>/public
                                            # = document root of smoke-detector.witch13.org
```

- **Domain:** `smoke-detector.witch13.org` → `/home/www/public/smoke-detector` (HTTPS works, Apache +
  PHP-FPM/CGI **8.4.27**). For staging, add a subdomain with its own folder and DB.
- **`.htaccess` (verified):** mod_rewrite works (`/api/*` → `api/index.php`, everything else → `index.html`)
  and mod_headers works (security headers). The `Authorization: Bearer` header reaches PHP without
  workarounds. Code outside the document root is readable (no `open_basedir`).
- **Atomic deploy (verified):** CI builds the artifact (`composer install --no-dev -o`, `vite build`), because
  Composer is not installed on the server. Then: `rsync -az` over SSH into `releases/<sha>` → `php8.4
  releases/<sha>/app/bin/console migrate` → swap the **document root symlink**
  (`ln -sfn … smoke-detector.tmp && mv -T smoke-detector.tmp smoke-detector`) → smoke-test `/api/v1/health` →
  keep the last 3 releases for rollback.
  - The swap causes a **brief HTTP 500 for about 1–5 s** while the web server re-resolves the path. That is
    acceptable, because clients retry and logs are queued offline. Swapping a symlink *inside* a real
    document root kept serving the **old** files, so that variant must not be used.
  - Migrations must be backward compatible with the previous release (expand → migrate → contract),
    because they run before the swap.
- **Cron (IONOS "UnixCron"):** the command field only allows `a-z A-Z 0-9 - _ . /`, which means one path
  with no arguments, spaces or redirects. We therefore ship an executable wrapper
  `app/bin/cron` (`#!/usr/bin/php8.4`, which writes its own log) and register
  `/home/www/smoke-detector/current-cron` as the command. That is a stable symlink to the wrapper of the
  active release, updated during the swap. Schedule: advanced mode, `*/5 * * * *`.
  **Verified:** runs reliably every minute (start jitter < 10 s) under the same UID as SSH, with `/bin/sh`,
  cwd `/home/www` and `PATH=/usr/local/bin:/usr/bin:/bin`, so the wrapper uses absolute paths only.
- **Server facts:** Debian 12 ARM64. PHP 8.1–8.4 CLI (`php` = 8.3, so always call `php8.4` explicitly) with
  Argon2id, sodium, gmp, bcmath, intl, pdo_mysql, OPcache, APCu and curl with HTTP/2. Web PHP:
  memory_limit 512M, max_execution_time 360 s. rsync, git and the MariaDB 10.11 client are present. Node 18 (EOL)
  is present, but a public, persistent Node server is not viable. The IONOS edge sends
  `x-ws-ratelimit-limit: 1000` (window unknown).
- Mobile apps: TestFlight / Play Internal Testing via Fastlane in CI (requires the developer accounts, OQ-4).

---

## 9. Test strategy (TDD)

**Workflow:** red → green → refactor, one SRS requirement at a time. A test's name (or tag) contains the
FR ID, e.g. `test_FR_COMP_03_missing_checkin_counts_as_baseline`. A CI script produces a
**traceability report** (FR ID → tests) and fails if a `M` requirement with logic has no test.

| Level | What | Tools | Where it runs |
|---|---|---|---|
| Domain unit | Scoring, streaks, XP, plan, duel judge, league ranker, badges — table-driven cases straight from the SRS | PHPUnit | every push |
| Mutation | Do the tests notice when the domain code is deliberately broken? Target: no surviving mutant that isn't provably harmless | Infection (`make mutation`) | before each step is closed |
| Integration | Repositories, use cases, cron jobs against real MariaDB | PHPUnit + MariaDB service container | every push |
| Contract | Every API response validated against `openapi.yaml`. Generated clients compile. | openapi-psr7-validator, codegen in CI | every push |
| Client unit | ViewModels, outbox/sync logic, event → celebration mapping | Vitest / Swift Testing / JUnit 5 + Turbine | every push |
| Client UI | Key screens: onboarding, +1, check-in, duel | Testing Library / XCUITest / Compose UI | every push |
| E2E | Register → log offline → sync → duel → finalize (with time travel via test `Clock`) | Playwright against staging | on `main`, nightly |
| Deploy smoke | `/health`, login, sync one log | curl/Playwright script | after each deploy |
| Manual | Sounds/haptics feel, push on real devices, accessibility | checklist in `docs/` | per release |

**Time control:** the backend accepts a test-only `X-Test-Now` header (enabled only when
`APP_ENV=testing`), and cron jobs take `--now`. This lets 7-day duels be tested in seconds.

---

## 10. Build plan

| Step | Deliverable | Key decision (default) |
|---|---|---|
| 0 | **IONOS spike** (half a day): deploy a hello-world Slim app + migration + cron + outbound HTTP/2 to APNs sandbox + SMTP mail | Confirms the risks in §11 before anything else is built |
| 1 | Monorepo, CI skeleton, OpenAPI v1 draft, traceability script | GitHub Actions; contract-first |
| 2 | Domain core, TDD: plan/limits, day score, streaks + freezes, XP, levels | Rules in versioned config |
| 3 | Auth, consent, email verification, account export/delete | JWT + rotating refresh |
| 4 | Logging sync + check-in + finalization cron | Idempotent UUID upsert, 48 h grace |
| 5 | Friends, duels, leagues (week/month/quarter/half-year), XP league | Calendar-aligned leagues |
| 6 | Badges, health milestones, feed, reactions, nudges | Server-emitted `events[]` |
| 7 | Notifications outbox + APNs/FCM/Web Push senders | Cron dispatch every 5 min |
| 8 | **Web client** (complete, incl. PWA + Web Push) → staging pilot | React + Vite |
| 9 | **iOS client**: built and tested in the simulator and on your own iPhone (free Apple ID). **Pay the Apple Developer Program** before the push test + TestFlight | SwiftUI + SwiftData. APNs code tested against a fake until then |
| 10 | **Android client**: emulator + sideloaded APK on real devices (push via FCM works without a Play account). **Pay Google Play** before store/internal testing | Compose + Room. Free Firebase project for FCM |
| 11 | Legal pages, store listings, pilot with 5–10 friends (SRS §7) | — |

---

## 11. Risks & spikes

| Risk | Impact | Mitigation / spike check |
|---|---|---|
| IONOS cron: single-path command, interval limits | Slow finalization / notifications | **Resolved 2026-11-17**: UnixCron runs every minute (start jitter < 10 s). The command must be one path (→ `bin/cron` wrapper). Fallback: WebCron calling `/api/v1/cron?key=…` |
| IONOS blocks our IP after many SSH connections in a short time (seen 2026-11-17: SSH + web unreachable for ~10–15 min) | Failed deploys | CI deploy uses **one** SSH connection (rsync + migrate + swap in a single session, or SSH ControlMaster), with no polling loops over SSH |
| Developer fees paid only after development (OQ-4) | iOS push, TestFlight and store review can only be verified late | iOS: a free Apple ID allows simulator + own device (7-day signing), but **no APNs push and no TestFlight**. APNs sender is tested with a fake and contract tests, then verified on a device right after paying. Android: FCM + sideloaded APKs work for free, so only the Play listing waits. Plan about 1–2 weeks for the first store reviews |
| Brief 500 during the document root swap | Failed requests for about 5 s per deploy | Clients retry with backoff. Offline outbox. Deploy outside peak hours |
| ~~PHP CLI over SSH limited (no `php` binary, no symlinks)~~ | Migrations / atomic deploy | **Resolved 2026-11-17**: `php8.4` CLI + atomic symlink swap work |
| ~~PHP curl without HTTP/2 → APNs unusable~~ | No iOS push | **Resolved**: PHP curl negotiates HTTP/2 with APNs (403 MissingProviderToken as expected) |
| ~~`minishlink/web-push` needs `gmp`/`openssl` extension~~ | No web push | **Resolved**: gmp, bcmath, openssl 3 present |
| Max execution time / memory for **web** PHP (FPM) | Requests / cron-via-URL cut off | **Open**: CLI has 512M / no limit, but web values need a `phpinfo()` check. Jobs stay batched, resumable and idempotent |
| DB limits & unsafe defaults | Scale ceiling / silent data corruption | **Resolved 2026-11-17**: MariaDB 11.8, `MAX_USER_CONNECTIONS 20` (enough with short-lived PHP requests, no persistent connections). Server defaults are **non-strict, utf8mb3, CET**, so every connection sets `time_zone='+00:00'`, a strict `sql_mode` and `utf8mb4` (verified). Tables are declared `utf8mb4_unicode_ci` explicitly. CI integration tests use a `mariadb:11.8` container with the same session init. Size quota: **2 GB**. Worst case at v1 scale is 1,000 users × 20 logs/day × 1 year ≈ 7.3 M rows, about 0.7 GB of data or 1–1.5 GB with indexes. Mitigation: once days are finalized and older than 12 months, compact raw `smoke_logs` into the existing `day_summaries` (competitions only need ≤ 6 months), and alert in `/health` at 70 % usage |
| Baseline inflation to win competitions | Unfair duels | Baseline is frozen per competition (FR-PLAN-07), weekly increases are capped at +10 % (FR-PLAN-06), and faking extra logs costs under-limit XP and streaks. Re-tune after the pilot |
| Three native clients = 3× UI effort | Schedule | Thin clients, generated API clients, server-driven events. Web first validates the API |
| GDPR Art. 9 health data | Legal exposure | Consent records, minimisation, EU hosting, AV-Vertrag, export/delete from day one |
| Store review (tobacco-related content) | Rejection | Frame as a reduction/quit aid, 18+ rating, no promotion of smoking |
