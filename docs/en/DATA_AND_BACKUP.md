[English](DATA_AND_BACKUP.md) · [Čeština](../cz/DATA_A_ZALOHY.md)

# Data, permissions, Trash and backup

## Data storage

Canonical page content is Markdown in the owner's Nextcloud Files:

```text
HomeWiki/
├─ .nomedia
├─ pages/
│  └─ <UUID>-<slug>.md
├─ media/
├─ attachments/
└─ .trash/
   ├─ pages/
   ├─ media/
   └─ attachments/
```

Markdown contains an internal `homewiki-meta` recovery comment followed by normal Markdown. `.nomedia` prevents generic Preview Generator jobs from processing Home Wiki's internal file tree.

The database stores the authoritative index and relationships: tree/order, visibility, ACL, aliases, link index, revisions, Trash records and tags. Physical content and database metadata should therefore be backed up together.

## Database tables

The standard Nextcloud migration creates:

- `hc_homewiki_pages`
- `hc_homewiki_acl`
- `hc_homewiki_aliases`
- `hc_homewiki_links`
- `hc_homewiki_trash`
- `hc_homewiki_tags`
- `hc_homewiki_page_tags`

Nextcloud automatically adds the instance database table prefix, such as `oc_`.

## Delegated writes to shared pages

Application ACL may allow a user to edit Markdown physically owned by another user. Home Wiki first authorizes through its own ACL and uses the physical owner's filesystem context only for the actual Files mutation. This lets standard Nextcloud Files events and `files_versions` operate on the correct canonical file without changing the persistent login session.

## Trash

Normal deletion is reversible. Markdown, media and attachments move under `HomeWiki/.trash/`, while the database stores a snapshot of page metadata, ACL, aliases and tags. Trash is managed by the physical page owner.

Items can be restored, permanently deleted, or all owner Trash can be emptied. Per-user automatic retention can be Off, 7, 14, 30 or 90 days and is enforced by a daily Nextcloud background job.

## Native whole-Wiki export

A native export is a ZIP containing `manifest.json`. It exports **only active pages owned by the current user**. Pages owned by other users and merely shared in are not copied into that user's backup. If the user owns no active pages, export is unavailable.

The current native format is `formatVersion: 3` and contains:

- page UUID, parent, ordering, title, slug, pinned state and revision,
- current Markdown,
- visibility, global permissions and ACL,
- historical aliases,
- images,
- general attachments with SHA-256 metadata,
- tag definitions and assignments.

Home Wiki Trash and Nextcloud `files_versions` history are intentionally excluded.

Native restore accepts v1, v2 and v3 backups and only restores into an empty owned Wiki. It validates UUIDs, duplicate slugs, tree cycles, ZIP paths, size limits and available SHA-256 hashes. ACL entries referring to users/groups that no longer exist are skipped and reported.

## Markdown import

A standalone `.md`/`.markdown` file becomes a new private page. A ZIP without a Home Wiki manifest is treated as a Markdown directory: `.md`/`.markdown` files are imported and hierarchy is inferred from `index.md` or matching parent Markdown files. Other files are ignored because a foreign Markdown ZIP has no reliable attachment-to-page mapping.

## Recovery from Markdown metadata

Recovery can recreate missing database page rows from the internal `homewiki-meta` headers and does not overwrite existing rows. It is an emergency index-recovery feature, not a replacement for a full Nextcloud backup or native Home Wiki ZIP.

## Delete entire Wiki

`Delete entire Wiki` removes all live and trashed pages owned by the current user and their Home Wiki files. Pages owned by other users and only shared in remain untouched. Outgoing shares of deleted owner pages disappear with their source pages.

The operation requires typed confirmation and the current password every time. The server uses strict password confirmation. Home Wiki does not persist the password.

## Import protection

ZIP entry counts, uncompressed sizes, duplicate paths and aggregate limits are checked before decompression. Reads are bounded; native backups validate hashes and process media and attachments one at a time. Creating or recovering pages, importing and removing the owner’s Wiki use a shared Nextcloud owner lock. A failed restore removes only the pages created by that restore, never the entire HomeWiki folder. External Files/WebDAV edits are not covered by this application lock.
