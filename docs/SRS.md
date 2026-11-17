# Software Requirements Specification (SRS)

**Project:** smoke-detector (working title) — a gamified "who smokes the least" competition app
**Platforms:** Web, iOS, Android
**Status:** Draft v0.1 — 2026-11-17 — for review
**Companion document:** [HLD.md](HLD.md) (High-Level Design)

Requirement IDs (`FR-…`, `NFR-…`) are stable. Every automated test must reference the ID it verifies
(TDD traceability, see HLD §9).

---

## 1. Introduction

### 1.1 Purpose
This document defines *what* the system must do. *How* it is built is in the HLD. Both must be
agreed before implementation starts.

### 1.2 Problem
Smokers who want to cut down or quit mostly rely on willpower alone. Generic quit apps track progress
privately, which gets boring and lacks social pressure. Friends who smoke often talk about cutting
down together but have no shared, fair, fun way to hold each other accountable.

### 1.3 Product summary
The app is a Duolingo-style habit app. Each user logs every cigarette, follows a personal daily
limit that drops over time (optionally to zero), and earns XP, streaks, levels and badges. Users
compete fairly against **one specific friend (duel)** or **all their friends (league)**. The winner is
whoever reduced the most compared to their own baseline over a week, a month, 3 months or 6 months.
Positive sounds, animations and health milestones reward progress. Users are never punished for
logging honestly.

### 1.4 Definitions
| Term | Meaning |
|---|---|
| **Log** | One recorded smoking event (timestamp, count ≥ 1). |
| **Check-in** | The user confirms a local calendar day as complete. A day with 0 logs counts as smoke-free **only** after a check-in. |
| **Baseline** | The user's self-declared average cigarettes/day at onboarding. All fairness comparisons are based on it. |
| **Daily limit** | Today's allowed maximum, derived from the baseline and the reduction plan. |
| **Reduction %** | `1 − actual / (baseline × counted_days)` over a period. Higher is better. Can be negative. |
| **Day finalization** | A local day becomes immutable 48 h after its local midnight (the *grace period*). |
| **Duel** | A 1v1 challenge between two friends over a fixed period. |
| **League** | A leaderboard of the user and all of their friends for a calendar-aligned period. |
| **Streak freeze** | A consumable that protects the check-in streak for one missed day. |

---

## 2. Overall description

### 2.1 User classes
| Class | Description |
|---|---|
| **Primary: adult smoker in a friend group** | 18+, smokes daily, wants to cut down or quit, and is motivated by friendly competition. Uses the phone mostly, often outdoors and sometimes offline. |
| **Secondary: quitter in a smoke-free stretch** | Has reached 0/day. Stays motivated through health milestones, streaks and helping friends. |

### 2.2 Explicitly NOT for
- **Minors (< 18).** Signup requires an age confirmation.
- **Strangers / public communities.** There are no public leagues, no discovery of unknown users and no public profiles.
- **Clinical or medical use.** The app gives no medical advice, diagnosis or therapy. Health milestones are general information from published sources and carry a disclaimer.
- **Organisations / workplaces** that track their employees.
- **Other nicotine products** (vapes, pouches) in v1. See Open Questions.

### 2.3 Operating environment
- Backend: IONOS Webhosting Plus (shared hosting, Germany): PHP 8.3, MariaDB/MySQL, Apache, SFTP, limited SSH, cron.
- Web: current Chrome, Firefox, Safari and Edge (last 2 versions), desktop and mobile.
- iOS: 17+ (iPhone). Android: 8.0+ (API 26).

### 2.4 Constraints
- C-1: No persistent server processes (no WebSockets, no queue workers). Background work runs only through cron.
- C-2: Deployment happens only via SFTP plus limited SSH.
- C-3: Health-related personal data falls under GDPR Art. 9. Explicit consent and EU hosting are required.
- C-4: Built test-first (TDD). Each requirement is covered by automated tests where technically feasible.
- C-5: German and English from day one. German is the default.

### 2.5 Assumptions
- A-1: Expected scale for v1 is ≤ 1,000 registered users and ≤ 50 friends per user.
- A-2: Users are honest most of the time. The design makes honest logging more rewarding than lying (honor system).

---

## 3. Functional requirements

Priority: **M** = must (v1), **S** = should (v1 if time permits), **C** = could (later).

