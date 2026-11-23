# Software Requirements Specification (SRS)

**Project:** Smoke Detector — a gamified "who smokes the least" competition app
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
The app is a Duolingo-style habit app. Each user logs every smoking session (cigarette, vape, shisha, …), follows a personal daily
limit that drops over time (optionally to zero), and earns XP, streaks, levels and badges. Users
compete fairly against **one specific friend (duel)** or **all their friends (league)**. The winner is
whoever reduced the most compared to their own baseline over a week, a month, 3 months or 6 months.
Positive sounds, animations and health milestones reward progress. Users are never punished for
logging honestly.

### 1.4 Definitions
| Term | Meaning |
|---|---|
| **Log** | One smoking **session** of any tobacco/nicotine product that is smoked or inhaled (cigarette, rolled cigarette, cigar/cigarillo, pipe, shisha round, vape/e-cig break, heated tobacco). Fields: timestamp, product type, count ≥ 1. All products count equally, 1 session = 1. |
| **Check-in** | The user confirms a local calendar day as complete. A day with 0 logs counts as smoke-free **only** after a check-in. |
| **Baseline (B)** | The user's current reference sessions/day, with one decimal place. Starts as the self-declared value at onboarding, then is recalculated weekly from the user's own logs (FR-PLAN-04). |
| **Daily limit** | Today's allowed maximum: `floor(B × f)`, where f depends on the taper speed (FR-PLAN-01). |
| **Reduction %** | `1 − actual / (B₀ × counted_days)` over a competition window. **B₀** is the baseline frozen at the window start, with a minimum of 1.0. Higher is better. Can be negative. |
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
- **Cannabis** and **non-inhaled nicotine** (snus, nicotine pouches, gum/patches) are not tracked.

### 2.3 Operating environment
- Backend: IONOS Webhosting Plus (shared hosting, Germany): PHP 8.4, MariaDB/MySQL, Apache, SFTP, limited SSH, cron.
- Web: current Chrome, Firefox, Safari and Edge (last 2 versions), desktop and mobile.
- iOS: 17+ (iPhone). Android: 8.0+ (API 26).

### 2.4 Constraints
- C-1: No persistent server processes (no WebSockets, no queue workers). Background work runs only through cron, and a web request cannot keep working after it has answered (verified on the server), so mail is queued and sent by cron.
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
| FR-ACC-01 | M | Users register with email, password (≥ 10 chars), display name, preset avatar, language and time zone. Registration **always** answers "check your inbox", whether or not the email already has an account, so nobody can find out who is registered. A new email gets a confirmation link. An email that already has an account gets a mail saying so, with a pointer to login and password reset. These mails, like all account mails, are sent by a background job and normally arrive within one or two minutes; the request itself never sends mail. |
| FR-ACC-02 | M | Registration requires (a) confirming age 18+, (b) explicit GDPR Art. 9 consent to processing smoking data, and (c) accepting the privacy policy. The app sends, for each consent, that it was accepted and the version of the text it showed. The server accepts it only when that is the version in force (otherwise the app must update) and stores that version with a timestamp. The apps can ask the server which versions are in force. |
| FR-ACC-03 | M | The email must be confirmed via the link (valid 24 h) **together with the password chosen at sign-up, before the account can be used**. Confirming logs the user in. A valid link with a wrong password confirms nothing and reveals nothing about the registration; the fifth wrong password makes the link useless. Until the confirmation a login is answered exactly like a wrong password, so the answer does not tell who is registered. A login with the right password mails a fresh confirmation link, and the apps offer "resend confirmation link" next to every login error. A registration that is not confirmed within 24 h of its newest link can no longer be confirmed and is deleted. A confirmation mail that cannot be delivered is tried again for as long as its link is valid and then dropped. |
| FR-ACC-04 | M | Login with email + password. Sessions persist across app restarts. Logout works per device. A wrong email, a wrong password and a registration that is not confirmed yet give the same answer. |
| FR-ACC-05 | M | Password reset via an emailed one-time link (valid 1 h). The request always answers the same way, whether or not the email has an account. A successful reset signs the user out on all devices at once: every session and every access token issued before stops working immediately. A reset mail that cannot be delivered within the hour is dropped. |
| FR-ACC-06 | M | Onboarding collects the starting baseline (sessions/day, 1–80), the goal mode (**reduce** to a target > 0, or **quit** = target 0), the taper speed (gentle / normal / fast) and the usual product type. A reduce target must be **below the current baseline**, at onboarding and when the goal is changed later. |
| FR-ACC-07 | M | The user can export all of their data as JSON (GDPR Art. 15/20), including the account mails that are still waiting to be sent (kind, time and failed tries). |
| FR-ACC-08 | M | The user can delete their account after re-entering the password. All personal data is removed **immediately** (well inside the 30 days GDPR allows) and all sessions end. Active duels end as forfeits: the other user keeps the duel in their list, without a winner and without XP. Proposed duels are deleted. |
| FR-ACC-09 | S | The user can change display name, avatar, language, time zone, reminder time and notification preferences. |

