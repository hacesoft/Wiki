[English](USAGE.md) · [Čeština](../cz/POUZITI.md)

# Using Home Wiki

## Tree and navigation

Pages can form an arbitrarily deep tree. Drag-and-drop organization can be enabled in the navigation and moves pages between parents or back to the top level. The desktop sidebar is resizable. Long titles use ellipsis and expose the complete title in a tooltip.

The **Pinned**, **Recent pages**, **Frequently used**, **Tags** and **Pages** sections can be collapsed or hidden. Menu/tree density is a per-user setting (`Compact` / `Comfortable`).

## Editor and concurrent changes

Home Wiki uses the Shared App Core editor and Markdown renderer, so reading, preview and live preview share one parser. Content is autosaved.

Each page has a numeric revision. Saves send an expected revision and the server performs an atomic compare-and-swap before changing data. If another user has changed the page, Home Wiki does not silently overwrite it and exposes conflict handling. An open page also watches the server revision so a clean editor can reload newer content.

## Internal links

Wiki links can be inserted with a page picker. Renaming a page stores historical aliases so older links can continue to resolve. The resolver always re-checks current Read permission on the destination.

## Sharing

A page can be:

- **Private** – owner only,
- **Shared** – access comes from user/group ACL entries,
- **All Nextcloud users** – every authenticated user has at least Read; higher rights can be granted explicitly.

Permissions are separated into **Read, Edit, Delete, Share**. The owner always has all permissions. Edit allows content changes and attachment uploads; attachment deletion requires Delete. Version history is available only to users with Edit permission.

## Images and attachments

Editor images are stored under `HomeWiki/media/`. General attachments (PDF, ZIP, configuration, spreadsheets, logs, and similar files) are stored under `HomeWiki/attachments/`. Each attachment is limited to 50 MB.

Read permission can list/download attachments. Edit can upload. Delete can remove attachments.

## Tags

Tags organize pages across the tree. The owner can create, rename or delete tags and can assign/remove a tag from:

- one page only,
- a page and all of its current descendants.

Subtree assignment is a one-time bulk operation, not inheritance. A child created later does not automatically receive the tag. Users with shared Read/Edit access may see and filter visible tags, but only the page owner changes tag assignments.

## Version history

Home Wiki does not maintain a duplicate content archive. Markdown history is read from the standard Nextcloud `files_versions` app and follows the instance retention policy. Restoring a version uses the normal Home Wiki save/CAS path.

## Settings

Each user can choose a personal start page, sidebar visibility/collapse state, drag-and-drop, Trash retention, menu/tree density and page-header behavior (`Scroll with content` / `Keep visible while scrolling`). An administrator can configure a default start page for the owner or for all users without a personal choice; a global default must be readable by all authenticated users.
