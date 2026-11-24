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
| AD-4 | API contract | Code-first · **Contract-first OpenAPI 3.0.3** | **Contract-first** (3.0.3 rather than 3.1, because the PHP validator and all three client generators support it fully) | One `openapi.yaml` generates typed clients for TS, Swift and Kotlin. The backend validates against it in tests (contract tests). |
| AD-5 | Auth | Email+pw · +Apple/Google · Magic link | **Email + password, JWT access (15 min) + rotating opaque refresh token. Email must be confirmed before first use** | Self-hosted, no third parties. Refresh tokens are stored hashed, so they can be revoked. Confirm-first means sign-up never reveals who has an account. Details in §4.5. |
| AD-6 | Offline | Offline-first · online-only | **Offline-first logging via client outbox + idempotent batch sync** | NFR-03 zero log loss. Users log outdoors. |
| AD-7 | Realtime | WebSockets · SSE · **polling + push** | **Push notifications + refresh on app foreground / pull-to-refresh** | Shared hosting allows no long-lived connections (C-1). |
| AD-8 | Background work | Queue worker · **cron** | **IONOS UnixCron → executable wrapper `bin/cron` every minute** (URL+secret fallback) | The only background mechanism available, and the web PHP cannot work after its response (§8). Every minute because confirmation and reset mails are sent by this job. Jobs are idempotent. |
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
    CR[UnixCron every minute<br/>bin/cron]
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
  CR -- mail outbox --> MAIL
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
│   ├── public/.htaccess        # comes with the first deployment
│   ├── src/Domain/             # pure PHP: scoring, streaks, XP, plan, duels — NO I/O
│   ├── src/Application/        # use cases (LogSmoke, CheckIn, CreateDuel, FinalizeDays…)
│   ├── src/Infrastructure/     # PDO repositories, mailer, push senders, clock
│   ├── src/Http/               # Slim actions, middleware, request/response mapping
│   ├── src/Console/            # CLI commands: migrate, mail:send, housekeeping:purge, cron:run
│   ├── templates/mail/{de,en}/ # mail texts
│   ├── config/rules/v1.php     # rule table: every tunable number (SRS App. A). One file per version, never edited once released
│   ├── migrations/             # Phinx migrations
│   ├── bin/console             # CLI entry point for the commands above
│   ├── bin/cron                # executable wrapper the hosting's cron calls (runs cron:run)
│   └── tests/{Unit,Integration,Support}/   # the contract is checked inside the Integration HTTP tests
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
- **Infrastructure**: PDO repositories (among them `PdoMailOutbox`), `SymfonyMailer` (SMTP),
  `SodiumMailValuesCipher` (libsodium), `FileCronHeartbeat`, `ApnsSender`, `FcmSender`, `WebPushSender` and
  `SystemClock`, each behind an interface with fakes for tests.
- **HTTP**: Slim actions translate JSON ↔ commands. Errors use RFC 9457 `application/problem+json`.