### 3.2 Logging & check-in (LOG)
| ID | Pri | Requirement |
|---|---|---|
| FR-LOG-01 | M | Log a session in **≤ 2 taps** from app open. The home screen has a prominent "+1" button that uses the last product type. A long-press or secondary button picks another product type. |
| FR-LOG-02 | M | Logging works **offline**. Logs are stored locally with a client-generated ID and synced automatically when online. Syncing is idempotent, so no duplicates are created. |
| FR-LOG-03 | M | A log can be back-dated or deleted, but only for days still inside the grace period (48 h after local midnight). The period ends by the clock, also when the server has not processed the day yet. A deleted log stays deleted. |
| FR-LOG-04 | M | The user checks in a day ("Done for today"), which confirms the count. Yesterday can be checked in until it is finalized. The check-in answers at once with the celebration and the projected streak and XP; these are provisional until the day is final. Logs of a checked-in day can still be corrected while the day is inside its grace period, and the check-in stays. |
| FR-LOG-05 | M | After each log, the home screen shows today's count vs. the daily limit and the remaining allowance. |
| FR-LOG-06 | M | Going over the limit shows a **neutral, encouraging** message and plays no negative sound (honor system). |
| FR-LOG-07 | M | Logs that arrive for already finalized days are rejected, and the user sees an explanation. |

### 3.3 Reduction plan (PLAN)
| ID | Pri | Requirement |
|---|---|---|
| FR-PLAN-01 | M | Daily limit = `floor(B × f)` with f = 0.9 (gentle) / 0.8 (normal) / 0.7 (fast). The three factors are part of the versioned rule table (Appendix A). In *reduce* mode the limit never goes below the target. In *quit* mode it reaches 0 naturally once B < 1/f. This replaces a fixed weekly taper, so the limit follows actual behaviour. |
| FR-PLAN-02 | M | Every baseline value is stored permanently with its validity date and inputs (baseline history). Past values are never recalculated (NFR-04). |
| FR-PLAN-03 | S | The user can switch from *reduce* to *quit* at any time without resetting anything. |
| FR-PLAN-04 | M | **Weekly baseline update** (Mondays 00:00 local, by cron): `W` = mean sessions/day over the **checked-in** days of the previous 7 days. `M` = the same over the previous **28** days. `B_new = round1(0.7·W + 0.3·M)`. Days without a check-in are excluded. |
| FR-PLAN-05 | M | Not enough data: if fewer than 4 of the previous 7 days are checked in, B stays unchanged that week. |
| FR-PLAN-06 | M | Increase cap: the baseline may rise per week by at most 10 % (rounded down to one decimal) **or 0.5 sessions/day, whichever is larger**, so it can't be inflated by a heavy (or faked) week. The 0.5 minimum keeps low baselines from getting stuck: 10 % of a baseline below 5 rounds to less than that, and a relapsing quitter at 0.0 could otherwise never rise. There is no cap on decreases. |
| FR-PLAN-07 | M | Competitions **freeze** each participant's baseline: a duel uses B₀ = the participant's B on the duel start date, and a league period uses B₀ on the period start (or join) date. For scoring, B₀ has a minimum of 1.0. |
| FR-PLAN-08 | S | The user sees the baseline history as a chart, and on Monday gets a celebration when B went down ("Your new normal: 7.4 → 6.8 🎉"). |
| FR-PLAN-09 | S | Personal stats show sessions per product type. Scoring ignores the product type. |

