# Home Wiki 1.2.8

Home Wiki is a Markdown-based wiki for **Nextcloud 35**. Pages, images and attachments are stored in the owner's Nextcloud Files; the Nextcloud database stores the page tree, sharing, tags, links and other metadata.

## Screenshots

![Home Wiki screenshot 01](https://raw.githubusercontent.com/hacesoft/Wiki/main/screenshots/01.png)

![Home Wiki screenshot 02](https://raw.githubusercontent.com/hacesoft/Wiki/main/screenshots/02.png)

[Česky](README_CZ.md)

## Requirements

- Nextcloud **35**
- PHP **8.3–8.5** with ZIP support
- MariaDB/MySQL through Nextcloud's `mysql` database driver
- [HC Shared App Core](https://github.com/hacesoft/core) (`hc_shared_app_core`) **0.18.8 or newer**

## Installation

### Nextcloud App Store

If Home Wiki is available in your Nextcloud App Store, this is the recommended installation method. Install and enable **HC Shared App Core** first, then find **Home Wiki** under **Apps** and install/enable it.

### Manual installation

Manual installation is supported for administrators who do not want to use the App Store or cannot access it. Use the ready-to-run release archive `hc_homewiki-<version>.tar.gz` or `hc_homewiki-<version>.zip`, not the complete source project.

The archive contains one root directory named `hc_homewiki`. Place it in a writable Nextcloud apps directory (commonly `custom_apps/hc_homewiki`), set ownership/permissions for the web process, and enable the app with `occ`:

```sh
sudo -u www-data php occ app:enable hc_homewiki
```

For Docker, run the same command inside the Nextcloud container; for example:

```sh
docker exec -u www-data <nextcloud-container> php /var/www/html/occ app:enable hc_homewiki
```

See [Installation and upgrades](docs/en/INSTALLATION.md) for the full manual-installation, upgrade and verification procedure.

### Full-source installation with `install.sh`

Administrators running Nextcloud in Docker can also use the automated installer included in the full-source package. Extract the full-source archive and run from its root:

```sh
sudo sh install.sh
```

The script validates app metadata, discovers running Nextcloud containers, checks HC Shared App Core, creates a transactional backup of the previous runtime, deploys the new code, validates the database structure, and attempts to restore the previous runtime if deployment fails. This method requires a Docker-based Nextcloud host with the `docker` command available; for non-Docker installations use the App Store or the manual release-archive method.

## Features

- hierarchical page tree with drag-and-drop organization,
- Shared App Core Markdown editor, live preview and autosave,
- conflict protection for concurrent edits,
- private pages, user/group sharing and all-authenticated-users visibility,
- separate Read / Edit / Delete / Share permissions and optional permission indicators in the tree,
- persistent subtree sharing inheritance and page/subtree ownership transfer,
- Wiki links, aliases, backlinks and full-text search,
- pinned, recent and frequently used pages, tags and attachments,
- version history through Nextcloud `files_versions`,
- Trash with optional retention,
- whole-Wiki ZIP export/import and Markdown/Markdown-ZIP import,
- optional `← 🏠 →` page navigation, mobile layout and per-user editor settings,
- optional synchronized scrolling between Markdown source and live preview.

## Documentation

- [Installation and upgrades](docs/en/INSTALLATION.md)
- [Using Home Wiki](docs/en/USAGE.md)
- [Data, permissions, Trash and backup](docs/en/DATA_AND_BACKUP.md)
- [Export/import format and converting data from other systems](docs/en/IMPORT_EXPORT_FORMAT.md)

## Backup

Before a manual upgrade or major change, create a native Home Wiki export and a normal Nextcloud backup. The user's `HomeWiki/` directory is data, not application code, and must not be removed during upgrades.

## Languages

Czech, English, German, Spanish, French, Italian, Dutch, Polish, Portuguese (Portugal), Slovak and Ukrainian. English is the fallback language.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).

Release history: [CHANGELOG.md](CHANGELOG.md). Czech: [CHANGELOG.cs.md](CHANGELOG.cs.md).