### 3.1 Account & onboarding (ACC)
| ID | Pri | Requirement |
|---|---|---|
| FR-ACC-01 | M | Users register with email, password (≥ 10 chars), display name, preset avatar, language and time zone. |
| FR-ACC-02 | M | Registration requires (a) confirming age 18+, (b) explicit GDPR Art. 9 consent to processing smoking data, and (c) accepting the privacy policy. Each consent is stored with a timestamp and version. |
| FR-ACC-03 | M | The email must be verified via a link before the user can join competitions. |
| FR-ACC-04 | M | Login with email + password. Sessions persist across app restarts. Logout works per device. |
| FR-ACC-05 | M | Password reset via an emailed one-time link (valid 1 h). |
| FR-ACC-06 | M | Onboarding collects the baseline (cigarettes/day, 1–80), the goal mode (**reduce** to a target > 0, or **quit** = target 0) and the taper speed (gentle / normal / fast). |
| FR-ACC-07 | M | The user can export all of their data as JSON (GDPR Art. 15/20). |
| FR-ACC-08 | M | The user can delete their account. Personal data is removed within 30 days. Active duels end as forfeits. |
| FR-ACC-09 | S | The user can change display name, avatar, language, time zone, reminder time and notification preferences. |

### 3.2 Logging & check-in (LOG)
| ID | Pri | Requirement |
|---|---|---|
| FR-LOG-01 | M | Log a cigarette in **≤ 2 taps** from app open (the home screen has a prominent "+1" button). |
| FR-LOG-02 | M | Logging works **offline**. Logs are stored locally with a client-generated ID and synced automatically when online. Syncing is idempotent, so no duplicates are created. |
| FR-LOG-03 | M | A log can be back-dated or deleted, but only for days still inside the grace period (48 h after local midnight). |
| FR-LOG-04 | M | The user checks in a day ("Done for today"), which confirms the count. Yesterday can be checked in until it is finalized. |
| FR-LOG-05 | M | After each log, the home screen shows today's count vs. the daily limit and the remaining allowance. |
| FR-LOG-06 | M | Going over the limit shows a **neutral, encouraging** message and plays no negative sound (honor system). |
| FR-LOG-07 | M | Logs that arrive for already finalized days are rejected, and the user sees an explanation. |

### 3.3 Reduction plan (PLAN)
| ID | Pri | Requirement |
|---|---|---|
| FR-PLAN-01 | M | Daily limit on day 1 = baseline. The limit then drops by a step every week according to taper speed (default: gentle −1/week, normal −2/week, fast −3/week) until it reaches the target. |
| FR-PLAN-02 | M | The baseline is locked once set. A user can start a "new journey" (new baseline), which ends their active duels as forfeits and starts fresh league participation. This prevents gaming the score by inflating the baseline. |
| FR-PLAN-03 | S | The user can switch from *reduce* to *quit* at any time without resetting anything. |

### 3.4 Gamification core (GAME)
| ID | Pri | Requirement |
|---|---|---|
| FR-GAME-01 | M | XP is awarded according to a server-side, versioned rule table (default values in Appendix A). |
| FR-GAME-02 | M | **Check-in streak** (primary 🔥): consecutive finalized days with a check-in. It does **not** break when the user goes over the limit, because honest logging is rewarded. |
| FR-GAME-03 | M | **Under-limit streak**: consecutive finalized days with count ≤ limit. |
| FR-GAME-04 | M | Streak freeze: earn 1 for every 7 consecutive check-in days (hold at most 2). A freeze is applied automatically when a day finalizes without a check-in. |
| FR-GAME-05 | M | Levels come from cumulative XP via a threshold table (Appendix A). |
| FR-GAME-06 | M | Celebration feedback (animation + positive sound + haptics on mobile) for: log saved (subtle), check-in, under-limit day, smoke-free day, streak extended, level up, badge earned, duel/league won. |
| FR-GAME-07 | M | Sounds can be muted in settings. Mobile respects the system silent mode. Sound is never the only feedback (accessibility). |
| FR-GAME-08 | M | Badges are awarded for defined achievements (Appendix B), e.g. first check-in, 7/30/100/365-day streaks, first smoke-free day, first duel won, limit halved. |
| FR-GAME-09 | M | Health milestones are based on time since the last logged cigarette (Appendix C). Each shows its source and a disclaimer. |

### 3.5 Friends (FRND)
| ID | Pri | Requirement |
|---|---|---|
| FR-FRND-01 | M | Each user has a personal invite code/link. Opening it sends a friend request, and the other user must accept. |
| FR-FRND-02 | M | Users can list, remove and block friends. Blocking hides both users from each other everywhere. |
| FR-FRND-03 | M | Friends see only **aggregates**: reduction % per period, streaks, level, XP, badges and health milestones reached. They do **not** see individual log timestamps. |
| FR-FRND-04 | M | Maximum 50 friends per user. |

