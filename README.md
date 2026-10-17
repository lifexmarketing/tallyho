# Tallyho Form Tracking

A WordPress plugin that pushes **one deduplicated dataLayer event per genuine Gravity Forms submission**, so GTM can route it to GA4, Google Ads and Meta without confirmation page views or double counting.

- **Spam is excluded server-side.** Gravity Forms still shows a confirmation to entries it marks as spam (Akismet, reCAPTCHA v3 scoring, CleanTalk when it marks spam). This plugin checks the entry status before telling the browser anything.
- **Privacy signals are honored.** Global Privacy Control and Do Not Track are checked on the submission request (server) and again in the browser, using the shared LifeX `isTrackingOptOut()` helper.
- **Every confirmation type is covered:** AJAX on-page, non-AJAX on-page, and redirect to a thank-you page.
- **Cache-safe.** Nothing per-visitor is printed into page HTML. Conversion data travels only on the submission response (a POST, never page-cached).
- **One event, one ID.** The entry ID becomes `event_id` (`gf_<site key>_<entry id>`, where the site key is a short hash of the site's domain so IDs never collide across sites sharing a pixel), used for Meta Pixel/CAPI deduplication and Google Ads transaction IDs, and as a per-session guard so refreshes, back-button visits and duplicate listeners never fire twice.

## Requirements

- WordPress 6.0+, PHP 7.4+
- Gravity Forms 2.5+ (for the Form Settings section)
- GTM installed on the site

## Install

1. Download `tallyho.zip` from the latest [release](https://github.com/lifexmarketing/tallyho/releases/latest) (not the "Source code" zip).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the zip, and activate.
3. Updates then appear on the normal Plugins and Dashboard → Updates screens. "Enable auto-updates" works too.

The plugin includes the shared LifeX privacy helper. If the standalone **LifeX Tracking Opt-Out Helper** plugin is also active, both are guarded, so only one definition of `isTrackingOptOut()` is ever used.

## Gravity Forms setup

Each form gets a **LifeX Conversion Tracking** section under **Form Settings**:

- **Push a conversion event when this form is submitted**: on by default. Turn it off for forms that aren't conversions (careers, support). Unmapped forms send the neutral `tallyho_form_submit` event, so they never count as leads unless you map them.
- **Conversion name**: optional GA4 event name, such as `estimate_form`. It travels with the form when the form is duplicated or the site is migrated, where form IDs can change.

Recommended: use **AJAX** with a **Text** confirmation where the client doesn't need a thank-you page. It's the cleanest path and avoids browser resubmission prompts on refresh.

## What gets pushed

```js
{
  event: 'tallyho_conversion',
  lx_source: 'gravityforms',
  form_id: '3',
  form_title: 'Request an Estimate',
  conversion_name: 'estimate_form',   // '' if not set on the form
  entry_id: '1542',
  event_id: 'gf_3f9a1c_1542',
  delivery: 'ajax'                    // 'ajax' | 'page' | 'redirect'
}
```

The site key (`3f9a1c` above) comes from the site's host name. If a site moves domains and you need IDs to stay stable, set it with:

```php
add_filter( 'tallyho_site_key', fn() => 'keystone' );
```

No form field values or personal data are pushed.

## GTM setup

1. **Admin → Import Container**, choose `gtm/tallyho-gtm-import.json`, pick a new or existing workspace, and choose **Merge → Rename conflicting tags, triggers, and variables**.
2. That adds:
   - Trigger **CE - tallyho_conversion**
   - Data Layer Variables for each field above
   - **LKP - form_id to conversion event**: map form IDs to event names for forms without a Conversion name. Replace the `999999` example row. Unmapped forms fall back to `tallyho_form_submit`, a neutral name that won't collide with GA4's own `form_submit` and won't count as a lead until you mark it.
   - **CJS - conversion event name**: the form's Conversion name if set, otherwise the lookup.
3. Add or repoint your tags to the trigger:
   - **GA4 Event**: Event Name `{{CJS - conversion event name}}`. Useful parameters: `form_id`, `form_title`, `event_id`.
   - **Meta Pixel** `Lead`: pass `{{DLV - event_id}}` as the `eventID` so it deduplicates against CAPI.
   - **Google Ads Conversion**: Transaction ID `{{DLV - event_id}}`.
4. Remove or pause old confirmation page view triggers for the same forms, or you'll count twice.
5. Preview, test (below), publish.

## Testing a site

Add `?tallyho_debug=1` to any URL once. The browser console then logs every push and every skip with its reason (`privacy signal`, `already sent`, and so on). `?tallyho_debug=0` turns it off. Debug state is stored in the browser only, so it is cache-safe.

1. **Genuine lead:** submit a test entry. Confirm one `tallyho_conversion` in GTM Preview and the GA4/Meta/Ads tags fire once.
2. **Refresh / back button:** reload the confirmation or go back to it. No second push.
3. **Spam:** submit with the name `akismet-guaranteed-spam` on an Akismet site. The entry lands in Spam and nothing is pushed.
4. **Privacy signal:** submit from a browser with Global Privacy Control on (Brave, Firefox setting, or an extension). Nothing is pushed.
5. **Redirect forms:** confirm the push happens on the thank-you page and the `tallyho_conv` cookie is gone afterwards.
6. **Cached sites:** run the cache-tracking-check skill on the form page and thank-you page after rollout.

## Notes and limits

- **Redirect confirmations need headers not yet sent.** If another plugin outputs content before Gravity Forms redirects, the cookie can't be set and that conversion is skipped. Debug mode won't see it, so test each redirect form once.
- **Payment forms** (Stripe Checkout, PayPal) that leave the site and come back: test them before relying on them. A slow return can exceed the 2 minute cookie, and Gravity Forms' handling of abandoned payments varies by add-on.
- **Redirects to another domain** won't carry the cookie. Keep thank-you pages on the same site (subdomains work only if `COOKIE_DOMAIN` covers them).
- **Redirect cookie lifetime** is 2 minutes, readable by JavaScript on purpose, `SameSite=Lax`, `Secure` on HTTPS. It holds the form ID, entry ID, conversion name and form title, never field values.
- **Script delay plugins.** The plugin excludes its script from WP Rocket's "Delay JavaScript execution" automatically. For FlyingPress, LiteSpeed, Perfmatters and similar, add `tallyho` and `isTrackingOptOut` to their delay exclusions. Exclude GTM itself too, or the dataLayer event waits until the visitor interacts. AJAX confirmations are detected even if jQuery is delayed.
- **Non-AJAX refresh.** Refreshing a non-AJAX confirmation makes the browser offer to resubmit. If the visitor accepts and Gravity Forms creates a new entry, that is a new entry ID and counts as a new submission.
- **Opted-out visitors** still submit normally. Only the conversion push is suppressed. Expect attributed conversions to dip slightly after rollout. That's the policy working, not a tracking bug.

## Developer hooks

```php
// Final say on whether a submission pushes a conversion.
add_filter( 'tallyho_should_track', function ( $track, $form, $entry ) { return $track; }, 10, 3 );

// Adjust the payload, or return a non-array to skip it.
add_filter( 'tallyho_payload', function ( $payload, $form, $entry ) { return $payload; }, 10, 3 );
```

In the browser, `window.tallyho.push({...})` uses the same guard and opt-out check, for other LifeX integrations.

## Releasing a new version

**One-time repo setup (do this before the first release):** every site installs whatever zip a release publishes, so protect it.

1. **Settings → Rules → Rulesets:** add a tag ruleset for `v*` that restricts creation, update and deletion to admins.
2. **Settings → Environments → release:** add yourself (and anyone else who should approve) as required reviewers. The release job waits for approval before publishing.
3. Protect `main` so changes arrive by pull request.

The workflow also refuses to release a tag that isn't on `main`, and third-party actions are pinned to commit SHAs.

**Each release:**


1. Update `Version:` in the plugin header **and** `TALLYHO_VERSION` in `tallyho.php`.
2. Add a `## [x.y.z]` section to `CHANGELOG.md`.
3. Commit to `main`, then tag and push:
   ```bash
   git tag v1.0.1 && git push origin v1.0.1
   ```
4. The **Release** workflow lints, runs the tests, checks the version numbers match the tag, builds `tallyho.zip`, and publishes the GitHub release.
5. Sites see the update within about 6 hours, or right away after **Dashboard → Updates → Check again**.

Draft and pre-release releases are ignored by sites, so you can stage a release as a pre-release first and test it by uploading the zip manually.

## License

GPL-3.0-or-later (see LICENSE)
