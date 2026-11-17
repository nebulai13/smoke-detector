# Brand — Smoke Detector (draft v0.1)

**Status:** proposal for review — 2026-11-17. All assets must be original or properly licensed.

## Idea in one line
A smoke detector that has stopped nagging and started cheering: instead of the annoying low-battery beep,
**Dex chirps happily** every time you make progress.

## Name
**Smoke Detector.** It is short, makes people smile, and the mascot is the logo.
- Store title: **"Smoke Detector – Smoke Less Together"** (DE: *"Smoke Detector – Gemeinsam weniger rauchen"*).
  The subtitle carries the meaning, so store searches for "rauchen" / "quit smoking" still find it.
- Trademark/store check is still pending (DPMA/EUIPO, App Store, Play Store).

## Mascot: Dex
- A round ceiling smoke detector with an LED "face" (two dot eyes + a ring-light mouth) and tiny arms.
- **Moods** (needed as animation states): idle (slow LED pulse), happy chirp (logged / check-in), proud
  (under limit), party (level-up, duel won), cosy (smoke-free day, fresh-air sparkles around it), encouraging
  (over limit: a nod and a thumbs up, **never** sad or angry), sleepy (quiet hours), nudging (friend nudge).
- Dex never sounds like a real alarm, never flashes red and never shames.

## Colours (tokens; WCAG AA contrast to be validated during implementation)
| Token | Light | Dark | Use |
|---|---|---|---|
| `--air` (primary) | `#12A07C` | `#3DD4AC` | Primary actions, "+1", progress |
| `--air-deep` | `#0B7A5E` | `#7BE6C8` | Text on light mint, pressed states |
| `--sky` | `#3BA9E6` | `#6CC4F2` | Friends, social, links |
| `--ember` | `#FF7A2F` | `#FF9A5C` | **Streaks only** (🔥) |
| `--gold` | `#FFC23D` | `#FFD36E` | XP, levels, badges |
| `--ink` | `#1C2A33` | `#E8F1F0` | Body text |
| `--cloud` (bg) | `#F5FBF9` | `#0E191E` | App background |
| `--neutral-over` | `#8A99A3` | `#7A8A94` | Over-limit states (neutral, **not red**) |

Avoid grey "smoke" imagery, ashtrays, glowing cigarettes and anything that makes smoking look attractive.

## Typography
- **Nunito** (rounded, friendly, free via Google Fonts) for UI. Use ExtraBold for numbers, streak counters and headlines.
- Native apps may use the system fonts (SF Pro Rounded / Roboto) to load faster. The rounded style is
  what matters, not the exact font.

## Sound design ("the chirp family")
Short, bright and soft. Based on a two-tone chirp, ≤ 600 ms except for the big moments.
| Event | Sound |
|---|---|
| Log saved | one soft chirp |
| Check-in | two rising chirps |
| Day under limit | three-note chirp arpeggio |
| Smoke-free day | chirp + soft "fresh air" whoosh |
| Streak extended | ember crackle + chirp |
| Level up / badge | chirp melody + sparkle chime (≤ 1.5 s) |
| Duel / league won | short chirp fanfare (≤ 2 s) |
| Over limit | **no sound**, Dex nods |
| Nudge received | a playful "beep-beep?" (questioning, not alarming) |

Rules: never use a real smoke-alarm screech. Respect mute and silent mode. Sound is never the only feedback.

## Voice & tone
Casual (DE: **du**), warm and a bit cheeky, never preachy and never shaming.
| Moment | EN | DE |
|---|---|---|
| Logged | "Logged. Honesty counts 💪" | "Eingetragen. Ehrlich währt am längsten 💪" |
| Over limit | "Above today's limit — tomorrow is a fresh start." | "Über dem Limit – morgen ist ein neuer Tag." |
| Smoke-free day | "Zero today! Dex is doing a happy dance." | "Heute null! Dex tanzt vor Freude." |
| New baseline | "Your new normal: 7.4 → 6.8 🎉" | "Dein neuer Normalwert: 7,4 → 6,8 🎉" |
| Streak at risk | "Your 🔥 12-day streak wants a check-in." | "Deine 🔥 12-Tage-Serie wartet auf deinen Check-in." |
| Nudge | "Anna gave you a beep-beep 👀" | "Anna hat dir ein Piep-Piep geschickt 👀" |

## App icon
Dex front view on a mint background, with the LED ring smiling. It works as a flat shape at 29 px. No cigarette in the icon (store policy + brand).