### 3.4 Gamification core (GAME)
| ID | Pri | Requirement |
|---|---|---|
| FR-GAME-01 | M | XP is awarded according to a server-side, versioned rule table (default values in Appendix A). |
| FR-GAME-02 | M | **Check-in streak** (primary 🔥): consecutive finalized days with a check-in. It does **not** break when the user goes over the limit, because honest logging is rewarded. |
| FR-GAME-03 | M | **Under-limit streak**: consecutive finalized days that were **checked in** with count ≤ limit. A checked-in day over the limit resets it to 0, and so does **every day without a check-in, even when a streak freeze is used** (an unconfirmed day is never an under-limit day, so skipping the check-in on a heavy day cannot save this streak). |
| FR-GAME-04 | M | Streak freeze: earn 1 each time the check-in streak reaches a multiple of 7 on a checked-in day (hold at most 2; a freeze earned while holding 2 is dropped without telling the user). When a day finalizes without a check-in, there is a check-in streak to protect and a freeze is held, one freeze is used automatically: the **check-in streak** stays unchanged (the day neither extends nor breaks it, and does not earn a freeze). The under-limit streak is not protected (FR-GAME-03). Without a freeze, both streaks reset to 0. In competitions a frozen day still counts as a missed day (FR-COMP-03). A later rule version must not lower the maximum number of freezes held without migrating the stored freezes. |
| FR-GAME-05 | M | Levels come from cumulative XP via a threshold table (Appendix A). |
| FR-GAME-06 | M | Celebration feedback (animation + positive sound + haptics on mobile) for: log saved (subtle), check-in, under-limit day, smoke-free day, streak extended, level up, badge earned, duel/league won. |
| FR-GAME-07 | M | Sounds can be muted in settings. Mobile respects the system silent mode. Sound is never the only feedback (accessibility). |
| FR-GAME-08 | M | Badges are awarded for defined achievements (Appendix B), e.g. first check-in, 7/30/100/365-day streaks, first smoke-free day, first duel won, limit halved. A badge is awarded once, when what justifies it is final (a day 48 hours after it ended, a duel when it is decided, a league when the rank is stored), and is never taken back. |
| FR-GAME-09 | M | Health milestones are based on time since the last logged session (Appendix C). The texts are written for smoking. For vape-only users the milestones are shown with a note that the evidence base differs. Each shows its source and a disclaimer. Without a logged session there is nothing to count from and no milestone is shown. The user chooses what friends see of the milestones: those from two weeks on (the default), none, or all. Friends see which milestones a user has reached at some time, worked out from final days and never taken back, so that neither a session of today nor a relapse can be read from the list. |

### 3.5 Friends (FRND)
| ID | Pri | Requirement |
|---|---|---|
| FR-FRND-01 | M | Each user has a personal invite code/link. Opening it sends a friend request, and the other user must accept. The user can replace the code by a new one at any time, and the old one stops working at once. If both users have asked each other, they are friends without a further step. |
| FR-FRND-02 | M | Users can list, remove and block friends. Somebody with an open friend request can be blocked too. Blocking hides both users from each other everywhere. Only the user who set a block sees it and can lift it, and lifting it does not bring the friendship back. Removing or blocking a friend ends a running duel of the two without a winner and deletes a proposed one. |
| FR-FRND-03 | M | Friends see only **aggregates**: reduction % per period (in whole percent), streaks, level, XP, which badges a friend holds (not when they were earned) and, as far as the friend's own setting allows (FR-GAME-09), health milestones reached. They do **not** see individual log timestamps, counts, limits or the baseline, and nothing precise enough to calculate these. A user who chose to show all health milestones also shows the two short ones (20 minutes, 12 hours); as they are worked out from final days and kept for good, they say only that at some time a logged session was followed by 12 hours without one. |
| FR-FRND-04 | M | Maximum 50 friends per user. A user's own open requests count towards the 50, when sending a request and when accepting one. Requests from others do not count, and the other user's number of friends never decides (it must not be revealed). |

