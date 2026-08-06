-- Re-applies the jackbrain theme + branding preferences to the ampache database.
--
-- These are DB-stored preferences (table `preference`/`user_preference`, user -1 = the
-- system-wide default used by Migration773001+ for custom_logo/custom_login_logo/
-- custom_favicon, and by convention for theme_name/theme_color/site_title too). They live
-- in the shared-mariadb container/volume, NOT in the ampache app container, so they
-- normally survive container recreates and image updates on their own. This file exists
-- purely as a DISASTER-RECOVERY / fresh-install script — run it once after restoring the
-- `ampache` database from an older backup, or after a brand-new install, to reapply the
-- site's actual branding instead of Ampache's stock defaults.
--
-- Usage (from the server, once shared-mariadb + ampache are both up):
--   sudo sh -c 'source /opt/shared-mariadb/.env && podman exec -i \
--     -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" shared-mariadb mariadb -u root ampache \
--     < ampache/theme-preferences.sql'
--
-- Requires ../assets/{login-logo.png,favicon.ico} and ../themes/jackbrain to already be
-- bind-mounted (see docker-compose.yml) — this script only sets the DB pointers to them.

UPDATE user_preference up JOIN preference p ON p.id = up.preference
    SET up.value = 'jackbrain' WHERE p.name = 'theme_name' AND up.user = -1;

UPDATE user_preference up JOIN preference p ON p.id = up.preference
    SET up.value = 'dark' WHERE p.name = 'theme_color' AND up.user = -1;

UPDATE user_preference up JOIN preference p ON p.id = up.preference
    SET up.value = 'musicbox' WHERE p.name = 'site_title' AND up.user = -1;

-- custom_logo (main app header + show_denied/show_test debug pages) and custom_login_logo
-- (login/register/lost-password/activation pages) both point at the same rubix.png-derived
-- image for a consistent look everywhere Ui::get_logo_url()/custom_login_logo is used.
UPDATE user_preference up JOIN preference p ON p.id = up.preference
    SET up.value = '/images/custom/login-logo.png' WHERE p.name = 'custom_logo' AND up.user = -1;

UPDATE user_preference up JOIN preference p ON p.id = up.preference
    SET up.value = '/images/custom/login-logo.png' WHERE p.name = 'custom_login_logo' AND up.user = -1;

-- Renders as <link rel="icon"> in <head> (Ui.php show_custom_style()); nginx also serves
-- /favicon.ico directly from nginx/static/music-favicon.ico (same image) as a second,
-- independent path — see nginx/conf.d/music.jackson-brain.com.conf.
UPDATE user_preference up JOIN preference p ON p.id = up.preference
    SET up.value = '/images/custom/favicon.ico' WHERE p.name = 'custom_favicon' AND up.user = -1;

-- Verify:
SELECT p.name, up.value FROM preference p JOIN user_preference up ON up.preference = p.id
    WHERE p.name IN ('theme_name','theme_color','site_title','custom_logo','custom_login_logo','custom_favicon')
    AND up.user = -1;

-- NOT included above (personal taste, not branding): the "Home Dashboard" plugin's
-- `homedash_trending` preference (hides the home-page "Trending" box). Unlike the six
-- preferences above, this one is genuinely PER-USER, not system-wide (Migration773001 only
-- converted custom_logo/custom_login_logo/custom_favicon to system-wide, not this plugin's
-- prefs) — each account (admin, jack, music-assistant, copilot-admin, ...) has its own row
-- and must be set individually, e.g.:
--   UPDATE user_preference SET value='0' WHERE preference=(SELECT id FROM preference WHERE name='homedash_trending');
-- Ampache's admin Preferences UI has an "apply to all users" option when editing this, but
-- it did not actually reach every account in practice (2026-08-04) — verify per-user after
-- using it, don't assume it's global.
