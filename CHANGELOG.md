# Changelog

## [1.0.0] - 2026-10-17

- Pushes one `tallyho_conversion` dataLayer event per genuine Gravity Forms submission.
- Skips entries Gravity Forms marks as spam (Akismet, reCAPTCHA v3 scoring, CleanTalk when it marks spam).
- Honors Global Privacy Control and Do Not Track, server-side at submission and in the browser.
- Covers AJAX and non-AJAX on-page confirmations and redirect confirmations.
- Deduplicates on a site-scoped entry ID, also sent as `event_id` for Meta Pixel/CAPI and Google Ads.
- Per-form toggle and conversion name in Form Settings.
- Updates from GitHub Releases through the standard WordPress update screens.