### 3.6 Competitions (COMP)
| ID | Pri | Requirement |
|---|---|---|
| FR-COMP-01 | M | **Duel:** a user challenges one friend for a period of **1 week, 1 month, 3 months or 6 months**. The friend accepts or declines. Unanswered invitations expire after 72 h. |
| FR-COMP-02 | M | An accepted duel starts on the next local day and runs for exactly the period length (7 / 30 / 90 / 180 days). |
| FR-COMP-03 | M | **Duel winner** = higher reduction % over the duel days. A day without a check-in counts as the frozen baseline B₀ (0 % reduction), **or as the sessions logged that day if that is higher**, so skipping the check-in never scores better than checking in. Reductions are compared exactly (as fractions, never as rounded percentages). Tie-breakers in order: more smoke-free days, more check-ins, otherwise a draw. |
| FR-COMP-04 | M | The duel result is decided after the last day of **both** users is finalized. Both users are notified. |
| FR-COMP-05 | M | A user may have at most 1 open duel per friend (proposed or running, whoever proposed it) and 5 open duels in total. Running duels and own proposals count. Proposals from others count once they are accepted. |
| FR-COMP-06 | M | **League (all friends):** for each calendar-aligned period (ISO week, calendar month, calendar quarter, calendar half-year) there is a leaderboard of the user and their friends, ranked by reduction %. Users who join mid-period are rated over the days since joining. A participant with no rated day yet is listed without a rank. |
| FR-COMP-07 | M | A separate **weekly XP leaderboard** ranks friends by XP earned this ISO week (the classic Duolingo-style ranking). |
| FR-COMP-08 | M | Leaderboards show provisional standings during the period and final standings once the period is finalized. Winners get XP + a badge, but only for a result with a reduction above zero: who smoked as much as the baseline or more, or never checked in, has a rank and wins nothing (otherwise friends who all do nothing would share rank 1 and its reward). While a duel or a period runs, a user sees of each other user only whether that user is ahead, level or behind, not a percentage and not a rank among the others: figures that move every day could be calculated back to daily counts. Ranks and percentages of others are shown once the result is final, a percentage only for a result over at least 7 days. Everything shown about another user while a duel or a period runs, and the comparison itself, covers final days only (FR-LOG-03: a day is final 48 hours after it ended): a user's own open days can still be changed and must not move what is shown about others. |
| FR-COMP-09 | S | Duel detail screen: day-by-day comparison in four levels per day (smoke-free, well under the baseline, under, not under). Not percentages per day and not timestamps: an exact daily percentage could be calculated back to the baseline and the count. |

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
| FR-I18N-01 | M | All UI text, emails and push texts exist in German and English. The language is chosen per user. API validation errors carry a stable code per field, which the apps translate; the English message next to it is for developers only. |
| FR-I18N-02 | M | Dates and numbers are formatted per locale. All day logic uses the user's time zone. A change of the time zone, and a change of the plan (goal, taper speed), counts from the next local day (a time zone in which that day has already begun: from the day after), so that today never changes after the fact. |

---

## 4. Non-functional requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-01 | Performance | API p95 latency < 400 ms for standard endpoints on IONOS shared hosting at v1 scale. |
| NFR-02 | Performance | App cold start to an interactive "+1" button in < 2 s on a mid-range device. |
| NFR-03 | Reliability | Zero log loss: every log confirmed locally is eventually stored on the server, or the user is told why not (FR-LOG-07). |
| NFR-04 | Correctness | Scoring is deterministic: recomputing from the stored logs with the same rule version gives identical results. |
| NFR-05 | Security | HTTPS only (HSTS). Passwords hashed with Argon2id (no fallback to a weaker hash). Failed password checks (login, account deletion) are rate-limited so that the limit locks **the client that guesses, not the account**: 5 per 15 minutes per pair of email address and client address, 30 per 15 minutes per client address, and 50 per hour per email address over all clients (a successful password reset clears this last count). Registration, resend and reset request together are limited to 5 per hour per client and 3 per hour per pair of email address and client; an address is mailed at most 10 times per hour by them, above which the answer stays the same and nothing is mailed or stored. No web request talks to the mail server, so neither the response time nor an error reveals whether a mail was sent. Target: OWASP ASVS L1, checked against a written checklist with the accepted deviations before the pilot (build step 11). |
| NFR-06 | Security | Tokens stored in the Keychain (iOS), EncryptedSharedPreferences/DataStore (Android) and an httpOnly SameSite=Strict cookie (web refresh token). The access token is valid 15 minutes. The refresh token changes on every use and is valid 60 days from the last refresh; presenting a replaced refresh token ends that session (after the 10 seconds of tolerance for two tabs or a lost answer). A user has at most 20 sessions. |
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
- Weighting products against each other ("cigarette equivalents"), or separate limits per product
- Payments, premium tier, in-app currency
- Wearables, Apple Watch / Wear OS apps, home-screen widgets (*widget "+1" is a strong later candidate*)
- Coaching content, lessons, medical advice
- Admin web panel (operations through DB/SSH in v1)
- Tablet-optimised layouts
- Custom avatar uploads (preset avatars only, so no moderation is needed)

