[English](INSTALLATION.md) · [Čeština](../cz/INSTALACE.md)

# Installation and upgrades

## Requirements

Home Wiki supports Nextcloud 35, PHP 8.3–8.5 and MariaDB/MySQL. PHP must include ZIP support. HC Shared App Core (`hc_shared_app_core`) 0.18.8 or newer is required.

Install and enable HC Shared App Core before Home Wiki.

## 1. Install from the Nextcloud App Store

If Home Wiki is available in your instance's App Store, open **Apps**, find **Home Wiki**, and install/enable it. Future updates are then offered through the standard Nextcloud app-management workflow.

This is the recommended method for normal users and administrators.

## 2. Manual installation from a release archive

Manual installation does not require `npm`, Composer, or building source code on the production server.

1. Download the ready-to-run `hc_homewiki-<version>.tar.gz` or `hc_homewiki-<version>.zip` archive from an official project release.
2. Extract it. The root directory must be named exactly `hc_homewiki`.
3. Place `hc_homewiki` in a writable Nextcloud app directory, commonly `custom_apps/` or another directory configured in `apps_paths`.
4. Set ownership and permissions so the Nextcloud web process can read the app code.
5. Run `occ` as the HTTP/web user and enable the app.

Example for a common Linux installation:

```sh
cd /var/www/nextcloud
sudo -u www-data php occ app:enable hc_homewiki
```

The HTTP user varies by distribution (`www-data`, `apache`, `http`, `wwwrun`, and others).

Docker example:

```sh
docker exec -u www-data <nextcloud-container> php /var/www/html/occ app:enable hc_homewiki
```

The container name and `occ` path may differ in your deployment.

Nextcloud runs application migrations automatically during installation/activation. Do not create Home Wiki database tables manually.

## 3. Automated full-source installation with `install.sh`

The full-source package also includes an installer for Docker-based Nextcloud deployments. This path is useful for administrators or development/service deployments that need a fast, controlled upgrade without manually copying runtime files.

### Requirements

- a Linux/Unix shell with `sh`,
- `root` privileges through `sudo`,
- the `docker` command,
- a running Nextcloud web container containing `/var/www/html/occ`,
- HC Shared App Core at the required version.

The installer first checks the common container names `nextcloud-app` and `nextcloud-cron`; if they are not present, it attempts to discover other running Nextcloud containers automatically.

Extract the full-source archive, change to its root directory, and run:

```sh
sudo sh install.sh
```

The installer reports each step as it runs. Before deployment it validates metadata and compatibility, checks Shared App Core, measures and backs up the previous runtime, validates PHP, deploys the runtime to the web/cron container, validates the database structure, and enables the app. If deployment fails, the transactional installer attempts to restore the previous runtime and application state.

The previous runtime backup is stored under `hc-core-deployment-backups/` inside the Nextcloud data directory. The user's `HomeWiki/` data directory is not part of the runtime and is not removed by this installer.

This script is an additional installation path for Docker deployments. It is not required for Nextcloud App Store installation or for manual installation of the ready release archive.

## 4. Verify the installation

Use `occ` to verify that the app is enabled and to read the version recorded by Nextcloud:

```sh
sudo -u www-data php occ app:list --enabled
sudo -u www-data php occ config:app:get hc_homewiki installed_version
```

## 5. Manual upgrade

Create a native Home Wiki export and a normal Nextcloud backup before upgrading.

1. Disable Home Wiki:

```sh
sudo -u www-data php occ app:disable hc_homewiki
```

2. Replace **only the application code directory** with the new `hc_homewiki` directory from the release archive.
3. Do not delete the user's `HomeWiki/` directory or application database tables.
4. Enable the app again:

```sh
sudo -u www-data php occ app:enable hc_homewiki
```

5. Verify `installed_version` as described above.

Nextcloud applies required application migrations when the app is enabled/upgraded.

## 6. Background jobs

Automatic Trash retention uses a standard Nextcloud background job. A server installation should normally use system Cron instead of AJAX-only background jobs.

## 7. Disable or remove the application

Temporarily disable Home Wiki with:

```sh
sudo -u www-data php occ app:disable hc_homewiki
```

Disabling the app does not delete user Wiki files. For manual removal, disable the app first and remove only the `hc_homewiki` code directory from the apps path.

Create an export before deliberately deleting user data. Remove user data only through Home Wiki's data-management functions or deliberate Nextcloud administration.

## App directories

Nextcloud can have multiple app directories configured through `apps_paths`. For manual installation, use a writable directory intended for additional apps; do not overwrite system apps shipped with Nextcloud.