### 4.2 Key libraries (all pure PHP, installed via Composer in CI, `vendor/` uploaded)
`slim/slim` 4, `php-di/php-di`, `robmorgan/phinx`, `firebase/php-jwt`, `symfony/mailer`,
`minishlink/web-push`, `league/openapi-psr7-validator` (tests), `phpunit/phpunit` 12,
`phpstan/phpstan` (max level), `infection/infection` (mutation testing). A code style checker is not set up yet.

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
  users ||--o{ mail_outbox : "is mailed"
```

| Table | Key columns / notes |
|---|---|
| `users` | id (UUIDv7, native `UUID` column), email (unique, lower-cased), password_hash, display_name, avatar_key, locale, timezone, email_verified_at (NULL = registration not confirmed yet), token_version (raised by a password reset; an access token is accepted only with the account's current number), created_at (for an unconfirmed registration: when its newest confirmation link was mailed) |
| `consents` | user_id, type (age18, art9, privacy), version, granted_at. Primary key (user_id, type, version) |
| `plans` | user_id (one row per user), goal_mode, target, taper_speed, default_product, start_date, rules_version (the version at onboarding), last_session_before (the local day of the last session before joining, if the user names one; §4.10) |
| `baseline_history` | user_id, valid_from (local date), value DECIMAL(4,1), source (onboarding/weekly/unchanged_low_data/capped), w_mean, m_mean, w_days, m_days (all four NULL for the onboarding entry), rules_version. Primary key (user_id, valid_from). Append-only, never recalculated. The current baseline is the entry with the latest valid_from |
| `smoke_logs` | **id = client UUID** (idempotency), user_id, smoked_at (UTC), local_date (worked out by the server, §4.6), product_type (enum: cigarette, rolled, cigar, pipe, shisha, vape, heated_tobacco, other), count (1–50), created_at (when the server first stored the log; never changes), updated_at, deleted_at (tombstone: a deleted log stays stored and stays deleted). Index (user_id, local_date). A log never changes its owner |
| `day_summaries` | user_id, local_date (primary key together), count (the sum of the day's logs that are not deleted, kept in step by every sync), checked_in_at, and what finalization writes, NULL before it: daily_limit, xp, events (the celebration events as JSON text), freeze_used, finalized_at, rules_version. A day is final when finalized_at is set (there is no status column that could contradict it). A row exists from the first log or check-in of the day; finalization creates the rows of days nobody touched |
| `user_progress` | user_id (one row per user, created with the plan): check_in_current, check_in_longest, under_limit_current, under_limit_longest, freezes_held, xp_total, level, next_day (the next local date to finalize: every earlier day is final), finalize_due_at and baseline_due_at (UTC instants the cron job looks for, each with an index). Replaces the `streaks` table of the first draft: the domain's `UserProgress` is one value |
| `plan_history` | user_id, valid_from (local date), goal_mode, target, taper_speed, rules_version. Primary key (user_id, valid_from). Starts with the onboarding. An entry that is in force is never changed; a second change on the same day replaces the entry for tomorrow. `plans` keeps what the user chose last |
| `timezone_history` | user_id, valid_from (local date), timezone. Primary key (user_id, valid_from). Starts with the onboarding, and is replaced in the same way. `users.timezone` keeps what the user chose last |
| `xp_events` | id (UUIDv7), user_id, local_date, source (the domain's `XpSource`), amount, detail (the streak length of a milestone; NULL otherwise), created_at. Append-only ledger, written at finalization; `user_progress.xp_total` is its sum, kept for speed and checked by a test. Index (user_id, local_date) |
| `invite_codes` | user_id (one row per user, made at the first look), code (10 characters, unique), created_at |
| `friendships` | user_a, user_b (the two users, the one with the smaller id as text first: one row per pair, primary key), requested_by, requested_at, accepted_at (NULL while the request waits). There is no status column that could contradict it. A declined or withdrawn request and an ended friendship are deleted. CHECK constraints: the two users differ, and `requested_by` is one of them |
| `blocks` | blocker_id, blocked_id (primary key together), created_at. A table of its own and not a status of the friendship: a block has a direction, and each of two users can block the other. A CHECK constraint: nobody blocks themselves |
| `duels` | id, challenger_id, opponent_id (each NULL once that account is deleted), period_days, status (proposed / active / declined / expired / cancelled / completed / forfeited), created_at, expires_at, answered_at, challenger_start, opponent_start (the first day of the duel in each user's own calendar), and what the decision writes, NULL before it: decided_at, winner (challenger / opponent / draw), and per side b0_tenths, actual_tenths, expected_tenths (exact totals), smoke_free_days, check_ins, xp; rules_version. Columns and not JSON, so that the side of a deleted user can be emptied by name |
| `league_results` | user_id, period_type (week / month / quarter / half_year), period_key (e.g. 2026-W47), primary key together. b0_tenths, actual_tenths, expected_tenths (exact totals, not a rounded percentage), smoke_free_days, check_ins, counted_days, rules_version, scored_at. For the ranking: check_at (when the cron step looks next), and NULL until ranked: rank, participants, xp, ranked_at |
| `user_milestones` | user_id, milestone_key (the domain's `HealthMilestone`), primary key together. reached_at (UTC): the moment the milestone was first reached. Written when a day becomes final, never changed (§4.11) |
| `user_settings` | user_id (one row per user, made by the first choice), milestone_sharing (from_two_weeks / private / all). No row means the defaults |
| `user_badges` | user_id, badge_key (the domain's `Badge`), primary key together: a user holds a badge once. awarded_at (UTC): when it was stored, which is when the day, the duel or the rank became final. The first award stays; a row is never changed |
| `feed_events` | position (the order of writing, never sent), id (a random UUID: what a client gets says nothing about the time), user_id, type (level_up / badge_earned / duel_won / duel_draw / health_milestone), detail (the level, the badge or the milestone; NULL for a duel), other_user_id (the opponent of a duel; set to NULL when that account goes), created_at. Written from final data only (§4.12). Index (user_id, position) |
| `nudges` | from_user_id, to_user_id, day (the local day of the sender), primary key together: one nudge per friend and day. created_at, delivered (whether the receiver had not checked in that day; never told to the sender). Goes with either account (§4.14). Index (to_user_id, created_at) |
| `feed_reactions` | event_id (the feed entry), user_id (who reacted), primary key together: a user has one reaction to an entry. reaction (cheer / strong / keep_going), created_at (the first time; a changed reaction keeps it). Goes with its entry and with its user (§4.13) |
| `devices` | user_id, platform (ios/android/web), push_token / web-push subscription, locale |
| `notifications_outbox` | user_id, type, payload, not_before, sent_at, attempts |
| `mail_outbox` | id (UUIDv7), user_id (`ON DELETE CASCADE`), template (confirm_email / already_registered / reset_password), locale, recipient, sealed_values (the links of the mail, encrypted with `sodium_crypto_secretbox`: random nonce per row, key derived from `jwt.secret` by HKDF-SHA256 with its own label), created_at, not_before (the next try, or the end of a run's 10-minute hold), attempts (failed tries). A row is deleted as soon as the mail server has the mail. Deliberately no index on not_before (two runs could deadlock on it) |
| `refresh_tokens` | id (the session: one row per login/device), user_id, key_hash and token_hash (SHA-256 of the session key and of the current token), device_label, created_at, generation (0 at login, +1 per refresh; a token's secret is derived from the session key and this number), rotated_at, expires_at, revoked_at. At most 20 live (not revoked) sessions per user; revoked rows are deleted by the cleanup |
| `one_time_tokens` | id, user_id, purpose (verify_email / reset_password), token_hash (SHA-256), expires_at, used_at, failed_attempts (wrong passwords entered with a confirmation link; the fifth deletes the token). Unique (user_id, purpose): a new token replaces the old one in one statement |
| `rate_limits` | key (rule name + keyed hash of the email, user id or client address; for a rule about a pair: rule name + hash of the client + hash of the email, the email last), window_start, hits. The hash is HMAC-SHA256 with a key derived from `jwt.secret` by HKDF-SHA256 with its own label, so the table alone does not tell whether a given address is in it. A key is still treated as personal data: rows are deleted with the account and after 2 days |

### 4.4 API overview (`/api/v1`, full detail in `api/openapi.yaml`)
| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `/auth/verify-email`, `/auth/resend-verification`, `/auth/login`, `/auth/refresh`, `/auth/logout`, `/auth/password-reset/{request,confirm}` |
| Legal | `GET /legal/consents` (public: the versions of the consent texts in force) |
| Me | `GET/PATCH /me`, `PUT /me/plan`, `GET/PUT /me/settings` (§4.11), `GET /me/export`, `DELETE /me`. `GET /me/baseline-history` comes with the weekly update in step 4 |
| Logging | `POST /logs/sync` (batch upsert + tombstones → returns affected day summaries), `POST /days/{date}/check-in`, `GET /days?from&to` |
| Progress | `GET /me/dashboard` (today, limit, streaks, XP, level, next milestone), `GET /me/badges`, `GET /me/health-milestones` (§4.10) |
| Friends | `GET/POST /me/invite`, `GET /friends`, `POST /friends/requests` (by invite code), `POST /friends/requests/{accept,decline,withdraw}`, `POST /friends/{remove,block,unblock}`, `GET /friends/blocked`. The other user's id is in the body (§4.7) |
| Competition | `GET/POST /duels`, `POST /duels/{show,accept,decline,cancel}` with the duel id in the body (§4.8). `GET /leagues?type&period` and `GET /leagues/xp` (§4.9) |
| Social | `GET /feed` (§4.12), `POST /feed/reactions` (§4.13), `POST /friends/nudge` and `GET /nudges` (§4.14). The ids are in the body (§4.7) |
| Devices | `PUT /devices/{id}` (push token), `DELETE /devices/{id}`, `GET/PUT /me/notification-settings` |

### 4.5 Auth & account design (step 3)

**Stack:** Slim 4, PHP-DI, `nyholm/psr7`, PDO (no ORM), Phinx migrations, `firebase/php-jwt`, `symfony/mailer`,
`symfony/uid` (UUIDv7), `psr/clock`. Tests: `league/openapi-psr7-validator` for contract tests.

**Layers:** `Http` actions only translate JSON ↔ commands and results. `Application` has one class per use
case and owns the transaction. `Infrastructure` has the PDO repositories, mailer, clock, token and password
services, each behind an interface with an in-memory or fake version for tests.

**Registration (confirm first, FR-ACC-01/02/03):**
1. `POST /auth/register` validates the input and the three consents, each sent as `{accepted: true, version}`
   with the version of the text the app showed. A version other than the one in force is
   `422 outdated_version` and nothing is stored. Then it always answers `202 {"status":"verification_sent"}`.
2. New email: create the user with `email_verified_at = NULL`, store the consents, mail a confirmation link.
3. Email of a confirmed account: nothing is changed. Mail "you already have an account" with links to login
   and password reset.
4. Email of an unconfirmed registration: replace its data (password hash, profile, consents) with the new
   submission, replace the older link with a new one and mail it. The 24 hours start again. The newest link
   therefore belongs to the newest submission, which is why confirming needs that submission's password.
5. `POST /auth/verify-email {token, password, refreshIn, deviceLabel?}` confirms the account when the token
   is valid **and the password matches the pending registration**, and answers like login (it starts a
   session). So if somebody else registered the address last, the owner's click activates nothing.
   - An invalid, expired or used token is `invalid_token`, refused before any password hashing. So is the
     leftover link of an account that is already confirmed.
   - A wrong password is `401 invalid_credentials`. It confirms nothing, carries no data of the registration
     and counts as a failed login of the client. After 5 wrong passwords the token is deleted.
6. `POST /auth/resend-verification {email}` always answers 202. An unconfirmed registration gets a new link
   valid 24 h and is kept for those 24 h. A confirmed account gets the "you already have an account" mail.
   An unknown address gets nothing.
7. A registration lives 24 h from its newest confirmation link (registration, repeated registration, resend,
   or login with the right password). After that it cannot be confirmed or re-sent, whether or not the
   cleanup has run, and `bin/console housekeeping:purge` deletes it, together with its queued mails. The cron
   job (`cron:run`, every minute) calls that command.
8. A registration does the same work in every case (one password hash, at most one queued mail), and no mail contains
   text the user typed, such as the display name. When the address has had its 10 mails of the hour, the
   password is still hashed and the answer is the same, but nothing is queued, stored or replaced: the link
   of the last mail that was queued keeps working.

**Login and tokens (FR-ACC-04, NFR-05, NFR-06):**
- `POST /auth/login {email, password, deviceLabel?, refreshIn: "body" | "cookie"}`.
  A wrong email, a wrong password and an unconfirmed registration give the same `401 invalid_credentials`,
  with the same counting towards the login limits, and the password check runs against a dummy hash when the
  email is unknown (same timing). A correct password on an unconfirmed registration additionally queues a
  mail with a fresh confirmation link, in the same transaction as the new token (the cron job sends it):
  at most 1 per hour per address and 5 per hour per client,
  counted separately from the other mail limits and never answered with 429. (An answer of its own for
  "not confirmed" would let anybody test who is registered: register the address, then log in.)
- Access token: JWT HS256, 15 min, claims `sub`, `iat`, `exp`, `jti`, `ver` (the account's token version).
  Secret ≥ 32 bytes from config. Every request reads the account row: a deleted or unconfirmed account, or a
  newer token version, makes the token invalid at once.
- Refresh token: `<key>.<secret>`. The key is 32 random bytes (base64url) and stays for the session. The
  secret of generation n is `base64url(HMAC-SHA256(K, key "." n))`, with K derived from `jwt.secret` by
  HKDF-SHA256 with a fixed label (independent of the JWT signing key, no extra setting). Only SHA-256 hashes
  of the key and of the current token, and the generation, are stored. Valid 60 days from the last refresh.
  - **Rotated on every use.** For 10 seconds after a refresh, the replaced token is answered with the *same*
    new refresh token and a fresh access token, without another rotation. Two browser tabs refreshing
    together, or a retry after a lost answer, therefore end up with the identical current token. (That is why
    the secret is derived and not random: the server can hand it out again without storing it.)
  - After those 10 seconds, and for any older token, a token that is not the current one ends the session
    (theft detection), however many refreshes lie in between.
  - A user has at most 20 live sessions; one more login ends the live one unused longest. Sessions that
    have ended (logout, theft detection) do not count and wait for the cleanup.
  - Changing `jwt.secret` ends every session, not only the access tokens.
- Native apps get the refresh token in the body. The web app asks for `refreshIn: "cookie"` and gets it as an
  `HttpOnly; Secure; SameSite=Strict; Path=/api/v1/auth` cookie, never in the body.
- `POST /auth/refresh` reads the cookie or the body (both at once is refused) and answers the way the token
  arrived. `POST /auth/logout` ends the session of the presented refresh token (this device only) and always
  answers 204. The device's last access token works until it expires (at most 15 min).
- Passwords: Argon2id through `password_hash` with fixed parameters (64 MiB, 4 passes), 10 to 200 characters
  counted in Unicode code points, never trimmed or normalised. Rehash on login when parameters change. The
  application refuses to start without Argon2id rather than falling back to a weaker hash.
- Email addresses are ASCII only, lower-cased and trimmed, so one mailbox cannot have two spellings.

**Password reset (FR-ACC-05):** `POST /auth/password-reset/request {email}` always answers 202. The link is
valid 1 h and usable once. `POST /auth/password-reset/confirm {token, password}` sets the password, ends every
session of the user, raises the token version (which ends the access tokens that are out) and clears the
failed logins counted for the address over all clients. It does not log in. A request for an unconfirmed registration sends
no mail, so the reset cannot be used to activate an account around the confirmation link.

**Rate limits (NFR-05):** fixed windows in the `rate_limits` table, aligned to the clock (a 15-minute window
starts at :00, :15, :30 and :45).
- Failed password checks (login, `DELETE /me`): 30 per 15 min per client, 5 per 15 min per pair of email
  and client, 50 per hour per email over all clients, checked in this order. A refusal stops at the first
  rule, so a client over its own limit uses up nobody else's budget. Every attempt is counted before the
  password is checked and taken back only when it succeeds, so parallel guesses cannot outrun the counter.
  The limits lock **the client that guesses, not the account**: after five wrong guesses by a stranger, the
  owner still logs in from elsewhere.
- Wrong passwords on `verify-email` count for the client only; the link has 5 tries of its own.
- Register, resend and password-reset request share one mail budget: 5 per hour per client and 3 per hour per
  pair of email and client (`429`). Over all clients an address gets at most 10 such mails per hour; above
  that the answer is unchanged and nothing is queued, stored or replaced.
- The confirmation mail triggered by a login has its own silent limits (1 per hour per address, 5 per client).
- Export: 5 per hour per account.
- Unknown addresses are counted exactly like known ones, so no limit tells who is registered.
- The client is `REMOTE_ADDR` only (forwarding headers are never read); IPv6 counts per /64 network.
- Over a limit: `429` with `Retry-After`.
- **Known weakness (SRS OQ-15):** the per-address rules still let somebody with a few client addresses lock
  the owner out. Each client may fail 5 times per 15 minutes, which adds up to 20 per hour to the address
  count of 50. So 3 client addresses (IPv4 or IPv6) reach the limit within the hour, and 10 lock login and
  account deletion for the whole hour, because the owner's own attempt is counted before the password is
  checked. Likewise 4 client addresses use up the 10 mails per hour, after which the owner's reset or
  confirmation mail is silently left out for the rest of the hour.

**Account (FR-ACC-06 to 09):**
- `GET/PATCH /me`: profile (display name 2–30 chars, avatar from the preset list, locale de/en, IANA time zone).
  An older zone name that browsers still report (`Asia/Calcutta`) is stored under the current name
  (`Asia/Kolkata`): whether PHP knows the older names depends on the time zone data of the machine, and day
  logic must never meet a name the server cannot resolve.
- `PUT /me/plan`: onboarding and later changes. Baseline 1–80 can be set only once (later it changes through
  the weekly update only). Goal, target (below the current baseline) and taper speed can be changed.
- `GET /me/export`: all data of the user as JSON. Every table with user data is registered with an exporter
  and an eraser. A test reads the database schema and fails when a table that references users is in
  neither registry, so later steps cannot forget export or deletion. The export never contains password or
  token hashes. It lists the user's sessions (device label, created, last refreshed, expires) and the mails that wait to
  be sent (kind, queued at, failed tries; never the address or the content).
- `DELETE /me {password}`: deletes every registered table's rows of the user in one transaction, then the
  user. Nothing is kept: the rate-limit counters of the email (alone and per client) and of the user id go too, and the access tokens
  stop working at once.
- A new table is added in one place (`Infrastructure\Privacy\UserDataTables`) with its exporter and eraser
  together. Tables owned by one user also get `ON DELETE CASCADE` as a safety net; tables shared between
  users (duels, friendships) need their own eraser.
- A table has one member in the export, or more when a user has its rows in different roles: `nudges` gives
  `nudgesSent` and `nudgesReceived` (§4.14), because the two are exported with different columns. Every member
  name is used once in the whole export, and the table is erased once.

**Errors:** every error is RFC 9457 `application/problem+json` with a stable `type` slug (e.g.
`validation_failed` with a field list, `invalid_credentials`, `rate_limited`, `service_unavailable`). Each
entry of the field list has the field, a stable `code` from a closed list (clients translate by it) and an
English message for developers. Unknown fields in a request are rejected. A database outage, a deadlock or a
lock wait timeout is answered as `503 service_unavailable` (with `Retry-After` where the server can tell)
and is not retried by the server. Counter work that runs after a commit (taking a counted login attempt
back, clearing the counters of an address or of a deleted account) is the exception: if it fails, the
finished login, confirmation, reset or deletion stands, a warning is logged, and the counter is left to the
2-day cleanup.

**Transactions and locks:** every use case locks the account row first and only then tokens or sessions; the
cleanup uses one transaction per table. A new one-time token replaces the old one with
`INSERT … ON DUPLICATE KEY UPDATE`. A use case queues its mail last, in the same transaction as the token:
if the mail cannot be queued, the token is rolled back too. The mail sender takes its batch in a short
transaction of its own (`SELECT … FOR UPDATE SKIP LOCKED`, touching only `mail_outbox`) and talks to the
mail server outside any transaction. It marks each taken row with its own `UPDATE … WHERE id = ?`. One
`UPDATE … WHERE id IN (…)` for the whole batch is not safe: MariaDB may run it as a scan of the table, which
waits at the rows another run holds, and two runs then deadlock. It does not lock the account. Deleting a sent mail and deleting its
account at the same instant take their locks in opposite order and can deadlock: the sender then leaves
the row held (at worst a duplicate mail 10 minutes later), or the deletion is answered 503. Taking a
batch locks the table for a few milliseconds, and a request that queues a mail at that moment waits.

The read that tells a use case *which* account to lock (user by email, token owner, session by key) runs
**before** the transaction, never inside it, and the transaction starts with the lock on the account.
Reason: MariaDB 11.8 has `innodb_snapshot_isolation` on by default and gives a transaction up with error
1020 ("Record has changed since last read") when it locks a row that changed after the transaction's first
plain read. That happened exactly when two requests about one address arrived together, answered 500, and
which request failed depended on whether the address was registered. As a safety net, errors 1020, 1213 and
1205 are all answered as 503. `LockingTest` checks the lock order with two database sessions for verify-email, reset confirm, refresh
and logout, and runs three real processes at once for register, resend and reset request in each state of
the address (unknown, pending, confirmed). Three logins to one account at once, and logins
racing the deletion of the account, run as real processes too: every login ends with a session or with
`invalid_credentials`, and the deletion goes through.

**Mail:** `Mailer` interface, SMTP implementation (IONOS) and an in-memory one for tests. Templates in German
and English, chosen by the user's locale. Links point to the web app (`APP_URL/verify-email?token=…`,
`APP_URL/reset-password?token=…`). The confirmation link opens a page of the web app that asks for the
sign-up password; the mail says so. The web app removes the token from the address bar and sends no referrer.
**No web request sends mail.** The web PHP on the server cannot keep working after it has answered (§8), so a
mail sent inside a request would show in the response time whether an address has an account, and a slow
mail server would slow every sign-up. Instead:
- A use case stores its mail in `mail_outbox` in the transaction that stores the token of the link. The
  values of the mail (the links) are encrypted before they reach the repository.
- `mail:send`, run by cron every minute, takes due rows oldest first in batches of 10 and sets them aside
  for 10 minutes. It sends them outside any transaction and deletes each row once the mail server has it.
  A mail therefore normally arrives within one or two minutes.
- A mail the mail server does not take is tried again after 1, 5 and 15 minutes and then every 15 minutes
  for as long as its link is valid (24 h confirmation and "already registered", 1 h reset). It is given up
  when the next try would come at or after the link's expiry, or when it is found too old when taken. The
  row is then deleted and the log gets the template name and the exception class only, never the address
  or the link.
- Two failures are not the mail server's and are handled differently. Values that cannot be decrypted (a
  changed `jwt.secret`, a damaged value) can never succeed: the row is deleted at once with an error line.
  A template that cannot be rendered means a broken release: the row is kept and tried again 15 minutes
  later without counting a try, an error line is logged, and the run reports a failure (no heartbeat that
  minute) while the other mails still go out.
- A run that dies between sending and deleting sends the mail again 10 minutes later: a duplicate is
  accepted, a lost mail is not. The same holds when the delete itself fails.
- A run takes no new mails after 40 seconds; the budget is checked between mails, and each read from the
  mail server is limited to 10 seconds. Only templates and locales the running release knows are taken,
  so after a rollback the newer release's mails wait instead of stopping the sender. The cleanup deletes
  queued mails older than 24 hours whatever their template.
- A mail server outage does not fail the cron run (the mails are kept for another try); it shows in the
  run's log line. A row that cannot be read back at all stops `mail:send` loudly until it is fixed or
  deleted.
- A mail that a running sender has already taken can still go out to its own address within that run
  after the account was deleted. Nothing about the account is stored afterwards.

**Config:** one typed `Settings` object read from environment variables in dev/test and from
`shared/config/local.php` in production (named by `SETTINGS_FILE` or found from the release folder, see
below; production from environment variables is refused). Every key is required and unknown keys are rejected. Nothing secret is committed. The versions
of the three consent texts in force are settings; the apps read them from `GET /legal/consents` and send the
version they showed. `jwt.secret` signs the access tokens, keys the refresh token secrets, keys the encryption of queued
mails and keys the hashes in the rate-limit table. Changing it ends every session, drops the mails that
wait at that moment and starts every rate-limit counter again.

Production finds its settings file in one of two ways. `SETTINGS_FILE`, when set, always wins, and a named
file that is missing stops the application. Without it, code that runs from a release folder
(`<root>/releases/<name>/app`, §8) reads `<root>/shared/config/local.php`. This is how the cron wrapper
works, because the hosting's cron passes neither arguments nor environment, and the web entry point uses
the same rule. In the repository layout the rule never applies. Every environment refuses a relative log
path (cron and the web server start in different directories), and production refuses the mail transport
`null://` (it would delete every mail unsent).

**Local and CI database:** `mariadb:11.8` (docker compose service `db`, GitHub Actions service container).
Every connection sets UTC, strict mode and utf8mb4. Integration tests migrate a fresh schema once and
isolate each test. The dev container mounts only `backend/` and `api/`, never the repository root, so the
git-ignored `.env` and `server.info` are not visible inside it.

### 4.6 Logging, check-in and day finalization (step 4)

**Decided with the owner (2026-11-22):** feedback for a check-in is shown at once and is provisional; a
change of the plan counts from the next local day; after a check-in the day's logs can still be corrected
while the day is inside its 48 hours, and the check-in stays.

**Local days (FR-I18N-02).** A log carries the instant it happened (`smokedAt`, UTC). The server works out
the local date; the client's idea of the date is never trusted.
- The time zone of a user on local date D is the newest `timezone_history` entry with `valid_from ≤ D`.
  A date before the first entry has the zone of the first entry.
- The history starts with the onboarding (first entry: the plan's start date and the zone of the account).
  Before the onboarding no day has been played, so a change of the zone simply takes effect. After it, a
  change made on local day D is stored with `valid_from = D + 1`: today keeps its zone.
- A new zone never lets a day begin in the past. Moving east past midnight (23:30 in Vienna is already
  tomorrow in Tokyo), D + 1 has begun in the new zone, so the change is valid from the first date after
  today that has not begun there (D + 2). Otherwise a change of the profile would turn today into
  tomorrow and leave stored logs on another date than the history gives.
- A new change replaces the changes that were still waiting from its own first day on; a waiting entry
  for an earlier day stays, because it decides when today ends. A change that changes nothing (back to
  the zone the history gives for that day anyway) writes no entry.
- Day D starts at 00:00 of D in the zone of D (where the clock skips midnight: at the first instant of
  the date) and ends where D+1 starts. An instant belongs to the latest day that has started. So every
  instant has exactly one day, also across a change of zone. (Moving east makes the last day in the old
  zone shorter; a jump east across the date line can leave it empty, and then no instant belongs to it.)
- This is pure calculation without I/O: `Application\Time\LocalDays` (zone, start and end of a date, the
  date of an instant) from a list of `ZoneChange`. It sits next to `LocalDate`, which it builds on.
- Every "today" of a user who has a plan comes from `LocalDays`, never from `users.timezone` directly:
  that column may already hold the zone of tomorrow.

**Plan and limit.** The limit of day D is `ReductionPlan::dailyLimit` of the baseline valid on D
(`baseline_history`), the plan valid on D (`plan_history`) and the rules of the plan. `PUT /me/plan` appends
an entry valid from the next local day, or from today when it is the first plan (onboarding). Nobody can
raise today's limit after going over it, and every past day can be recalculated exactly (NFR-04).
- Only goal and taper speed are in the history. The usual product is no rule and changes at once. A
  request that changes neither goal nor taper speed writes no entry.
- `GET /me` shows what the user chose last, the limit of today, and `plan.pendingFrom` (a date) while the
  choice is not in force yet. "The current baseline" is everywhere the entry that is valid today.
- The onboarding also creates `user_progress`: nothing earned, the first day to finalize is the start
  date, and the first weekly baseline is due at the start of the first Monday on which the plan is seven
  days old.
- A change of the time zone moves the two due instants of `user_progress` in the same transaction: the
  day to finalize may end at another instant now, and the user's Monday begins at another one.

**`POST /logs/sync` (FR-LOG-02, -03, -07; NFR-03).** A batch of at most 200 items
`{id, smokedAt, productType, count}` or `{id, deleted: true}`.
- Stored with `INSERT … ON DUPLICATE KEY UPDATE` by the client's UUID. The same item sent twice changes
  nothing. An item with a known id and other values replaces the stored log (back-dating, correcting).
  An id is the same log however the app spells it (upper or lower case); the answer repeats it as sent.
- `smokedAt` needs a UTC offset. Fractions of a second are dropped.
- A deleted log stays deleted: a later item for its id is accepted and changes nothing, so an old item
  that arrives late cannot bring a log back. Deleting a log the server never saw is accepted and stores
  nothing.
- An item that is stored exactly as sent is accepted again even when its day has become final in the
  meantime (NFR-03: the app may never have got the first answer).
- Each item is answered on its own: `accepted`, or `rejected` with a reason. Reasons:
  `day_finalized` (FR-LOG-07; also when a correction would move a log out of or into a final day),
  `in_future` (more than 5 minutes ahead of the server's clock), `before_start` (before the first day of
  the plan), `day_full` (the day would hold more than 300 sessions), `not_yours` (the id belongs to
  another account; the answer is the same as for any id the client must not reuse).
- A count is 1 to 50 per log and a day holds at most 300 sessions (SRS OQ-9). A malformed item makes the
  whole request `422`; the reasons above are answers, not errors.
- The affected days are recomputed in the same transaction (count = sum of the logs that are not
  deleted) and come back in the answer, so the client shows count, limit and what is left (FR-LOG-05).
  The answer holds the day of every accepted item (for a moved log the old and the new one), oldest
  first, also when nothing changed: a repeated batch gets the days again.
- The rules are checked per item in this order: `not_yours`, deleted log (accepted, nothing changes),
  stored exactly as sent (accepted), `in_future`, `before_start`, `day_finalized`, `day_full`.
- Without a plan (onboarding not done) the request is `409 plan_required`.
- One transaction per request, the account row locked first.
- A day takes nothing more once its 48 hours are over, whether the cron job has already made it final or
  not: sync and check-in compare the clock with the end of the day plus 48 hours, not only with
  `user_progress.next_day`. So the grace period does not get longer when the cron job is late.

**`POST /days/{date}/check-in` (FR-LOG-04).** For today and for every earlier day that is not final. It
stores `checked_in_at` once (a repeated check-in changes nothing) and answers with the day and the
provisional feedback described below. A day that is final, in the future or before the plan is refused
with `409` and the type `day_finalized`, `day_in_future` or `day_before_start`; a date that is none is
`422`.

**`GET /days?from&to`** (at most 92 days) lists the days with count, limit, check-in and status. These
are the only query parameters of the API: query strings end up in server logs, and two calendar dates say
nothing about a person (whose days they are is in the token). A test lists the reviewed exceptions.
**`GET /me/dashboard`** gives today (count, limit, remaining), both streaks, freezes, XP and level.
**`GET /me/baseline-history`** lists the baseline entries (FR-PLAN-08).

**Provisional feedback (FR-GAME-06).** Stored progress changes only at finalization. What the user sees
before that is a projection: the stored `user_progress` run through the domain's `DayFinalizer` for the
days that are not final yet, in order, with their counts and check-ins as they stand. A day without a
check-in whose local day is not over is left out of the projection (it is not missed yet).
- The dashboard shows the projected streaks, XP and level and marks them as provisional when they differ
  from the stored ones.
- A check-in answers with the celebration events the projection gives for that day (checked in, under the
  limit, smoke-free, streak extended, milestone, freeze earned, level up).
- The projection is recalculated on every request and never stored, so it cannot drift. If the user
  corrects a day afterwards, the next answer simply shows the new projection.

**Day finalization (`days:finalize`, a step of `cron:run` after `mail:send`).**
- A day is final 48 hours after its end. `user_progress.finalize_due_at` holds that instant for the user's
  next day, so the job reads the users that are due from one index and nobody else.
- Per user-day, in calendar order, one transaction with the account locked first: build the `DayOutcome`
  (count, limit, checked in or missed), run `DayFinalizer`, store the day as final with its XP and events,
  append `xp_events`, update `user_progress` and move `finalize_due_at` on. Running it again does nothing.
- The count of a final day is counted from its logs once more, not taken from the stored count.
- Days nobody touched get their row here: count 0 from the logs, not checked in.
- Inside the transaction the day is due only if `end of the day + 48 hours` has really passed, worked
  out from the zone history. A stored due instant that is too early finalizes nothing and is put right.
- Weekly baseline: when the user's Monday 00:00 has passed (`baseline_due_at`), `BaselineCalculator` gets
  one entry per calendar day of the previous 28 local days (days before the plan are left out), `null`
  for a day without a check-in, and the result is appended to `baseline_history` valid from that Monday.
  Not before the plan is 7 days old. The counts and check-ins are taken as they stand at that moment:
  Sunday can still be corrected for 48 hours, but a baseline is never recalculated (FR-PLAN-02), and a
  check-in that comes later counts for the next week's month. The 7 and 28 days of the windows come
  from the rule table; the next baseline is due one calendar week later, whatever the rule table says.
- What is due for a user is done in the order it was due (a baseline first when it is due at the same
  instant as a day), so a day is always finalized with the baseline that is valid on it, also when the
  cron job was down for days and catches up.
- Users are taken in batches of 50, the one who waits longest first. The step begins no day and no
  baseline after 20 seconds. What is left is due at the next run a minute later; the command says so,
  and that is no failure.
- A user whose day cannot be finalized (damaged data, a database error) does not stop the others: the
  transaction of that day is rolled back, the failure is logged without naming the user, the run goes on
  with the next user, and the command ends with exit code 1, so `cron:run` says FAILED and writes no
  heartbeat until somebody has looked.
- Two runs at once are safe: the account lock makes them take turns, and the second finds the day final.
  A log that arrives while its day is being finalized is either part of the final day or refused with
  `day_finalized`, never stored and left out (a test runs both as real processes).

**Known limits, accepted for the pilot** (from the audit of this step):
- Limits and finalization always use the current rule table. Stored rows name the version that produced
  them, but there is no way yet to load an older one. Before a second rule table (`v2`) is released,
  `RuleSets` has to give a version by name, and days of a `v1` plan have to be finalized with `v1`.
- If 50 or more users fail in every run (damaged data), they fill the batch and the users behind them
  are not reached. The run fails and writes no heartbeat, so it is seen; nothing is lost.
- `not_yours` tells that an id is in use by another account. Ids are random UUIDs chosen by the app, so
  this cannot be used to find one.
- `POST /logs/sync` has no rate limit of its own. A batch of 200 corrections costs about 1,500 queries
  under the lock of the caller's own account. To be looked at with the limits of step 11.
- The 20 seconds of `days:finalize` do not count the time it waits for an account lock.
- A move across the date line of more than 24 hours (from a zone 11 or 12 hours behind UTC to one 13 or 14
  hours ahead) leaves one date without any instant. That day cannot be checked in and is finalized as
  missed. If it is a Monday, the weekly baseline of that week is stored for the Tuesday and stays on
  Tuesdays from then on. No user of the pilot lives there; to be put right by taking the Monday from the
  weekday instead of from the stored instant.
- The read operations (`GET /days`, dashboard) read plan, progress and days in separate statements. A
  finalization in between can make one answer show a day as provisional that is final a moment later.

**Export and deletion.** `smoke_logs`, `day_summaries`, `user_progress`, `xp_events`, `plan_history` and
`timezone_history` are registered with exporter and eraser (the schema guard fails otherwise). The export
members are `planHistory`, `timeZoneHistory`, `smokeLogs` (deleted logs too, with `deletedAt`), `days`,
`progress` (absent before the onboarding) and `xpEvents`. The two due instants of `user_progress` are the
schedule of the cron job and are not exported.

**No data to carry over.** The migrations of this step only create tables. Nothing was deployed before
them, so there is no plan without a history and no backfill; a local database from before step 4 is
recreated.

**Left for later steps:** duels and leagues read final days (step 5); badges and health milestones (§4.10)
and the feed (step 6); the reminder that a day is still open (step 7).

### 4.7 Friends (step 5, first part)

**Invite code (FR-FRND-01).** Every user has one code: 10 characters out of `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`
(no 0, 1, I or O), made by a secure random generator at the first `GET /me/invite`. That is 50 bits: a code
cannot be guessed. Input in small letters is accepted. `POST /me/invite` replaces the code, and the old one
stops working at once. A code that is taken already is not given out again: after 5 tries in a row the request
fails, because that does not happen by chance. The link is `<app address>/invite#<code>`. The code is behind
a `#`, which a browser never sends to a server, so it is in no access log.

**No id of a person in a URL.** A path ends up in server logs like a query string (§4.4). So every operation
about another user takes the user id in the body: `POST /friends/remove {userId}` and not `DELETE /friends/{id}`.

**Requests.** Whoever has the code can ask its owner, and nothing else: the owner accepts or declines.

| Action | Rule |
|---|---|
| Send (`POST /friends/requests {code}`) | Stores a request. The answer shows name and avatar of the owner, so the sender knows whom they asked. Sent again, nothing changes. If the owner had asked the sender before, both have agreed: the request is accepted at once |
| Refused | `invite_not_found` (404): nobody has the code, or one of the two has blocked the other. `own_invite`, `already_friends`, `friend_limit_reached` (409) |
| Accept | Only by the one who was asked. The friendship keeps who asked and when |
| Decline, withdraw | The request is deleted. The other one is not told, and can ask again |
| Remove | Ends an accepted friendship for both. An open request is not removed this way |

**Limit (FR-FRND-04).** For every user, friends and own open requests together are at most 50. A request
that is sent takes its place among the sender's 50 at once, and keeps it when it is accepted. So a new
request is refused when the sender is at 50, and an acceptance is refused when the one who accepts is at 50.
Requests from others do not count: otherwise strangers with the code could fill the list. Nothing is ever
refused because of the other user's count. That count is nobody else's business: a refusal would tell a
stranger with the code, or somebody who was only asked, how many friends a user has.

**Blocks (FR-FRND-02).** A user can block whom they can see: a friend, or somebody with an open request in
either direction. Anybody else is answered `friend_not_found`, as a user id that does not exist is, so the
answer does not tell whether an id exists. A block deletes the friendship or request and hides the two from
each other: for both, the other one's invite code seems not to exist. Blocking again changes nothing. Only the
blocker sees the block (`GET /friends/blocked`) and can lift it. Lifting does not bring the friendship back.
Nobody learns who has blocked them, not even from their own data export.

**What friends see (FR-FRND-03).** Name, avatar, level, XP, the two streaks (current and longest), and since
when they are friends. The values are the stored ones, as they were when the friend's last day became final.
A friend's open days stay the friend's own business (§4.6 shows them to the user alone). A friend never
sees counts, limits, the baseline, streak freezes, the time zone or the time of a log. Somebody with an open
request sees name and avatar only. Reduction in percent comes with the leagues (§4.9). Of the badges a friend
sees which ones a friend holds and never when they were earned (§4.10). Health milestones follow the user's
own setting, with the second part of step 6.

**Locks.** Every change locks the accounts of both users first, in the order of their ids. Two requests that
cross each other cannot wait for each other, and two acceptances at the same moment cannot pass the limit
together. If an account was deleted while the request waited, the answer is the same as for a user who was
never there. Lifting a block changes a row of the blocker alone and locks that account only.

**Export and deletion (FR-ACC-07, FR-ACC-08).** The export holds the invite code, the friendships and open
requests, and the blocks the user has set. Of the other user it holds the id and nothing else. Deleting an
account deletes its code, every friendship and request it is part of, and every block in both directions.

**Known limits, accepted for the pilot:**

- **No rate limit on friend requests.** A code has 50 bits, so guessing is hopeless, but somebody who has a
  code can send, be declined and send again without end. Blocking stops it. Belongs with the limits of step 11.
- **An invite code does not expire.** It works until the user replaces it.
- **A declined request leaves no trace**, so the sender does not learn it. A notification could tell (step 7).
- **A block can be told from a decline.** After a decline the sender can ask again and gets `pending`. After
  a block the code seems not to exist. That is the same answer as after a renewed code, so the sender cannot be
  sure, but can guess.
- **A replaced invite code is free again.** The generator could give it to another user (one chance in 2^50
  per new code). Somebody who still has the old code would then ask that user.
- **No test with two requests at the same moment.** The tests show that every change waits for both accounts
  (with a second database connection) and that the limit holds one request after the other.

### 4.8 Duels (step 5, second part)

Decided by the owner: friends see percentages, never counts, limits or the baseline; the detail shows the
reduction of every duel day; a running duel ends without a winner when the two stop being friends; when an
account is deleted, its running duels are forfeits without XP for the user who is left.

**Own calendar.** Each of the two is rated over their own local days (§4.6). An accepted duel starts for
each on their own next local day and is exactly N dates of that calendar (FR-COMP-02). So the two sides can
start on different dates, and the detail numbers the days from 1.

**Lifecycle (FR-COMP-01, §5.3).**

| Status | How it is reached |
|---|---|
| `proposed` | `POST /duels {opponentId, periodDays}`: a user with a plan challenges a friend for 7, 30, 90 or 180 days |
| `declined` | The friend declines. The duel stays in both lists |
| `expired` | 72 hours pass without an answer. This is decided by the clock, as the grace period in §4.6: an expired proposal is shown as expired and can be answered or taken back by nobody, also before the cron step has written the status |
| (deleted) | The challenger takes an open proposal back. Or the two stop being friends, or one account is deleted, while it is a proposal |
| `active` | The friend accepts, within the 72 hours, with a plan of their own |
| `completed` | The last day of both is final. Decided by the cron step |
| `cancelled` | The two stopped being friends while it ran (removal or block). No winner, no XP |
| `forfeited` | One account was deleted while it ran. The other user keeps the duel, without a winner and without XP |

A running duel cannot be given up. Leaving it means ending the friendship, which ends it for both without a
result.

**Limits (FR-COMP-05).** Two friends have at most one open duel (proposed or running), whoever proposed it.
A user has at most 5 open duels: running duels and own proposals count. Proposals from others do not count
until they are accepted: otherwise friends could fill somebody's list. The limit is checked for the
challenger at the proposal and for the friend at the acceptance. An expired proposal takes no place, and a
new proposal to the same friend marks it as expired on the way.

**Score (FR-COMP-03, FR-PLAN-07, §5.4).** `CompetitionScoreCalculator` with the frozen baseline: the baseline
entry valid on the side's first day, at least the minimum of the rule table. A day with a check-in counts
with its count. A day without one counts as the baseline, or as what was logged when that is more.

- While the duel runs, the standing is provisional. It counts the duel days up to yesterday, and today once
  today is checked in (the rule of the projection in §4.6). A day without a check-in counts as missed even
  while it could still be checked in.
- The decision reads the final days of both sides and stores per side the frozen baseline, the exact totals,
  smoke-free days, check-ins and XP, with the rules version (NFR-04). The winner comes from
  `CompetitionScoreComparator`: more reduction (compared exactly), then more smoke-free days, then more
  check-ins. Equal on all three is a draw.
- XP by `XpCalculator::forDuel` (won 100, draw 50, lost 20 in v1), paid to a side only for a reduction above
  zero of its own (decided by the owner on 2026-11-24, as for the leagues, §4.9): two who both do nothing
  draw, and would otherwise collect the XP of a draw week after week. The winner is stored all the same. It goes to the ledger with the user's own
  local date of the decision, and to `user_progress` with the level recalculated, in the transaction that
  decides the duel. A reward of 0 writes nothing.

**What the opponent sees (FR-FRND-03).** Of the opponent's side: smoke-free days, check-ins and counted days,
and for each day that counts one of four levels. While the duel runs: who is ahead (`ahead`, `level`,
`behind`, by the comparison that decides the winner, over the days that count so far, once both have one), and
no percentage. Once the duel is decided: the reduction of both sides in whole percent (negative when over the
baseline). The user's own percentage is shown to the user all the time. A day is never a number:

| Level | The day |
|---|---|
| `smoke_free` | checked in without a session |
| `well_under` | checked in with at most half of the frozen baseline |
| `under` | checked in below the baseline |
| `not_under` | at or over the baseline, or not checked in |

**Of the other side, final days only.** The user's own side follows the logs at once: the days up to
yesterday, and today once it is checked in. Everything about the opponent (smoke-free days, check-ins, counted
days, day levels) covers the opponent's final days only, and who is ahead is decided over the final days of
both sides, once both have one. A final day is two days old and cannot be changed by anybody. The price: the
standing lags two days, and a 7-day duel shows its first one on its fourth day.

The figures are coarse and late on purpose, after three audit findings:

- The first version showed every day in percent with one decimal. That gives everything away: two days that
  differ by one session differ by exactly 100 ÷ baseline percent, so a friend could calculate the baseline
  and from it the count of every day. The owner chose levels and whole percent.
- A whole percentage of a running duel still moves every day. A friend who notes it daily can take the
  differences: on the first day the total is that day's reduction, and each later change is about that day's
  share of the baseline. The owner chose: while a duel runs, only who is ahead.
- "Who is ahead", computed live, could be found out by trying. A user can change the own logs for 48 hours:
  set a total, read the standing, change one log, read again. The standing flips where the own reduction
  passes the opponent's. About seven tries give the opponent's reduction of a week to one or two percentage
  points, on the same day. The owner chose: final days only. A user's final days cannot be changed, so each
  of the user's days is one fixed comparison and no more.

The user's own days are shown as levels too; the exact numbers of one's own days are in `GET /days`. The
server keeps the exact totals and decides the winner with them, so two sides can show the same whole percent
and one of them wins. The export holds the user's own exact result.

Never shown: the baseline, a count, a limit, or the totals a percentage was made from. When the two are not
friends any more, each sees the own side of their past duels, with its outcome, and neither the name nor
the side of the other.

**No id in a URL** (as §4.7): the duel id is sent in the body, also for reading one duel (`POST /duels/show`).

**Cron step `competitions:finalize`.** Third step of `cron:run`, after `days:finalize`, so a duel is decided
in the run that makes its last day final. Per run at most 50 proposals expire and at most 50 duels are
decided, proposals first; the rest follows a minute later. Every item is one transaction with both accounts
locked in the order of their ids, and is looked at again after the locks: a duel that was answered, taken
back or cancelled in the meantime is left alone. An item that fails is rolled back, logged without any id
and counted; the others are still done, and the run then fails visibly, without a heartbeat (as §4.6).

**Locks.** Proposing, accepting, declining and taking back lock both accounts first, in the order of their
ids, and read the duel again after that. Lists and details lock nothing. A duel that is gone after the wait is answered
`duel_not_found` after the transaction, so what happened in the meantime stays. The table has no CHECK that
the two ids differ: MariaDB allows none on a column with `ON DELETE SET NULL`. The code makes sure of it.

**Friends (§4.7).** Removing a friend and blocking delete the proposal between the two and cancel their
running duel, in the same transaction.

**Export and deletion (FR-ACC-07, FR-ACC-08).** The export lists the user's duels with the user's own side:
role, dates, outcome, and the own result as it was scored. Of the opponent it holds the id. Deleting an
account deletes its proposals, turns its running duels into forfeits and empties its side of every other
duel: id, first day and result. A duel that belongs to nobody any more is deleted.

**Known limits, accepted for the pilot:**

- **What the final figures still tell.** The whole percentage of a decided duel, with the levels of its days,
  narrows the possible baselines without fixing one (an audit counted hundreds of candidates for a 7-day
  duel) and gives a lower bound for the baseline. A side at exactly 100 % had no session. Accepted: a result
  needs a figure.
- **Who is ahead still tells something, slowly.** Every day that becomes final gives one fixed comparison in
  each competition two friends share: their duel and up to four league periods (§4.9), of which the short
  ones say the most. A user can choose what to log on a day before it is final, and so place the threshold.
  The two users' days become final at different hours, so a comparison can be read twice, before and after
  the other one's day is in. "Level" says that the two are exactly equal. An audit estimated that somebody who
  logs made-up values for a few weeks, with two days of waiting for each answer, could narrow a friend's
  reduction of a week to about one session. The only way to tell nothing is to show nothing until the result
  is final. The owner chose a standing that means something; this is the price, written down so that it can be
  decided again.
- **The standing compares each side's own final days.** They can differ by a day or two in number, because
  each user's days become final by their own clock.
- **Shown days use today's rule table.** A completed duel's days are worked out again from the stored days
  and the stored baseline. After a new rule version the levels could disagree with the stored totals. To be
  settled when rule versions become selectable (§4.6).
- **Leaving instead of losing.** Whoever is behind can end the friendship, and the duel ends without a
  result. The owner chose this over "the one who leaves loses", which would punish a block for good reason.
- **Declined and expired proposals are kept** until one of the two accounts is deleted. The list shows the
  100 newest duels.
- **A failing duel is tried again every minute.** With 50 or more of them, the ones behind are not reached
  (as in §4.6). The run fails visibly.
- **Badges** for duels ("First duel won", "5 duels won") are awarded when a duel is decided, from the stored
  results (§4.10).
- **XP by arrangement.** Two friends can agree to lose in turns: 120 XP per pair and week, each with a
  reduction above zero of their own. Accepted: a week of real days is the price.
- **A reduction is measured against the baseline that counts,** which is at least 1.0 a day (FR-PLAN-07).
  Somebody who smokes less than one session a day has a reduction by doing what they always do, and is
  rewarded for it. The rule closes the case of doing nothing, not this one.

### 4.9 Leagues (step 5, third part)

A league is the user and their friends over a calendar period, ranked by reduction (FR-COMP-06). There are
four period types, and every user is in the league of each type all the time.

**Periods.** Calendar-aligned, the same dates for everybody, each user in their own time zone (§4.6):

| Type | Key | Dates |
|---|---|---|
| `week` | `2026-W47` | Monday to Sunday of the ISO week. The year of the key is the ISO year: 2027-01-01 is in `2026-W53` |
| `month` | `2026-11` | the calendar month |
| `quarter` | `2026-Q4` | three months from January, April, July or October |
| `half_year` | `2026-H2` | six months from January or July |

**The score of a user for a period** does not depend on who looks. It uses the scoring of the duels (§4.8,
§5.4): the user's own days of the period, from the first day of the plan if that is later (FR-COMP-06: "rated
over the days since joining"), against the baseline valid on that first counted day, frozen (FR-PLAN-07). A
league is the same scores, ranked per viewer among the viewer and the viewer's friends of today.

**Final scores.** When `days:finalize` makes a day final that is the last day of a period, it stores the
user's result for that period in the same transaction: frozen baseline, exact totals, smoke-free days,
check-ins, counted days, rules version. One row per user, type and period in `league_results`, never changed
afterwards except for the rank (NFR-04).

**Rank and reward (FR-COMP-08, OQ-8).** The cron step `competitions:finalize` ranks a stored result when every
friend of that user is settled for the period: the friend has a stored result, or cannot get one (no plan, or
a plan that started after the period). A result that has to wait is looked at again 15 minutes later. After 7
days of waiting it is ranked with the results that are there: a friend whose days cannot be finalized must
not hold up everybody else for ever.

- The rank is 1 plus the number of participants who are better: more reduction (compared exactly), then
  more smoke-free days, then more check-ins. Equal on all three share the rank (1, 1, 3).
- Participants are the user and the friends who have a result. With at least `leagueMinParticipants` (3 in
  v1), rank 1 to 3 earns `XpCalculator::forLeague` XP (150 / 100 / 50, times 1, 2, 3 or 4 by period type).
  Only a result with a reduction above zero earns it (decided by the owner on 2026-11-23, after an audit):
  otherwise three friends who all do nothing share rank 1 and collect its XP every week. The rank is stored
  all the same, with 0 XP. It
  is written to the ledger and to `user_progress` as the XP of a duel is (§4.8).
- The rank is the user's rank among the friends of that moment. It is stored with the number of participants
  and the XP, and never recalculated.
- Per run at most 50 results, each in one transaction that locks the account of its user and reads the result
  again. A result that changed in the meantime, or whose account is gone, is left alone. A result that fails
  is rolled back, logged without any id and counted, as a duel (§4.8).

**What a user sees (FR-FRND-03, FR-COMP-08).** `GET /leagues?type=…&period=…` (`period` is a key, or `current`).

| | While the result is not final | Once the user's rank is stored |
|---|---|---|
| Own entry | own reduction in whole percent over the days that count so far, and own rank: one more than the number of friends who are ahead | own final percent, rank, and the stored reward |
| A friend's entry | ahead of the user, level, or behind. No rank and no percentage | rank, and the percent of a stored result over at least 7 counted days |
| Always | name, avatar, smoke-free days, check-ins, counted days | |

- The user's own entry follows the logs at once, as in §4.8: the days up to yesterday, and today once it is
  checked in. Of a friend only final days are shown and compared, and the comparison uses the user's own
  final days (§4.8 says why). The user's own rank needs a final day of the user.
- **Friends are not ranked against each other while the result is not final.** An audit showed why: somebody
  can add accounts of their own as friends, give each a result they choose, and read every day where a real
  friend stands among them. With 48 such accounts that is the friend's reduction to two percentage points,
  day by day: the moving percentage the owner ruled out for duels. Against the user alone it is one
  comparison a day, as in a duel. The list is ordered by group (ahead, the user, level, behind) and by name
  inside a group, so the order tells nothing more.
- **A friend's final percentage needs 7 counted days.** Somebody who joined on the last days of a period has
  a result over one to three days, and its percentage would be nearly the figure of a single day. Such a
  friend has a rank and no percentage. The user's own percentage is always shown to the user.
- A friend without a final day in the period (no plan, a plan that starts later, nothing final yet) is listed
  last, without figures. While the user has no final day in the period, nobody is ahead or behind: friends
  with a final day are listed by name, with their figures.
- A final league is made of stored results: a friend without one is listed without a rank. The list is
  ranked among the friends of today. The stored reward is the one of the moment it was decided, and the two
  can differ when friendships changed.
- A period that has not begun for the user is answered `league_not_found`. A user who has not done the
  onboarding has no calendar yet and is answered `plan_required`, here and for the XP ranking.

`type` and `period` are query parameters. They name a kind of period and a calendar period, and say nothing
about a person (§4.4).

**Weekly XP ranking (FR-COMP-07).** `GET /leagues/xp`: the user and their friends by the XP that became
theirs in the current ISO week. The week is the viewer's: from the start of the viewer's Monday to the end of
the viewer's Sunday, as two instants, for everybody in the list. It is the sum of the ledger entries written
in that time. So it counts final XP only, like everything friends see of each other's progress (§4.7), with one exception:
the XP of a reaction (§4.13, at most 10 a day) is booked and counted at once. The XP of
a day appears when the day is final, two days later. There is no reward for this ranking: XP for XP would
feed itself. XP is among the things friends see (FR-FRND-03), so the numbers are shown.

**Export and deletion.** `league_results` belongs to one user: the export lists the rows with the user's own
exact result, and deleting the account deletes them. A friend's stored rank is not changed when a user is
deleted.

**Stored for good.** The repository inserts a result once. Later writes change the time of the next look and
set the rank, the participants and the XP while they are still empty, and nothing else: a period that was
scored twice by mistake would keep its first score and its rank, and no reward could be paid twice (NFR-04).
A result that fails to be ranked is looked at again 15 minutes later, like one that waits, so it is not the
first of every run.

**Known limits, accepted for the pilot:**

- **What the final figures tell.** A new friend sees the user's final percentages of past periods, back to
  the first one both have a result for. A final percentage over a week narrows the possible baselines and
  totals without fixing them (§4.8). Smoke-free days and check-ins of a running period, looked at daily, tell
  for each day whether it was checked in and whether it was smoke-free: less than the day levels of a duel,
  but for every friend and on the same day.
- **The stored number of participants** can include somebody who is no friend any more.
- **A period whose last day is skipped** by a move across the date line (§4.6) gets no stored result.
- **Ranks are among today's friends.** Adding friends who did badly shortly before a period is ranked improves
  the own rank. Accepted for a pilot among friends.
- **The XP of a day counts in the week it became final,** not in the week of the day. Sunday's XP is in the
  next week's ranking.
- **Badges** ("League champion") are awarded when a rank is stored (§4.10).

### 4.10 Badges and health milestones (step 6, first part)

**Badges (FR-GAME-08, Appendix B).** Seventeen badges, each with a fixed key (`Badge`). The domain's
`BadgeEvaluator` says which badges a set of facts justifies. It names a badge every time its condition holds,
and the storage keeps the first award, so nothing is given twice. A badge that follows a stored total (the
longest streaks, the duels won) is made up for at the next occasion if it was missed once. A badge of a
single day (`first_smoke_free_day`, `limit_halved`, `target_reached`) is not: it is awarded with that day or
with the next day of its kind. The thresholds (7, 30, 100, 365 days, 5 duels, 50 reactions) are part of what a
badge is, so they are constants of the domain and not numbers of the versioned rule table.

| Badge | Justified by |
|---|---|
| `first_check_in` | a checked-in day |
| `first_smoke_free_day` | a checked-in day without a session. A day without a check-in is never smoke-free |
| `check_in_streak_7`, `_30`, `_100`, `_365` | the longest check-in streak has that length |
| `under_limit_streak_7`, `_30` | the longest under-limit streak has that length |
| `limit_halved` | a kept day (checked in, at or under the limit) whose limit is at most half the limit of the first day of the plan. A plan that began with a limit of 0 has nothing to halve |
| `target_reached` | a kept day whose limit has arrived at the target that was valid on that day, in a plan whose first limit was above that target. For "quit" that is a limit of 0, which the weekly baseline reaches after about a month without a session. Not reached: a limit that was the target from the first day, and a target the user set above the limit later (the limit then goes up to it). An audit found both before the first push |
| `first_duel_won`, `five_duels_won` | the number of decided duels the user won with a reduction above zero (§4.8). A draw, a cancelled duel and a forfeit are not won |
| `league_champion_week`, `_month`, `_quarter`, `_half_year` | rank 1 of a league period of that type among at least three (the minimum for the XP of a rank, from the rule table), with a reduction above zero (§4.9). A shared rank 1 makes both champions |
| `cheerleader` | 50 reactions sent. Awarded from the second part of step 6 on, which brings the reactions |

**When a badge is awarded.** Only from what is final, in the transaction that makes it final (`AwardBadges`):
when `days:finalize` makes a day final, when `competitions:finalize` decides a duel (the winner only) or
stores a league rank. A check-in alone earns nothing: the day can still be changed for 48 hours. `awarded_at`
is the time of that step. If the step fails, the badge is rolled back with it. A badge is never taken back,
also not when the streak breaks or a friend leaves the league afterwards.

**The first limit** of `limit_halved` is calculated from the calendar (the baseline and the plan terms of the
first day of the plan), not read from the stored first day. Both are the same as long as the rule table of the
first day is in force. To settle with the first new rule version (§4.6).

**Celebration (FR-GAME-06).** `GET /me/badges` lists the badges with their time. An app celebrates a badge
it has not shown yet, on each device once. There is no `badge_earned` entry in the events of a day: a day
event carries a number, a badge a key, and two of the three occasions are not days.

**What friends see (FR-FRND-03, decided by the owner on 2026-11-23).** `GET /friends` lists the keys of the
badges each friend holds, and no time. Somebody with an open request shows none. A date would say more than
the badge: the day of `first_smoke_free_day` is a day with a count of zero. Known limit: somebody who looks
at the list every day sees on which day a badge appeared, which is two days after the day itself. Accepted:
the activity feed (second part of step 6) announces badges to friends by design (FR-SOC-01).

**Health milestones (FR-GAME-09, Appendix C).** Eight fixed spans of elapsed time since the last session, from
20 minutes to 15 years. A month counts as 30 days and a year as 365 days: the sources give round figures, and a
fixed span needs no calendar and no time zone. `GET /me/health-milestones` works them out at the moment of the
request from the user's last log that is not deleted (`smoked_at`), and stores nothing:

- **No logged session:** the milestones count from the end of the day of the last session before joining,
  if the user names it in the plan (`lastSessionBefore` of `PUT /me/plan`, decided by the owner on
  2026-11-24): somebody who quit before joining would otherwise see none. The end of the day, so that the count
  never says more than the user did. Without that day `since` is null and the list is empty: the app does not
  know when somebody smoked last who never logged a session.
- **The named day** is before the first day of the plan (a session of a day of the plan is logged) and not
  before 1900. It can be named, changed or taken away with every `PUT /me/plan`: a plan sent without it has
  none. It is the user's own statement and moves nothing a friend sees beyond what §4.11 says.
- **A new session starts the count again.** A deleted log is no session.
- **Vaping only** (`vapingOnly`): every logged session was vaping, or, without a log, the plan names vaping.
  The apps then say that the evidence differs. The texts, their sources and the disclaimer (LEG-04) are in
  the apps.
- **Known limit:** the app knows the logged sessions only. Somebody who stops logging reaches every
  milestone. This view is for the user alone, who knows better.

**What friends see of the milestones** is the user's own setting (decided by the owner on 2026-11-23): see
§4.11.

**Known limits.**

- **The two log queries of the milestones** (the last session, the products used) read all logs of the user:
  the one index of `smoke_logs` is by day. About 7,000 rows for a year of heavy logging, some tens of
  milliseconds. An index by time would need a migration of an existing table; to settle with the pilot's
  numbers.
- **Compacting old logs** (§11, planned for logs older than 12 months) must keep the last session and the
  products of a user, or the milestones of somebody who quit a year ago start again from nothing.
- **Vaping only** looks at every log there is. Somebody who smoked for a year and vapes since last month is
  not "vaping only".
- **A failing badge step fails its day, duel or rank,** which is tried again at the next run, like every
  other part of that step. A badge is never worth an inconsistent result.
- **Badges by arrangement:** two accounts of one person can win duels against each other, as they can earn the
  XP of a duel (§4.8).

**Export and deletion.** `user_badges` is registered with exporter and eraser. The export member `badges`
holds key and time. The health milestones of this view are not stored and so not exported: the logs they are
calculated from are. (Those that friends may see are stored, §4.11.)

### 4.11 Health milestones for friends, and the settings (step 6, second part)

**The setting (FR-GAME-09, decided by the owner on 2026-11-23).** Every user chooses what friends see of the
health milestones: `from_two_weeks` (the default: the milestones from two weeks on), `private` (none) or
`all` (also 20 minutes and 12 hours). `GET /me/settings` and `PUT /me/settings`; a change counts at once.
Stored in `user_settings`, one row per user, made by the first choice: no row means the defaults. The
notification settings of step 7 will join it.

**Milestones for friends are reached for good.** A friend does not see where the user stands now (that is
`GET /me/health-milestones`, for the user alone, §4.10). A friend sees which milestones the user has reached
at some time:

- `days:finalize` stores them in the transaction that makes a day final (`AwardMilestones`): from the last
  session logged for that day or a day before it, to the end of that day. A session of a day that is not final
  does not count (§4.8: of another user only final days are shown).
- **A day named before joining** (§4.10) counts for friends from the first day of the plan only: the days
  before it are the user's word, the days of the plan are confirmed by check-ins. Without a logged session and
  without a named day nothing is stored, because nothing says that the user does not smoke.
- **Only confirmed time counts** (confirmed by the owner on 2026-11-24). A day without a check-in is not a day without a session (FR-LOG-03, as for
  the smoke-free day and the under-limit streak): the count begins again at the end of the last day without
  a check-in, if that is later than the last session. So a day without a check-in stores nothing, and an
  account that logs one session and is never opened again reaches nothing for friends. An audit found that
  such an account would otherwise have collected every milestone up to "15 years". The user's own view
  (§4.10) is not changed by this: it counts from the last logged session, and only the user sees it.
- A milestone is stored once, with the moment it was first reached, and never taken back. So a relapse does
  not show: the list of a friend only ever grows. This is less than "the milestones as they stand", which
  would tell friends the day of every session after a long pause.
- `GET /friends` (and the answer to an accepted request) lists the keys the friend's setting allows, without
  a time. Somebody with an open request shows none.
- With `all`, the two short milestones appear after the first day with a logged session that ends 12 hours
  after it. They say little, which is why they are not in the default.

**Known limits.**

- **A milestone that appears tells a date.** Somebody who looks at the friend list every day sees on which
  day a milestone appeared: two days after the day that completed the span. Counted back, that is the day of
  the user's last session or last day without a check-in: for `circulation` 14 days before, for `breathing`
  30. So a friend who watches closely learns, once per milestone, roughly when the user stopped. It is what
  the milestone says, with a date. A relapse does not show, and the list never shrinks except by the setting.
  Accepted with the setting: the user can choose `private`.
- **What the setting itself tells.** A list that becomes empty at once says that the user chose `private`
  (nothing else empties it). `heart_rate` or `carbon_monoxide` in a list says that the user chose `all`.
- **The moment of appearance** is the user's midnight plus 48 hours, as for streaks and levels: a friend who
  asks very often can tell the user's time zone from it.
- **A milestone whose moment falls on a day with a later session** is not stored: by the end of that day the
  count has begun again. The user's own view showed it for some hours.
- **A stored setting value the code does not know** stops the friend lists that include that user with an
  error. It shows nothing, and the API stores no such value. To be changed to "treated as private" (decided
  by the owner on 2026-11-23 for a later change).
- A milestone stays when the logs it came from are deleted with a later correction: a final day cannot be
  changed, so this cannot happen after the fact.
- The last session up to a day is read from all logs of the user (§4.10 "Known limits").

**Export and deletion.** `user_milestones` and `user_settings` are registered with exporter and eraser. The
export members are `healthMilestones` (key and the moment it was first reached) and `settings`.

### 4.12 Activity feed (step 6, second part)

**What is written (FR-SOC-01).** An entry is written in the transaction that makes its fact final
(`PublishToFeed`), never from an open day:

| Entry | Written when |
|---|---|
| `level_up` | a step raises the level: a final day, the XP of a decided duel or of a league rank. One entry per step with the level reached, also when the step passed two levels |
| `badge_earned` | a badge is stored for the first time (§4.10) |
| `duel_won`, `duel_draw` | a duel is decided, for a side with a reduction above zero (§4.8). The entry keeps the id of the other user. A lost duel is not announced |
| `health_milestone` | a health milestone is stored for the first time (§4.11) |

Streak milestones (FR-SOC-01) are in the feed as their badges: the check-in streaks of 7, 30, 100 and 365
days each have one, and a second entry for the same day would say the same twice.

**What a user sees.** `GET /feed`: the 50 newest entries about the user and the user's friends that the user
may see, the newest first.

- **Friends only.** Somebody with an open request, a removed friend and a blocked user are not in it, from
  the moment the friendship ends. An entry whose account is gone is left out.
- **No time (decided by the owner on 2026-11-23: badges without dates).** An entry has no time, and its id is
  random: the ids of users and logs begin with their time, a feed id must not. The order is by a counter
  that is never sent.
- **Health milestones of a friend** are shown as far as that friend's setting allows at the moment of the
  request (§4.11). The own ones always.
- **The opponent of a duel** is named to the opponent and to friends of the opponent (decided by the owner
  on 2026-11-23). Everybody else reads "won a duel".
- An entry with a value this version of the code does not know (after a roll-back) is left out. Only a duel
  entry has an opponent, whatever a row says.

**Known limits.**

- **The feed tells when, roughly.** An entry appears about two days after the day it is about, and somebody
  who reads the feed often sees it appear. That is what a feed is for; what the badges and milestones tell
  with their day is in §4.10 and §4.11. Nobody has to be in a feed: there is no setting for it yet, except
  the one for health milestones. To settle with the pilot.
- **Entries the user may not see never shorten the feed:** the entries are read 200 at a time until 50 are
  found or none are left (an audit found that friends who all begin in the same week have hundreds of hidden
  health milestones among them).
- **After a roll-back** a row whose type this version does not know is left out when it is read from the
  database, so a page of 200 can come back short and end the reading early: the feed can then be shorter
  than 50 until newer entries arrive. Accepted for a state that should not last.
- **A lost duel is not announced by the loser's entry, but it can be read:** somebody who is a friend of both
  sees "won a duel against" the loser in the winner's entry. That follows from naming the opponent to the
  opponent's friends.
- **Duel results are new to friends.** The friend list does not show them. An entry says that a user won or
  drew a duel with a reduction of their own: no count, no limit, no baseline.
- **A new friend sees the newest 50 entries,** also those from before the friendship, as the friend list shows
  every badge from before it.
- **The order tells a little:** entries of one step are written together (badges, then milestones, then the
  level), so entries next to each other are probably from the same day, and a level next to a duel entry
  probably came from the XP of that duel.
- **The accounts of the friends are read one by one,** as in the friend list: about 55 queries for a user
  with 50 friends. To settle with the pilot's numbers.
- **A level entry after a new rule version** could be written or left out by the first reward under it, if
  the level thresholds changed: the reward compares with the stored level. To settle with the first new
  rule version (§4.6).
- **Entries are kept for good.** A clean-up of old entries can come with the housekeeping job.

**Export and deletion.** `feed_events` is registered with exporter and eraser. The export member `feedEvents`
holds the user's own entries with their time, of an opponent the id only.

### 4.13 Reactions (step 6, second part)

**What a reaction is (FR-SOC-02).** One of three preset keys, `cheer`, `strong` and `keep_going`, on a feed
entry of a friend. No text. `POST /feed/reactions {entryId, reaction}`:

| Rule | |
|---|---|
| To what | an entry the user sees in the feed at that moment (§4.12), and never an own one. Everything else is `feed_entry_not_found`, whether the entry exists or not; an own entry is `own_feed_entry` |
| How many | one per user and entry. Sent again, it changes its key and keeps its time. It cannot be taken back |
| XP (FR-GAME-01) | a new reaction earns `XpCalculator::forReaction` XP (2, at most 10 a day in v1), booked on the user's own day and added to the progress at once: it is no smoking data, so it does not wait for a day to be final. A changed reaction earns nothing. Before the onboarding there is no day and no progress, so nothing is earned |
| Level | reaction XP can reach a level, which is an entry of the feed (§4.12) |
| Badge | the 50th reaction a user sent earns `cheerleader` (§4.10). A changed reaction is not one more |
| Limit (FR-SOC-04) | reactions and nudges together: 30 a day per user (`social.actions`, the rate limiter of §4.5, a day in UTC). The 31st is `429 rate_limited`. A changed reaction counts, and so does one that is refused |

**Who sees a reaction.** The sender sees the own reaction on the entry (`myReaction`). The owner of the entry
sees who reacted (`reactions`: name, avatar and key, oldest first), of those who are friends now. Nobody else
sees who reacted to a friend's entry, or how many did.

**What friends can tell from the XP.** The XP of a reaction is added to the sender's total at once. So the
sender's friends can see the XP on the friend list and in the weekly XP ranking (§4.9) go up by 2 at that
moment, and a level that it reaches as an entry of the feed: that somebody reacted to something just now, not
to what or to whom. It tells nothing about smoking. An audit pointed it out; the owner decided on 2026-11-24 to
keep it so (the alternatives were to leave reaction XP out of the weekly ranking, or to book it with the next
final day).

**Locks.** The step locks the account of the sender, as every step that changes the progress does (§4.5).

**Known limits.**

- **A reaction tells its receiver that the sender read the feed,** and roughly when: the receiver sees it at
  the next request. That is what it is for.
- **XP by reacting** is 10 a day at most, for five taps. Accepted: it is the Duolingo-style reward for being
  there for friends, and it is capped.
- **Reactions to entries that left the feed stay stored** and count for the badge.
- **The weekly XP ranking can be moved by reacting:** up to 70 XP a week, against up to 70 XP for one
  smoke-free day.
- **An entry whose owner deletes the account at the same moment** can answer a reaction with a server error
  instead of "not found". The request changes nothing.
- **A move across the date line** gives two local days within 24 hours, and so twice the daily maximum once.

**Export and deletion.** `feed_reactions` is registered with exporter and eraser. The export has the reactions
the user sent (`reactionsSent`: the id of the user whose entry it was, the key, the time) and, with every own
feed entry, the reactions to it (who, key, time). Deleting an account deletes the reactions it sent and those
to its entries.

### 4.14 Nudges (step 6, second part)

**What a nudge is (FR-SOC-03).** A reminder to a friend to check in, with no text. `POST /friends/nudge
{userId}`:

| Rule | |
|---|---|
| To whom | an accepted friend. Everybody else, the user themself and an id that does not exist: `friend_not_found` |
| How often | once per friend and day of the sender (the day in the time zone of the sender's account). The second is `already_nudged`, whatever became of the first |
| Delivered | only if the friend has a plan and has not checked in on the friend's own today |
| The answer | `204` with no content, the same whether it is delivered or not (decided by the owner on 2026-11-23): whether a friend has checked in today is data of a day that is not final, and friends see final days only (§4.8). The SRS had the nudge for "a friend who hasn't checked in today", which would have shown everybody the check-in state of every friend |
| Limit (FR-SOC-04) | one of the 30 actions a day that reactions count towards too (§4.13). A refused nudge counts |
| Reward | none |

**What the receiver sees.** `GET /nudges`: the friends whose nudges of the user's own today were delivered,
the first one first, as name and avatar. No time. Empty once the user has checked in today (the nudges did
their work), before the onboarding, and for a nudge of somebody who is no friend any more. A push message
comes with step 7.

**What is stored.** Every nudge that was taken, also the ones that were not delivered: the sender's limit of
one per friend and day needs them, and the sender must not be able to tell the two apart by sending again.

**Known limits.**

- **A nudge tells its receiver that the sender thought of them now.** That is what it is for.
- **A nudge that is not delivered is still stored about the receiver.** The receiver's data export has it,
  with `delivered: false`. The sender's export has the nudges the sender sent without that value.
- **Timing:** the check-in of every friend is read with one query that always answers one row, whether the
  friend checked in, logged without a check-in, has no day yet or has no plan (an outside audit found that
  reading the stored day made different work before the same answer). What remains, not measured: the plan
  of a friend is read in full only when there is one, which could tell whether the friend did the onboarding
  (not whether they checked in); and for somebody who is no friend the existence of the account is not read.
- **A friend who deletes the account at the same moment** can answer a nudge with a server error instead of
  "not found", as for reactions (§4.13). The request changes nothing.
- **The day of the sender follows the zone of the account,** not the zone history (§4.6): after a change of
  the time zone a second nudge to a friend can be possible within one day.
- **Old nudges are kept.** A clean-up can come with the housekeeping job.

**Export and deletion.** `nudges` is registered with exporter and eraser. The export members are `nudgesSent`
(whom, when) and `nudgesReceived` (who, when, delivered). Deleting an account deletes the nudges it sent and
received.

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

### 5.2 Day finalization & scoring (cron, every minute)
`cron:run` runs its steps in this order, each isolated from the others: `mail:send` (step 3),
`days:finalize` with the weekly baseline (step 4, §4.6), `competitions:finalize`, which lets duel proposals
expire, decides the duels whose days are all final and ranks league results (step 5, §4.8 and §4.9), later feed events
and the notifications outbox (steps 6 and 7), and last `housekeeping:purge`.

Every step is safe to run twice at once and stops taking new work after its share of the minute
(`mail:send` 40 s, `days:finalize` 20 s). Leftover work is picked up by the next run.

### 5.3 Duel lifecycle
```mermaid
stateDiagram-v2
  [*] --> proposed
  proposed --> declined
  proposed --> expired : 72h
  proposed --> [*] : taken back, friendship ended, account deleted
  proposed --> active : accepted (starts on the next local day of each)
  active --> cancelled : friendship ended or blocked
  active --> forfeited : account deleted
  active --> completed : both users' last days final (+48h)
  completed --> [*]
```
The rules are in §4.8.

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

### 6.1 Web client: the core without screens (first part of step 8)

Built before the screens, because it needs no visual decision and everything else stands on it. Plain
TypeScript in `web/src/core/`, no React in it:

| Module | What it does |
|---|---|
| `api/api.ts` | Calls typed from the contract (`web/src/api/schema.d.ts`, generated; a check fails when it is out of date). Answers with the data of a success and throws for everything else: `ApiProblem` (the problem document of the server) or `NetworkError` (no answer). On `401 unauthorized` it renews the access token once and sends the request again |
| `session/session.ts` | The access token, in a variable and nowhere else. Login asks for the refresh token as an `HttpOnly` cookie. At the start of the app the session is restored from that cookie. Refreshes that overlap are one request |
| `logging/logbook.ts` | The outbox in IndexedDB: a log is stored on the device first, with an id made on the device, and sent later in batches of at most 200. Accepted items leave the outbox. Refused items leave it too and are kept with their reason for the user to see (FR-LOG-07). Without a connection everything stays and goes out unchanged later |
| `logging/autosync.ts` | Sends the outbox when the app starts, when the connection comes back and when the app comes to the front |

- A log counts as sent from the moment its batch leaves, because the server may have it from then on.
  Deleting such a log queues a deletion; deleting a log that never left only takes it out of the outbox.
- The tests run against a mocked server (MSW) and a real IndexedDB implementation in memory. A request
  that no test expects fails the test.
- Dependencies are pinned to exact versions and install scripts are switched off (`web/.npmrc`).

- Requests carry cookies and the token to the app's own origin only (`credentials: 'same-origin'`, AD-9).
- **A logout without a connection** forgets the token on the device at once, but the server has not heard
  of it and the refresh cookie is still good. So the device notes that a logout is owed (a flag in
  `localStorage`, never a token). The next start delivers it before anything else and stays signed out,
  with or without a connection. A new login replaces the cookie and clears the note. A logout the
  server answered with a failure is owed in the same way. While one is owed nothing renews the session,
  and the other tabs of the app are signed out as well.
- Every request has a time limit (30 seconds). One that hangs counts as "no connection", so a sync can
  never hold its lock for ever.
- A request that comes back unauthorized after the user logged out never renews the token.
- **Every user has a logbook of their own** on the device (the database is named after the user id). What
  one user has not sent yet is never sent with another user's token, also on a shared computer.
- **Tabs take turns** (Web Locks): a sync of one tab waits for the sync of another, so an old item cannot
  reach the server after its correction. Reading a batch and marking it as sent is one database
  transaction, so a log that is deleted at the very moment it goes out gets its deletion queued. A log
  that was corrected on this device counts as known to the server too.
- A log the server could never take (a date outside the years 1 to 9999, a count outside 1 to 50) is
  refused when it is made, so it cannot block the outbox for everything behind it.

Not built yet (they belong to the screens): a timer that tries again while the browser says it is online
and the server cannot be reached, asking the browser to keep the storage (`storage.persist()`), and
emptying the list of sent ids.

---

## 7. Cross-cutting concerns
- **Time zones:** every day calculation uses the user's IANA time zone stored on the server. Logs
  carry UTC plus the client's offset. `local_date` is computed server-side. A time zone change applies
  from the next local day onward (to build in step 4: today `PATCH /me` stores the new zone at once and
  nothing records when it changed).
- **Security:** HTTPS + HSTS, Argon2id via `password_hash`, rate limiting in DB (`rate_limits`),
  JWT signed with HS256 using a secret from a config file **outside the document root**, explicit input
  validation in the HTTP layer (the OpenAPI schema is enforced by contract tests, not parsed per request),
  prepared statements only. The API sets its own security headers in middleware (so they are tested); `.htaccess` adds them for the static web app.
- **Privacy:** aggregate-only friend views (FR-FRND-03), generic push payloads, JSON export, immediate
  hard delete, no third-party analytics. Logs are kept indefinitely while the account exists (needed for 6-month
  windows) and are purged on deletion.
- **Errors & observability:** RFC 9457 problem responses. Logs go to a file outside the document root
  with daily rotation done by cron (not built yet; the cron job alone writes one JSON line per run, about
  1,440 a day). `/api/v1/health` checks the DB and reports the cron heartbeat: the end of the last cron run
  in which every step succeeded, kept in the file `cron-heartbeat` next to the application log. It is
  reported, not judged, so a stale heartbeat is still a 200; the deploy smoke test and the monitoring
  compare it with the clock.
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
  active release, updated during the swap. Schedule: **every minute** (confirmation and reset mails wait
  for this job).
  **Verified:** runs reliably every minute (start jitter < 10 s) under the same UID as SSH, with `/bin/sh`,
  cwd `/home/www` and `PATH=/usr/local/bin:/usr/bin:/bin`, so the wrapper uses absolute paths only.
  The wrapper runs `cron:run` (`mail:send`, then `housekeeping:purge`, which deletes unconfirmed
  registrations, old tokens and old rate-limit rows). It prints nothing and writes one line per run to the
  application log. `bin/cron` must keep its executable bit in git (mode 100755) and through rsync, and
  `shared/logs` must be writable (log and heartbeat file).
- **Verified on the server on 2026-11-21** (temporary probe page, deleted afterwards):
  - `REMOTE_ADDR` is the visitor's own address (IPv4 or IPv6), not a proxy. The rate limits can rely on it.
  - The web PHP runs as `cgi-fcgi` and has **no `fastcgi_finish_request()`**: a request that keeps working
    after its output holds the client for the whole time. Nothing can be done "after the response", so mail
    is never sent inside a web request. It goes into the `mail_outbox` table and cron sends it (§4.5 Mail).
  - `zend.exception_ignore_args` is on, the `Authorization` header reaches PHP, and `DELETE` with a JSON
    body arrives intact.
- **Settings on the server:** neither Apache nor cron has to pass anything. Both entry points find
  `shared/config/local.php` from the release folder they run in (§4.5 Config). If the cron wrapper cannot
  start (settings missing or invalid), it appends one line to `shared/logs/cron-start.log` (UTC time and the class of the
  failure only, because a message could quote the settings file), since the application log is not
  available at that point. Standard error gets the message.
- **Still to verify before the first release:** `SELECT @@innodb_snapshot_isolation` on the production database (the code is correct with it
  on or off, but the local and CI database should match production).
- **The release must ship** `backend/templates/` and `backend/config/rules/` next to `src/`, and
  `shared/config/local.php` needs the consent version keys.
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
**traceability report** (requirement ID → tests). It counts IDs in test method names only, knows `FR`, `NFR`
and `LEG` IDs, and fails when a test names an ID that is not in the SRS, when the SRS mentions an ID that has no
requirement row the tool can read, or when it found no test name at all. Its own tests
(`tools/test_traceability.py`) run in `make trace` and in CI. "Covered" means at least one test names the
requirement, not that it is complete. Failing on a must-have requirement without a test (`--strict`) is
switched on once all build steps that own must-haves are done.

| Level | What | Tools | Where it runs |
|---|---|---|---|
| Domain unit | Scoring, streaks, XP, plan, duel judge, league ranker, badges — table-driven cases straight from the SRS | PHPUnit | every push |
| Mutation | Do the tests notice when the domain code is deliberately broken? Target: no surviving mutant that isn't provably harmless | Infection (`make mutation`) | before each step is closed |
| Integration | Repositories, use cases, cron jobs against real MariaDB | PHPUnit + MariaDB service container | every push |
| Contract | Every API request and response in the HTTP tests is validated against `openapi.yaml`. The harness also checks that the `status` of a problem body equals the HTTP status and that a 204 has no body. A 401 always carries `WWW-Authenticate: Bearer` and a 429 always `Retry-After` (required in the contract); a 503 carries `Retry-After` only when the server can tell. From step 8 on: generated clients compile | openapi-psr7-validator; codegen in CI from step 8 | every push |
| Client unit | ViewModels, outbox/sync logic, event → celebration mapping | Vitest / Swift Testing / JUnit 5 + Turbine | every push |
| Client UI | Key screens: onboarding, +1, check-in, duel | Testing Library / XCUITest / Compose UI | every push |
| E2E | Register → log offline → sync → duel → finalize (with time travel via test `Clock`) | Playwright against staging | on `main`, nightly |
| Deploy smoke | `/health`, login, sync one log | curl/Playwright script | after each deploy |
| Manual | Sounds/haptics feel, push on real devices, accessibility | checklist in `docs/` | per release |

**Time control:** tests replace the `Clock` in the container (`FrozenClock`), and the console commands use the same
`Clock` (they take no arguments, because the hosting's cron cannot pass any). This lets 7-day duels be tested in seconds. There is no time-travel header in the API.

**Mutation testing** covers `src/Domain` and `src/Application` (the code the Unit suite tests without a
database). Code tested through HTTP or the real database is not mutated. `make mutation` fails below a
score of 99 %; it is run locally before a step is closed, not in CI (it takes several minutes). The threshold is
raised, never lowered.

**CI** runs `composer validate --strict` and `composer audit`, the Unit suite without a reachable database
(so a unit test that touches the database fails there as it does locally), the Integration suite against
MariaDB 11.8, PHPStan and the traceability report. Every job has a 15-minute timeout, read-only
permissions and checks out without stored credentials. Actions are referenced by version tag, not by
commit hash (accepted; revisit before the pilot).

Known surviving mutants, all provably harmless:
- `Level\LevelCalculator::levelFor`, the two correction loops after the square-root guess. The guess is
  never below the true level, so the second loop never runs, and it is at most one too high, so the first
  loop runs at most once. Mutants that turn `while` into `if`, `>` into `>=`, `<=` into `<` or remove the
  second loop change nothing; mutants that make a loop endless end as time-outs.
- `Competition\Reduction` (percentage for display): `$saved < 0` mutated to `<= 0`. With nothing saved the
  rounded value is the integer 0, and minus zero is the same integer.
- `Day\DayFinalizer` (milestone awards): the list cut to its first entry. One day raises the check-in
  streak by at most one, so it reaches at most one milestone and the list never has a second entry.
- `Application\Day\Projection` (a day without a row): the count 0 mutated to 1. Such a day has no check-in,
  so it is a missed day, and the rules ignore the count of a missed day.

- `Application\Competition\DuelSides` (a duel or league day without a row): the count 0 mutated to 1. Such a day has no
  check-in, so it counts as the frozen baseline or as its count when that is more. The baseline is at least
  1.0, and a count of 1 is never more than that.

Mutants that make the loops of `Application\Day\FinalizeDays` and `Projection` endless end as time-outs,
which count as caught. The run needs more memory than PHP's default (`make mutation` sets 512 MB).

---

## 10. Build plan

| Step | Deliverable | Key decision (default) |
|---|---|---|
| 0 | **IONOS spike** (half a day): deploy a hello-world Slim app + migration + cron + outbound HTTP/2 to APNs sandbox + SMTP mail | Confirms the risks in §11 before anything else is built |
| 1 | Monorepo, CI skeleton, OpenAPI v1 draft, traceability script | GitHub Actions; contract-first |
| 2 | Domain core, TDD: plan/limits, day score, streaks + freezes, XP, levels | Rules in versioned config |
| 3 | Auth, consent, email verification, account export/delete | JWT + rotating refresh. **Release conditions:** cron runs `bin/cron` every minute (`mail:send` + `housekeeping:purge`), server checks of §8 done |
| 4 | Logging sync + check-in + finalization cron (§4.6) | Idempotent UUID upsert, 48 h grace. `days:finalize` is the second step of `cron:run`, after `mail:send` |
| 5 | Friends, duels, leagues (week/month/quarter/half-year), XP league. Built in parts: friends (§4.7), duels (§4.8), leagues (§4.9) | Calendar-aligned leagues |
| 6 | Badges, health milestones, feed, reactions, nudges | Server-emitted `events[]` |
| 7 | Notifications outbox + APNs/FCM/Web Push senders | Sent by the same cron job as the mail (every minute) |
| 8 | **Web client** (complete, incl. PWA + Web Push) → staging pilot. The core without screens (§6.1) was built ahead, after step 4 | React + Vite |
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
