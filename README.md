# Home Wiki 1.0.0

> **Publisher input archive:** This ZIP uses the final app-rooted format. NAS deployment helpers (`install.sh`, `uninstall.sh`, `build-release.sh`, `scripts/`) are not included. Any instructions referencing those files apply to the separate full-source GitHub repository, not this archive. The final signed App Store `.tar.gz` is produced by Hacesoft Publisher.


Home Wiki is a Markdown-based wiki for **Nextcloud 35**. Pages and attachments are stored in the page owner's Nextcloud Files; the Nextcloud database stores the page tree, sharing, tags and other metadata.

[Česky](README_CZ.md) · [English documentation](docs/en/README.md) · [Česká dokumentace](docs/cz/README.md)

## Requirements

- Nextcloud **35**
- PHP **8.3–8.5** with ZIP support
- MariaDB/MySQL via Nextcloud's `mysql` driver
- [Shared App Core](https://github.com/hacesoft/core) (`hc_shared_app_core`) **0.18.8 or newer**, installed and enabled first

## Installation

### Standard manual installation from GitHub

Clone the repository directly as `hc_homewiki` into your Nextcloud `custom_apps` directory:

```sh
cd /var/www/html/custom_apps
git clone https://github.com/hacesoft/Wiki.git hc_homewiki
cd /var/www/html
sudo -u www-data php occ app:enable hc_homewiki
```

The app files (`appinfo/`, `lib/`, `js/`, etc.) are at the **repository root**, not in a nested directory. Alternatively download a source ZIP, rename its extracted directory to `hc_homewiki`, place it under `custom_apps/` and enable it through Nextcloud. Back up your Nextcloud instance before updating an existing installation. See [installation documentation](docs/en/INSTALLATION.md).

### Synology NAS / Docker

For a deployment with Docker CLI access, unpack or clone this repository outside `custom_apps` and run:

```sh
sudo sh install.sh
```

This optional installer locates the Nextcloud containers, verifies requirements, backs up the currently installed application code, deploys the runtime application files and checks the result. It does not remove user Wiki data. Container discovery is designed for typical Nextcloud Docker deployments and cannot be guaranteed for every setup. See [NAS installation](docs/cz/NAS_INSTALACE.md).

`uninstall.sh` removes only the application code from the Docker deployment and preserves user files and database content. Use it only if you understand the consequences of disabling/removing the app.

## Features

- Hierarchical page tree with drag-and-drop organization
- Shared App Core Markdown editor, live preview and autosave
- Revision checking and conflict protection for concurrent edits
- Private, shared and all-authenticated-users visibility, with separate Read / Edit / Delete / Share permissions
- Wiki links, page aliases, backlinks and full-text search
- Pinned, recent and frequently visited pages; tags and attachments
- Version history via Nextcloud `files_versions`
- Trash with optional retention and whole-Wiki ZIP export/import
- Dashboard widget, mobile layout and configurable navigation

## Repository layout

- `appinfo/`, `lib/`, `js/`, `css/`, `l10n/`, `templates/`, `img/` – Nextcloud application runtime
- `docs/` – public documentation (Czech and English)
- `install.sh`, `uninstall.sh`, `scripts/` – optional Docker/NAS deployment utilities; not needed for standard Nextcloud installation
- `README.md`, `README_CZ.md` – project information

## Languages

Czech, English, German, Spanish, French, Italian, Dutch, Polish, Portuguese (Portugal), Slovak and Ukrainian. English is the fallback language.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) and the [full AGPL 3.0 license text](https://www.gnu.org/licenses/agpl-3.0.txt).

Release history: [CHANGELOG.md](CHANGELOG.md). Czech: [CHANGELOG.cs.md](CHANGELOG.cs.md).

## Full source archive and Hacesoft Publisher

This GitHub/NAS project contains install.sh, uninstall.sh, build-release.sh, scripts/, docs/ and the installable Nextcloud runtime in src/. For NAS installation, extract the entire project and run `sudo sh install.sh` from its root. Do not copy the project root or scripts/ directly into Nextcloud custom_apps; the installer deploys src/. Publisher independently validates this full-source input and builds its own app-ID-rooted, signed distribution archive.