### 3.6 Competitions (COMP)
| ID | Pri | Requirement |
|---|---|---|
| FR-COMP-01 | M | **Duel:** a user challenges one friend for a period of **1 week, 1 month, 3 months or 6 months**. The friend accepts or declines. Unanswered invitations expire after 72 h. |
| FR-COMP-02 | M | An accepted duel starts on the next local day and runs for exactly the period length (7 / 30 / 90 / 180 days). |
| FR-COMP-03 | M | **Duel winner** = higher reduction % over the duel days. Days without a check-in count as *baseline* (0 % reduction). Tie-breakers in order: more smoke-free days, more check-ins, otherwise a draw. |
| FR-COMP-04 | M | The duel result is decided after the last day of **both** users is finalized. Both users are notified. |
| FR-COMP-05 | M | A user may have at most 1 active duel per friend and 5 active duels in total. |
| FR-COMP-06 | M | **League (all friends):** for each calendar-aligned period (ISO week, calendar month, calendar quarter, calendar half-year) there is a leaderboard of the user and their friends, ranked by reduction %. Users who join mid-period are rated over the days since joining. |
| FR-COMP-07 | M | A separate **weekly XP leaderboard** ranks friends by XP earned this ISO week (the classic Duolingo-style ranking). |
| FR-COMP-08 | M | Leaderboards show provisional standings during the period and final standings once the period is finalized. Winners get XP + a badge. |
| FR-COMP-09 | S | Duel detail screen: day-by-day comparison chart (reduction per day, not timestamps). |

### 3.7 Social reactions (SOC)
| ID | Pri | Requirement |
|---|---|---|
| FR-SOC-01 | M | Friend activity feed: level-ups, badges, streak milestones, duel results, health milestones. |
| FR-SOC-02 | M | Users can react to feed items with a preset reaction (👏 cheer, 💪 strong, 🔥 keep going). No free-text chat. |
| FR-SOC-03 | M | Users can **nudge** a friend who hasn't checked in today (max 1 nudge per friend per day). |
| FR-SOC-04 | M | Reactions and nudges are rate-limited (max 30 per user per day). |

### 3.8 Notifications (NOTIF)
| ID | Pri | Requirement |
|---|---|---|
| FR-NOTIF-01 | M | Push notifications on iOS (APNs), Android (FCM) and Web (Web Push), each with opt-in. |
| FR-NOTIF-02 | M | Notification types: daily check-in reminder (user-set time), streak at risk (no check-in by 21:00 local), duel invitation / start / result, overtaken in a league, reaction or nudge received, badge earned. |
| FR-NOTIF-03 | M | Each type can be turned on or off. Quiet hours default to 22:00–08:00 local. |
| FR-NOTIF-04 | M | Notification payloads contain **no health data** (no counts). Example: "Anna sent you a cheer". |
| FR-NOTIF-05 | M | Notifications are delivered within 15 minutes of the triggering event (cron-based). |

### 3.9 Localisation (I18N)
| ID | Pri | Requirement |
|---|---|---|
| FR-I18N-01 | M | All UI text, emails and push texts exist in German and English. The language is chosen per user. |
| FR-I18N-02 | M | Dates and numbers are formatted per locale. All day logic uses the user's time zone. |

---

## 4. Non-functional requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-01 | Performance | API p95 latency < 400 ms for standard endpoints on IONOS shared hosting at v1 scale. |
| NFR-02 | Performance | App cold start to an interactive "+1" button in < 2 s on a mid-range device. |
| NFR-03 | Reliability | Zero log loss: every log confirmed locally is eventually stored on the server, or the user is told why not (FR-LOG-07). |
| NFR-04 | Correctness | Scoring is deterministic: recomputing from the stored logs with the same rule version gives identical results. |
| NFR-05 | Security | HTTPS only (HSTS). Passwords hashed with Argon2id (bcrypt as fallback). Login is rate-limited (5 attempts / 15 min / account + IP). OWASP ASVS L1. |
| NFR-06 | Security | Tokens stored in the Keychain (iOS), EncryptedSharedPreferences/DataStore (Android) and an httpOnly SameSite=Strict cookie (web refresh token). |
| NFR-07 | Privacy | Data minimisation: no location, no contacts access, no analytics SDKs and no third-party trackers in v1. |
| NFR-08 | Privacy | All personal data is stored in the EU (IONOS DE). Push providers receive only device tokens and non-health text. |
| NFR-09 | Accessibility | WCAG 2.1 AA (web). VoiceOver/TalkBack labels, Dynamic Type/font scaling and reduced-motion support (celebrations simplified). |
| NFR-10 | Maintainability | Backend domain logic ≥ 90 % line coverage. Every FR with business logic has at least one test named with its ID. |
| NFR-11 | Portability | The backend runs on stock IONOS shared hosting with no extensions beyond the IONOS defaults (verified by the spike, HLD §11). |
| NFR-12 | Compatibility | The API is versioned (`/api/v1`). Old app versions keep working for ≥ 3 months after a breaking change. |

