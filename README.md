# Home Wiki 1.0.0


Home Wiki is a Markdown-based wiki for **Nextcloud 35**. Pages and attachments are stored in the page owner's Nextcloud Files; the Nextcloud database stores the page tree, sharing, tags and other metadata.

[Česky](README_CZ.md) · [English documentation](docs/en/README.md) · [Česká dokumentace](docs/cz/README.md)

## Requirements

- Nextcloud **35**
- PHP **8.3–8.5** with ZIP support
- MariaDB/MySQL via Nextcloud's `mysql` driver
- [Shared App Core](https://github.com/hacesoft/core) (`hc_shared_app_core`) **0.18.8 or newer**, installed and enabled first

## Installation

Install and enable [HC Shared App Core](https://github.com/hacesoft/core) 0.18.8 or newer first. The Nextcloud application code is located in `src/`, **not** at the repository root.

For the documented Synology/Docker deployment, clone the complete project outside `custom_apps` and run:

```sh
git clone https://github.com/hacesoft/Wiki.git
cd Wiki
sudo sh install.sh
```

The installer stages `src/` in Nextcloud under the technical app ID `hc_homewiki` without deleting user content. On other environments, deploy **only the contents of `src/`** into `custom_apps/hc_homewiki/`, set appropriate permissions, then run `occ app:enable hc_homewiki` as the web-server user. Back up the database and files before upgrading. See the [installation guide](docs/en/INSTALLATION.md).

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

- `src/` – Nextcloud runtime (`src/appinfo/`, `src/lib/`, `src/js/`, `src/css/`, `src/l10n/`, `src/templates/`, `src/img/`)
- `docs/` – public documentation (Czech and English)
- `install.sh`, `uninstall.sh`, `scripts/` – optional Docker/NAS deployment utilities; not needed for standard Nextcloud installation
- `build-release.sh` – build script
- `README.md`, `README_CZ.md`, `LICENSE`, `CHANGELOG.md` – project information and license

## Languages

Czech, English, German, Spanish, French, Italian, Dutch, Polish, Portuguese (Portugal), Slovak and Ukrainian. English is the fallback language.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE) and the [full AGPL 3.0 license text](https://www.gnu.org/licenses/agpl-3.0.txt).

Release history: [CHANGELOG.md](CHANGELOG.md). Czech: [CHANGELOG.cs.md](CHANGELOG.cs.md).
