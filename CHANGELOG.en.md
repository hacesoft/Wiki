# Changelog

## 1.2.5

- Mobile editor toolbar is now fixed directly above the content instead of floating over it.
- The formatting toolbar scrolls horizontally so all tools remain reachable on narrow screens.
- Mobile editor uses the maximum available viewport height with reduced top and bottom spacing.


## 1.2.4

- Fixed the mobile `⋯` button whose menu immediately closed after opening.
- Removed the separate mobile create-page button; page creation is now available from `⋯`.
- The mobile tools sheet now exposes page editing/viewing, editor tools, sharing, history, attachments, tags and other page actions directly without a nested menu.

## 1.2.3

- Mobile interface now uses one responsive top row: Wiki, Back, Home, Forward and a single tools button.
- The same compact controls are used in portrait and landscape/tablet layouts.
- The mobile tools menu is presented as a large touch-friendly bottom sheet and uses two columns when enough width is available.

## 1.2.2

- Phone layout reduced to a single compact `Wiki` bar with one tools menu.
- Page title, metadata, page navigation and page action header no longer consume vertical space on phones.
- Mobile tools menu provides navigation, page creation, Back/Home/Forward, edit/view switching, editor toolbar access and page actions.
- Editor mode tabs are removed from the mobile layout; formatting controls appear only when requested.

## 1.2.1

- Mobile interface reduced for smaller screens.
- On phones, the page header is more compact and page actions are consolidated.
- In edit mode on phones, the editor toolbar opens from a floating button so the content stays visible.

## 1.2.0 — 2026-10-25
- Documentation again includes the supported Docker/full-source installation path using `sudo sh install.sh` alongside App Store and manual release-archive installation.
- Cleaned public documentation and added clear App Store and manual release-archive installation paths.
- Added per-user editor opening position: top or bottom of the page; default remains bottom.
- Added optional bidirectional proportional scroll synchronization between Markdown source and live preview; default remains disabled.
- Editor scroll position is applied only when entering edit mode, so autosave and normal rerenders do not unexpectedly move the user.

## 1.1.0-dev.7 — 2026-10-25
- Added exact native Home Wiki export/import format documentation for third-party data conversion.
- Documented generic Markdown/Markdown-ZIP migration, native `formatVersion: 3`, ACL/media/attachment/tag mapping, safety limits and validation.
- Added an AI-assisted conversion workflow and reusable prompt template that requires input-format analysis before transformation.

## 1.1.0-dev.6 — 2026-10-25
- Added optional per-user in-Wiki navigation buttons `← 🏠 →`, disabled by default.
- Back/Forward use Home Wiki page history only; Home opens the effective configured start page.
- Removed the duplicate hard-coded application version; UI/export metadata now read the version from Nextcloud app metadata (`appinfo/info.xml`).

## 1.1.0-dev.4 — 2026-10-25
- Sharing-state indicators are now an optional per-user setting and are disabled by default.
- Reorganized Wiki settings into coherent Start page, Appearance, Page tree and Administrator groups.

- Added compact sharing-state indicators directly to the page tree without changing the database or API.
- The tree distinguishes private pages, selected-user/group sharing, and all-Nextcloud-user visibility.
- Effective permissions and persistent subtree inheritance are visible at a glance; exact ACL details are available in the tooltip.
- Mixed recipient permission profiles are marked with `≠` instead of showing a misleading aggregate.

## 1.1.0-dev.2 — 2026-10-24

- Fixed Nextcloud 35 `MigrationService.php#76` activation warning from the single database dependency in `info.xml`.
- No schema or functional changes; added manifest regression test.

## 1.1.0-dev.1 — 2026-10-24

- Persistent subtree-sharing inheritance on create, move, and sharing updates.
- Owner-only irreversible page/subtree transfer to an existing user, including Markdown, media and attachments.
- Transfers reset sharing to private, reassign tags and aliases, and report incomplete old-file cleanup.
- New safe additive DB migration for `inherit_sharing`; complete translations and baseline behavior tests.



## 1.0.0 — 2026-10-20

First public source release for Nextcloud 35.

### Features
- Markdown pages, hierarchical navigation and full-text search
- Fine-grained sharing, attachments and revision history
- Whole-Wiki export/import and Trash management

### Compatibility
- Nextcloud 35.
- Requires Hacesoft Shared App Core 0.18.8 or newer.
