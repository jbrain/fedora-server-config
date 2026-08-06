# Ampache "Jack Brain" theme — research & rebuild (2026-08-04)

## Background

The site previously ran a custom theme (`jackbrain`) on a much older Ampache version,
backed up at `/home/jack/backup/music.jackson-brain.com/themes/` on the server (old
`jackbrain` + the `reborn` base it forked from). The current container runs
`ampache/ampache:nosql7` (Ampache 7.9.8), which only ships the stock `reborn` theme.
This folder holds the research artifacts and the new theme built from them.

## What's here

- `jackbrain/` — the new theme, deployed via `../docker-compose.yml` (bind-mounted, since
  the image's own `/var/www/public/themes` isn't a declared volume and gets wiped on every
  container recreate).
- `jackbrain-old/` — full copy of the original (irreplaceable) custom theme, kept for
  reference/history.
- `favicons/` — recovered custom favicons for both `jackson-brain.com` (WordPress) and
  `music.jackson-brain.com` (Ampache), pulled from the pre-container native webroots
  (`/var/www/vhosts/*/favicon.ico` on the server). Deployed via `../../nginx/static/`.

## Findings from reviewing the old theme (thorough pass, 2026-08-04)

Diffing `jackbrain-old` against its `reborn` base (and against the current container's own
`reborn` theme) turned up **far less real customization than expected**:

- `default.css`: **zero real differences** — the diff was 100% formatting/comment-style
  noise from different Ampache eras (multi-line vs comma-joined selectors, license header
  text, `.1s` vs `0.1s`), not a single changed property value.
- `images/icons/*.png`: the files differ byte-for-byte from stock, but a pixel-level
  dominant-color check showed **identical colors** — these were just re-saved/re-compressed
  at some point (different PNG encoder/metadata), not recolored. No visual difference.
