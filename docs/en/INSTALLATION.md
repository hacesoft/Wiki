[English](INSTALLATION.md) · [Čeština](../cz/INSTALACE.md)

# Installation and upgrades

## Requirements

Home Wiki supports Nextcloud 35, PHP 8.3–8.5 and MariaDB/MySQL. The PHP ZIP extension is required. The app also requires Shared App Core (`hc_shared_app_core`) version 0.18.8 or newer.

Install and enable Shared App Core before Home Wiki.

## Install from the source project

The application code resides in `src/`, and its technical ID is `hc_homewiki`. Do not clone the entire repository directly into Nextcloud `custom_apps/`.

On Synology NAS / Docker, clone the repository outside `custom_apps` and run:

```sh
git clone https://github.com/hacesoft/Wiki.git
cd Wiki
sudo sh install.sh
```

Alternatively deploy **only the contents of `src/`** to `custom_apps/hc_homewiki/`, set correct ownership/permissions and, as the web-server user, run `php occ app:enable hc_homewiki`.

Nextcloud automatically runs the migration in `lib/Migration/` and creates the required tables and indexes. No manual database setup is required.

On a production Nextcloud 35 instance, the developer-only `migrations:status` and `migrations:migrate` commands are not normally available. Verify the installation instead with:

```bash
sudo -u www-data php occ config:app:get hc_homewiki installed_version
sudo -u www-data php occ app:list --enabled
```

## Manual upgrade

For Synology NAS / Docker, an optional `install.sh` script is available; run it from the repository root outside `custom_apps`.

Create a native Home Wiki export and a regular Nextcloud backup before an upgrade. Replace the application code while keeping the directory name `hc_homewiki`. Nextcloud runs pending migrations during application updates.

Do not delete the user's `HomeWiki/` directory or application database tables when replacing application code. Those are user data, not installation files.

## Background jobs

Automatic Trash retention uses a standard Nextcloud background job. A production Nextcloud installation should use system cron rather than AJAX-only background jobs.

## Uninstalling

Disabling the app does not by itself delete the user's Wiki content. Create a native export first if the data may be needed later. Remove content only through the explicit Home Wiki data-management functions or deliberate Nextcloud administration.
