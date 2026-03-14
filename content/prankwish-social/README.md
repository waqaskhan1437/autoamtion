# PrankWish Social Library

This folder holds the refillable PrankWish social-copy queue.

## Files

- `library.json`: the active queue used by the app
- `library.template.json`: a sample refill format
- `tagline-library.json`: fallback overlay taglines if Gemini is unavailable

## How The Queue Works

1. The app reads packs from `library.json` in order.
2. It uses `Cycle 1 -> Pack 1`, `Cycle 2 -> Pack 2`, and so on.
3. Titles stay service-based and brand-aware.
4. Occasion coverage should live in descriptions, keywords, and hashtags.
5. Gemini can rewrite titles, descriptions, and taglines per video, but `library.json` remains the safe fallback seed.
6. When you replace the queue with fresh packs, change `library_key`.

If `library_key` changes, the app starts again from pack `1`.

`tagline-library.json` is separate and is only used if AI generation is unavailable.

## Required Format

Each pack must have:

- `id`: unique string, for example `pw-pack-001`
- `theme_key`: short slug, for example `mother_moments`
- `theme_name`: readable label
- `search_intents`: array of search phrases
- `keywords`: array of evergreen keywords
- `platforms`: object with all of these keys:
  - `youtube`
  - `tiktok`
  - `instagram`
  - `facebook`
  - `twitter`
  - `threads`
  - `linkedin`
  - `pinterest`
  - `bluesky`

Each platform block should contain:

- `title`
- `description`
- `hashtags`
- `tags`
- optional `keywords`
- optional `call_to_action`

## Content Rules

- Keep titles generic and service-based.
- Do not start titles with occasion phrases like `Happy Birthday Mother`.
- Put occasion and relationship intent into descriptions, keywords, and hashtags.
- Mention `PrankWish.com` in every description.
- Keep the 3-step order flow clear:
  - choose a style
  - send your custom script
  - receive digital delivery on email or WhatsApp

## Refill Steps

1. Edit `library.json` or replace it with a fresh file based on `library.template.json`.
2. Change `library_key` to a new value, for example `prankwish-service-library-v2`.
3. Validate the file:

```powershell
C:\xampp\php\php.exe scripts/validate-prankwish-social-library.php
```

4. If you want the app to generate a fresh 100-pack queue again:

```powershell
C:\xampp\php\php.exe scripts/generate-prankwish-social-library.php prankwish-service-library-v2
```

5. If you want to rebuild the fallback tagline file:

```powershell
C:\xampp\php\php.exe scripts/generate-prankwish-tagline-library.php
```
