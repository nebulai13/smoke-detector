# Smoke Detector

Gamified "who smokes the least" app: PHP 8.4 + Slim backend on IONOS shared hosting, React web client,
SwiftUI iOS client, Compose Android client. Read these before working:

- `docs/SRS.md`: what the app must do (requirement IDs `FR-…`, `NFR-…`)
- `docs/HLD.md`: how it is built (architecture, data model, deployment, test strategy, build plan)
- `docs/BRAND.md`: name, mascot, colours, sounds, tone
- `server.info` (git-ignored): verified facts about the IONOS server

## Working agreement

- **Test first (TDD).** Write the failing test before the code. Test names carry the requirement ID they
  verify, e.g. `test_FR_COMP_03_missing_checkin_counts_as_baseline`.
- **Verify first.** Before starting any task, state how you'll verify it's correct.
- **Report after.** After finishing, run that verification and report the actual results, including failures.
- **Spec changes go in the docs.** If a requirement or design decision changes, update the SRS/HLD in the
  same change.
- **Hot zones: ask before touching.** Before changing any of these, stop, ask, and explain the blast
  radius (what depends on it and what could break):
  - Auth: passwords, tokens, sessions, rate limiting
  - GDPR: consent records, data export, account deletion, anything that changes what friends can see
  - Scoring rules: `backend/config/rules/`, baseline, XP, streak, duel and league logic that is already
    released (changes alter users' results)
  - Database migrations, and any direct change to the production database
  - Deployment, cron and `.htaccess`
  - Secrets: `.env`, `server.info`, `shared/config/local.php`. Never commit or print them
  - Anything on the IONOS server outside `/home/www/smoke-detector` and `/home/www/public/smoke-detector`

## Server rules (IONOS)

- SSH alias `i0n0s`. **Batch commands into one SSH connection.** Many connections in a short time get the
  IP blocked for 10–15 minutes.
- Always call `php8.4` explicitly (plain `php` is 8.3).
- Every DB connection sets `time_zone='+00:00'`, a strict `sql_mode` and `utf8mb4` (server defaults are unsafe).
- The database is reachable only from the webspace.