- `templates/light.css`: never actually customized — it's stock reborn-blue with a broken
  image reference (`ampache-reborn-blue.png`, a file that doesn't even exist in the old
  theme's own `images/`). Light mode was evidently never really used/finished.
- `templates/header.inc.php` / `footer.inc.php` / `show_search_form.inc.php`: full copies of
  stock Ampache core with no hardcoded custom content found (footer even checks the empty
  `custom_text_footer` preference, confirming nothing was hardcoded there). **Current Ampache
  no longer supports per-theme PHP template overrides at all** (`theme.cfg.php` is now just
  `name`/`base`/`colors`/`author`/`maintainer` — no template-file hooks), so these files
  couldn't be ported forward even if they had contained real customizations.
- `templates/dark.css`: this had the one *real* customization — an orange accent
  (`#ff9d00`/`#cc6200`/`#c24d00`). **The current stock "Reborn" Dark theme already uses this
  exact palette natively** (verified in the container's own `dark.css`) — Ampache's own theme
  evolved to the same colors independently. Nothing to port here either.
- The only two genuine, confirmed customizations: the **login-page logo** (a "melting Rubik's
  cube" image, `jackbrain-old/images/rubix.png`) and the **site title** ("musicbox").

## Why login logo / favicon aren't theme files anymore

In Ampache 7.9.8, `show_login_form.inc.php` resolves the logo via
`AmpConfig::get('custom_login_logo')` (falling back to `Ui::get_logo_url()`), rendered as a
plain `<img>` — not a theme CSS `background-image` like the old version. So branding assets
are wired up as **admin preferences** (`custom_login_logo`, `custom_logo`, `custom_favicon`,
`site_title`), pointing at files under `../assets/` (bind-mounted to
`/var/www/public/images/custom/` in the container), not theme files. See
`../docker-compose.yml` and the preference `UPDATE`s applied to `user_preference` (user -1)
for `theme_name=jackbrain`, `theme_color=dark`, `site_title=musicbox`,
`custom_login_logo=/images/custom/login-logo.png`.

## Bottom line

Given the above, `jackbrain/` is intentionally a near-identical fork of the current stock
`reborn` theme (same CSS, same icons) — there was nothing genuine left to re-theme visually.
It exists as a stable, decoupled home for *future* customizations (so they survive Ampache
upstream changing its own `reborn` theme), with the login logo restored via preference rather
than theme files.

## Follow-up refinements (2026-08-04, after initial deployment)

The rubix.png login logo is 465x282 (~1.649:1), not square like the stock Ampache mark, which
needed real CSS changes beyond the initial "near-identical fork" — all in
`jackbrain/templates/default.css` (structural/layout, not color, so it applies to both Dark
and Light):

- **Login/register/lost-password/activation pages**: the stock theme hardcodes 3-4 separate
  128x128 square boxes (`#loginPage #header`/`#registerPage #header`, `#loginPage
  #logo`/`#registerPage #logo`, `#header #logo img`, `#mobileheader #logo img`). Changed all
  of them to `width:100%; max-width:465px; height:auto` (container) / `width:100%; height:auto;
  max-width:465px` (img) so the logo renders at **full native size**, scaling down
  responsively on narrow viewports instead of being squashed into a square or overflowing.
- **Main app header logo** (`#header #headerlogo img`, the *other* logo — used while logged
  in): deliberately did NOT touch the container (`#header #headerlogo`, still
  `float:left;width:20%;overflow:hidden`) since it isn't square itself and changing it would
  fight the header's float layout. Only dropped the img's hardcoded `width:64px` (now
  `width:auto`) so it isn't squashed — the 20%-wide container has ample room for the
  resulting ~106px-wide image at `height:64px`.
- **`#loginInfo`** (logged-in username/logout link in the main header) was hardcoded
  `left:80px`, which is exactly where the old 64px-square logo ended — now overlapped by the
  wider ~106px non-square logo. Moved to `left:120px`.
- **Debug/installer pages** (Access Denied, Permission Denied, test/installer error pages) use
  a bare Bootstrap `.navbar-brand img` with **no sizing at all** in stock Ampache — harmless
  with the old square logo (small enough to not break the 70px navbar) but would render our
  465x282 image at full native size otherwise. Added `.navbar-brand img { height:40px;
  width:auto; ... }`.
- **Home page "Browse Ampache..." bar**: non-functional/redundant on the home page (the same
  underlying component IS the real, working category tab bar on every Browse listing page —
  don't hide it globally). Its home-page wrapper is uniquely `#browse_header` (confirmed by
  checking the Browse action templates don't use that wrapper) — added `#browse_header {
  display: none; }`.

## Branding preferences beyond the theme itself

`custom_logo` (main header + debug pages) and `custom_favicon` (renders an actual `<link
rel="icon">` via `Ui.php`'s `show_custom_style()`) also needed setting — not just
`custom_login_logo`. All are captured in `../theme-preferences.sql` for disaster-recovery
reproducibility (see `../README.md`). `custom_favicon` and the nginx-served static
`/favicon.ico` (`../../nginx/static/music-favicon.ico`) are kept as **identical copies** of
the same generated icon so both possible code paths serve the same image — see
`favicons/README` note below.

## Favicon generation

`favicons/ampache-favicon.ico` and `favicons/wordpress-favicon.ico` are the **original**
recovered pre-container favicons (kept for history). The **current live** Ampache favicon
(`../../nginx/static/music-favicon.ico`, copied to `../assets/favicon.ico`) was generated
fresh from `jackbrain-old/images/rubix.png`: center-cropped to a square (using the smaller
dimension, since the source is wider than tall) and re-encoded as a proper multi-resolution
`.ico` (16/32/48/64/128/256px) for consistent branding with the login/header logo, rather than
reusing the old recovered Ampache favicon.

## Known residual inconsistency to check periodically

`homedash_trending` (the "Home Dashboard" plugin's Trending-box toggle) is a genuinely
**per-user** preference, unlike `custom_logo`/`custom_login_logo`/`custom_favicon` (which
became system-wide as of Ampache's `Migration773001`). Ampache's admin Preferences UI has an
"apply to all users" option when editing it, but in practice (2026-08-04) it only updated 2 of
4 accounts (`admin`, `jack` — not `music-assistant`, `copilot-admin`). If disabling Trending
site-wide matters, verify per-account rather than trusting that checkbox.