---

## 7. What success looks like (proposed — confirm)
1. A new user can sign up, confirm their email, finish onboarding and log their first session in **< 3 minutes** of their own time (the confirmation mail can take one or two minutes to arrive on top of that).
2. Logging takes **≤ 2 taps** from app open, online or offline.
3. Two test users can run a 1-week duel end to end on staging, and the result matches a hand calculation.
4. 100 % of the scoring/streak/XP rules in this SRS have passing tests that reference their FR IDs.
5. Every merge to `main` deploys backend + web to IONOS automatically, and the smoke tests pass.
6. Pilot: 5–10 friends use it for 4 weeks. ≥ 60 % still check in at least 5 days/week in week 4, and the median reduction % is > 0.

---

## 8. Open questions
| # | Question | Default if unanswered |
|---|---|---|
| OQ-1 | Branding & sound design (draft in [BRAND.md](BRAND.md)). Original assets are needed | Name decided: **Smoke Detector**. Trademark check pending |
| OQ-2 | ~~Count vapes / other nicotine products?~~ | **Decided:** every smoked/inhaled tobacco or nicotine product counts, 1 log = 1 session. No cannabis |
| OQ-3 | ~~How is the baseline set?~~ | **Decided:** onboarding value, then weekly `0.7·W + 0.3·M` with safeguards (FR-PLAN-04…07). Tune weights/cap after the pilot |
| OQ-4 | ~~Developer accounts~~ | **Decided:** owner owns both and pays the fees **after development**. Consequences: see HLD §11 (iOS push + TestFlight only after paying) |
| OQ-5 | Domain name and whether a staging subdomain is available | `app.<domain>` + `staging.<domain>` |
| OQ-6 | ~~Timeline / team size~~ | **Decided:** two-person team (owner + Claude Code), no fixed deadline. The HLD build plan is ordered by steps, not dates |
| OQ-8 | ~~League ranking: how are ties ranked, and do the duel tie-breakers apply?~~ | **Decided:** the tie-breakers of the duels (FR-COMP-03), then a shared rank (1, 1, 3). HLD §4.9 |
| OQ-9 | ~~Upper bound for sessions logged per day~~ | **Decided:** at most 50 per log entry and 300 per day (HLD §4.6) |
| OQ-10 | ~~A pending registration can be taken over by registering the same address again~~ | **Decided:** the confirmation page asks for the password of the sign-up and logs the user in. Five wrong passwords make the link useless (FR-ACC-03) |
| OQ-11 | ~~Login lockout: a stranger's wrong guesses lock the owner out~~ | **Decided:** lock the client that guesses, not the account (NFR-05). Remaining weakness: OQ-15 |
| OQ-12 | ~~Session refresh has no tolerance for two tabs or a lost answer~~ | **Decided:** for 10 seconds after a refresh, the replaced token is answered with the same new token |
| OQ-13 | ~~Which consent version is recorded?~~ How do existing users consent again when a text changes? | **Decided:** the app sends the version it showed and the server refuses any other (FR-ACC-02). Still open: asking existing users again after a text change. Decide with the legal pages (step 11) |
| OQ-14 | Password hardening beyond length: check against a list of common passwords, and mail the user after a password reset | Decide with the ASVS checklist (step 11). Default: both |
| OQ-15 | A "client" is an IPv4 address or an IPv6 /64 network. The limits per email address over all clients can be reached by a few clients: 3 client addresses reach the 50 failed logins per hour, and 10 lock the owner out of login and account deletion for the whole hour. 4 client addresses use up the 10 account mails per hour, so the owner's reset mail is left out for the rest of that hour | Accept for the pilot. Revisit with the ASVS checklist (step 11). Options: raise the per-address limits, count only clients that never logged in successfully, or exempt account deletion from the per-address rule |
| OQ-16 | Requests about a known and an unknown email address do different amounts of database work (resend, reset request, registration, login of an unconfirmed registration), so their response times can differ by a few milliseconds. Each probe of a known address also mails its owner, and an address can be probed at most 10 times per hour | Accept for the pilot. Revisit with the ASVS checklist (step 11). Option: do the same writes for an unknown address |
| OQ-17 | Somebody who knows an address with an unconfirmed registration can register it again (at most 10 times per hour). Each time the owner's confirmation link stops working until the owner registers again, and the 24 hours of the unconfirmed registration start again | Accept for the pilot: it follows from "the newest registration wins" (OQ-10), nobody gets into an account, and the mail limits bound it. Revisit with the ASVS checklist (step 11) |
| OQ-7 | Are XP values and taper steps (Appendix A) right? | Use the defaults and tune after the pilot (they are config, not code) |