---

## 5. Legal & compliance requirements
- LEG-01: Impressum and Datenschutzerklärung (privacy policy), reachable from the web and in-app.
- LEG-02: Data processing agreement (AV-Vertrag) with IONOS.
- LEG-03: Consent records (FR-ACC-02) can be audited, and consent can be withdrawn (withdrawal = account deletion, since processing is impossible without it).
- LEG-04: The health content is informational only, not a medical device (no MDR claims). Disclaimer shown in onboarding and on the milestone screen.
- LEG-05: App Store and Play Store health-app policies, including age rating 18+ (tobacco reference).

---

## 6. Out of scope (v1)
- Public leagues, matchmaking with strangers, public profiles (*maybe later*)
- Free-text chat / DMs
- Sign in with Apple / Google (*later; Apple requires SiwA once any social login exists*)
- Vapes, e-cigarettes, nicotine pouches (*Open Question*)
- Payments, premium tier, in-app currency
- Wearables, Apple Watch / Wear OS apps, home-screen widgets (*widget "+1" is a strong later candidate*)
- Coaching content, lessons, medical advice
- Admin web panel (operations through DB/SSH in v1)
- Tablet-optimised layouts
- Custom avatar uploads (preset avatars only, so no moderation is needed)

---

## 7. What success looks like (proposed — confirm)
1. A new user can sign up, finish onboarding and log their first cigarette in **< 2 minutes**.
2. Logging takes **≤ 2 taps** from app open, online or offline.
3. Two test users can run a 1-week duel end to end on staging, and the result matches a hand calculation.
4. 100 % of the scoring/streak/XP rules in this SRS have passing tests that reference their FR IDs.
5. Every merge to `main` deploys backend + web to IONOS automatically, and the smoke tests pass.
6. Pilot: 5–10 friends use it for 4 weeks. ≥ 60 % still check in at least 5 days/week in week 4, and the median reduction % is > 0.

---

## 8. Open questions
| # | Question | Default if unanswered |
|---|---|---|
| OQ-1 | App name, branding, mascot, sound design (original assets needed) | Working title "smoke-detector"; commission or source royalty-free sounds |
| OQ-2 | Count vapes / other nicotine products? | No, cigarettes only in v1 |
| OQ-3 | Baseline: self-declared (current) vs. measured in a calibration week? | Self-declared + locked (FR-PLAN-02) |
| OQ-4 | Who owns the Apple Developer (99 $/yr) and Google Play (25 $) accounts? | — must be decided before the iOS phase |
| OQ-5 | Domain name and whether a staging subdomain is available | `app.<domain>` + `staging.<domain>` |
| OQ-6 | Timeline / team size | Unknown — the build plan in the HLD is sequenced, not dated |
| OQ-7 | Are XP values and taper steps (Appendix A) right? | Use the defaults and tune after the pilot (they are config, not code) |

---

## Appendix A — Default XP & level table (config, versioned)
| Event | XP |
|---|---|
| Check-in of a day | +10 |
| Day ≤ daily limit | +20 |
| Smoke-free day (checked in, 0 logs) | +40 (on top) |
| Check-in streak reaches 7 / 30 / 100 / 365 | +50 / +150 / +500 / +1500 |
| Duel won / draw / completed | +100 / +50 / +20 |
| League period rank 1 / 2 / 3 (≥ 3 participants) | +150 / +100 / +50 (×1 week, ×2 month, ×3 quarter, ×4 half-year) |
| Reaction sent | +2 (max 10 XP/day) |

Level *n* requires a cumulative `50 × n × (n + 1)` XP (L1 = 100, L2 = 300, L3 = 600, …).

## Appendix B — Initial badge set
First check-in · First smoke-free day · 7/30/100/365-day check-in streak · 7/30-day under-limit streak ·
Limit halved · Reached quit target · First duel won · 5 duels won · League champion (each period type) ·
Cheerleader (50 reactions sent).

## Appendix C — Health milestones (to be text-reviewed against sources: WHO, CDC)
| Time since last cigarette | Milestone (summary) |
|---|---|
| 20 minutes | Heart rate and blood pressure drop |
| 12 hours | Carbon monoxide level in the blood drops to normal |
| 2 weeks – 3 months | Circulation improves, lung function increases |
| 1 – 9 months | Coughing and shortness of breath decrease |
| 1 year | Excess risk of coronary heart disease is about half that of a smoker |
| 5 years | Stroke risk can fall to that of a non-smoker (5–15 years) |
| 10 years | Lung cancer death risk about half that of a smoker |
| 15 years | Coronary heart disease risk is that of a non-smoker |