---

## Appendix A — Default XP & level table (config, versioned)
| Event | XP |
|---|---|
| Check-in of a day | +10 |
| Day ≤ daily limit | +20 |
| Smoke-free day (checked in, 0 logs) | +40 (on top) |
| Check-in streak reaches 7 / 30 / 100 / 365 **for the first time** (new personal best only, so breaking and rebuilding a streak does not pay twice) | +50 / +150 / +500 / +1500 |
| Duel won / draw / lost but completed (forfeit: 0) | +100 / +50 / +20 |
| League period rank 1 / 2 / 3 (≥ 3 participants, and only for a result with a reduction above zero) | +150 / +100 / +50 (×1 week, ×2 month, ×3 quarter, ×4 half-year) |
| Reaction sent | +2 (max 10 XP/day) |

Level *n* requires a cumulative `50 × n × (n + 1)` XP (L1 = 100, L2 = 300, L3 = 600, …). New users start at level 0.

Day XP (check-in, under limit, smoke-free) is awarded once, when the day is finalized, and only for checked-in days. A smoke-free day therefore earns 10 + 20 + 40 = 70 XP. Reaction XP never exceeds the daily cap: a reaction that would cross it earns only the part that still fits.

All numbers in this appendix, the taper factors (FR-PLAN-01), the baseline rule numbers (FR-PLAN-04 to 07) and the freeze numbers (FR-GAME-04) live in one versioned rule table (`backend/config/rules/v1.php`). A version that has been used in production is never edited; new numbers go into a new version.

## Appendix B — Initial badge set
First check-in · First smoke-free day · 7/30/100/365-day check-in streak · 7/30-day under-limit streak ·
Limit halved · Reached target · First duel won · 5 duels won · League champion (each period type) ·
Cheerleader (50 reactions sent).

- **Limit halved:** a day that was checked in and kept at or under its limit, when that limit is at most half
  the limit of the first day of the plan.
- **Reached target:** a day that was checked in and kept at or under its limit, when the limit has arrived at
  the target of the plan (0 for "quit"). One good day while the limit is still on its way down is not it.
  The plan must have begun above that target: a limit that was the target from the first day, or a target
  that was set above the limit later, is not reached.
- **League champion:** rank 1 among at least three participants with a reduction above zero, one badge per
  period type.
- The streak badges follow the longest streak the user ever had.

## Appendix C — Health milestones (to be text-reviewed against sources: WHO, CDC)
| Time since last session | Milestone (summary) |
|---|---|
| 20 minutes | Heart rate and blood pressure drop |
| 12 hours | Carbon monoxide level in the blood drops to normal |
| 2 weeks – 3 months | Circulation improves, lung function increases |
| 1 – 9 months | Coughing and shortness of breath decrease |
| 1 year | Excess risk of coronary heart disease is about half that of a smoker |
| 5 years | Stroke risk can fall to that of a non-smoker (5–15 years) |
| 10 years | Lung cancer death risk about half that of a smoker |
| 15 years | Coronary heart disease risk is that of a non-smoker |
