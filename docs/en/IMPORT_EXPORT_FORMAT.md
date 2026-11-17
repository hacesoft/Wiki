[English](IMPORT_EXPORT_FORMAT.md) · [Čeština](../cz/FORMAT_IMPORT_EXPORT.md)

# Home Wiki export and import format

This document describes the portable Home Wiki format so data can be converted from another wiki, notes, or documentation system without depending on any particular source application.

Home Wiki supports three import modes:

1. one Markdown file (`.md` or `.markdown`),
2. a ZIP containing ordinary Markdown files,
3. a native Home Wiki ZIP containing `manifest.json`.

For a simple conversion, Markdown or Markdown ZIP is the safest route. To preserve the tree, UUIDs, images, attachments, tags, aliases, and permissions, use the native format.

> A native full restore is intended for an **empty owned Wiki**. Before importing important data, create a Nextcloud backup and a Home Wiki export first.

## 1. Simplest option: one Markdown file

A `page-name.md` file can be imported directly. Home Wiki creates a new **private** page.

The title is selected in this order:

1. the first Markdown heading `# Title`, if present,
2. otherwise the filename without its extension.

Example:

```markdown
# Example server

Notes about a server.
```

A Home Wiki internal link can be written as:

```text
[[Example server]]
```

## 2. Markdown ZIP

A ZIP without a valid native `manifest.json` is treated as a generic Markdown archive. Only `.md` and `.markdown` files are imported. Other files are ignored.

Example:

```text
import.zip
├─ Home-network.md
├─ Home-network/
│  ├─ Router.md
│  ├─ Switches.md
│  └─ Servers/
│     ├─ index.md
│     ├─ Example-server.md
│     └─ Backup.md
└─ Solar.md
```

Home Wiki attempts to infer the tree from directories. A parent is looked up through forms such as:

- `directory/index.md`,
- `directory/index.markdown`,
- `directory.md`,
- `directory.markdown`.

An `index.md` page takes the directory name when it has no own `# ...` heading.

This mode is suitable for transferring text and hierarchy. It **cannot reliably map foreign images and attachments to pages** because there is no manifest that defines ownership. Use the native format below if media, attachments, or metadata matter.

## 3. Native Home Wiki ZIP

The current format for newly generated archives is:

```json
{
  "format": "hc-homewiki-export",
  "formatVersion": 3
}
```

The importer can read native versions 1 and 2 for older Home Wiki backups, but **new conversions from foreign data should always generate version 3**.

### ZIP layout

```text
HomeWiki-import.zip
├─ manifest.json
├─ pages/
│  ├─ 11111111-1111-4111-8111-111111111111.md
│  └─ 22222222-2222-4222-8222-222222222222.md
├─ media/
│  ├─ 11111111-1111-4111-8111-111111111111/
│  │  └─ diagram.png
│  └─ 22222222-2222-4222-8222-222222222222/
│     └─ photo.jpg
└─ attachments/
   └─ 11111111-1111-4111-8111-111111111111/
      └─ manual.pdf
```

Every page must use a version-4 UUID. Its Markdown path must be exactly:

```text
pages/<UUID>.md
```

Images belong under:

```text
media/<page-UUID>/<filename>
```

Attachments belong under:

```text
attachments/<page-UUID>/<filename>
```

Do not use absolute paths, `..`, backslashes, or duplicate ZIP paths.

## 4. `manifest.json`

A practical minimal native example with two pages:

```json
{
  "format": "hc-homewiki-export",
  "formatVersion": 3,
  "appVersion": "1.2.0",
  "exportedAt": "2026-10-25T15:00:00Z",
  "sourceOwner": "import",
  "pageCount": 2,
  "mediaCount": 1,
  "attachmentCount": 1,
  "tagCount": 2,
  "tags": [
    { "name": "NAS" },
    { "name": "Network" }
  ],
  "notes": {
    "trashExcluded": true,
    "nextcloudFileVersionsExcluded": true
  },
  "pages": [
    {
      "id": "11111111-1111-4111-8111-111111111111",
      "parentId": null,
      "title": "Network documentation",
      "slug": "network-documentation",
      "visibility": "private",
      "pinned": true,
      "sortOrder": 0,
      "createdAt": 1761400000,
      "updatedAt": 1761400000,
      "revision": 1,
      "allPermissions": {
        "read": false,
        "edit": false,
        "delete": false,
        "share": false
      },
      "inheritSharing": false,
      "acl": [],
      "aliases": ["network"],
      "contentPath": "pages/11111111-1111-4111-8111-111111111111.md",
      "contentSha256": "<SHA-256 of the Markdown file>",
      "media": [
        {
          "name": "diagram.png",
          "path": "media/11111111-1111-4111-8111-111111111111/diagram.png",
          "mime": "image/png",
          "sha256": "<SHA-256 of the file>"
        }
      ],
      "attachments": [
        {
          "name": "manual.pdf",
          "path": "attachments/11111111-1111-4111-8111-111111111111/manual.pdf",
          "mime": "application/pdf",
          "size": 123456,
          "sha256": "<SHA-256 of the file>"
        }
      ],
      "tags": ["NAS", "Network"]
    },
    {
      "id": "22222222-2222-4222-8222-222222222222",
      "parentId": "11111111-1111-4111-8111-111111111111",
      "title": "Example server",
      "slug": "example-server",
      "visibility": "private",
      "pinned": false,
      "sortOrder": 10,
      "createdAt": 1761400000,
      "updatedAt": 1761400000,
      "revision": 1,
      "allPermissions": {
        "read": false,
        "edit": false,
        "delete": false,
        "share": false
      },
      "inheritSharing": false,
      "acl": [],
      "aliases": [],
      "contentPath": "pages/22222222-2222-4222-8222-222222222222.md",
      "contentSha256": "<SHA-256 of the Markdown file>",
      "media": [],
      "attachments": [],
      "tags": ["NAS"]
    }
  ]
}
```

`appVersion`, `exportedAt`, `sourceOwner`, and the counters are useful for diagnostics and portability. A converter should fill them consistently even though restore mainly relies on `format`, `formatVersion`, and `pages`.

## 5. Page fields

| Field | Meaning |
| --- | --- |
| `id` | Unique v4 page UUID. It must not be duplicated in the archive. |
| `parentId` | Parent UUID or `null`. The parent must be part of the same import. |
| `title` | Displayed page title. |
| `slug` | Unique page address within the owner. Generated slugs should use simple ASCII `a-z`, `0-9`, `-`, `_`, `/`. |
| `visibility` | `private`, `shared`, or `all`. Use `private` when migrating from an unknown permission model. |
| `pinned` | `true` / `false`. |
| `sortOrder` | Sibling ordering. Allowed range is `-1000000` to `1000000`. |
| `createdAt`, `updatedAt` | Unix timestamps in seconds. |
| `revision` | Positive revision number, normally `1` for a migration. |
| `allPermissions` | Permissions for all authenticated users when `visibility: "all"`. |
| `inheritSharing` | Whether sharing settings are maintained across the subtree. |
| `acl` | Specific users and groups. |
| `aliases` | Historical page names/addresses. |
| `contentPath` | Must be exactly `pages/<id>.md`. |
| `contentSha256` | SHA-256 of the Markdown. Optional for legacy backups, strongly recommended for every newly generated archive. |
| `media` | Images belonging to the page. |
| `attachments` | General attachments belonging to the page. |
| `tags` | Tag names assigned to the page. |

### Permissions for all users

Used only with `visibility: "all"`:

```json
"allPermissions": {
  "read": true,
  "edit": false,
  "delete": false,
  "share": false
}
```

Read access is implicit when visibility is `all`. Global permissions are reset in the `private` and `shared` modes.

### User and group ACL

Example:

```json
"acl": [
  {
    "type": "user",
    "id": "jane",
    "canRead": true,
    "canEdit": true,
    "canDelete": false,
    "canShare": false
  },
  {
    "type": "group",
    "id": "family",
    "canRead": true,
    "canEdit": false,
    "canDelete": false,
    "canShare": false
  }
]
```

`type` is `user` or `group`. The ID must exist in the target Nextcloud instance. Missing users/groups are skipped and Home Wiki reports the number of skipped ACL entries.

If the source permission model cannot be mapped confidently, **do not invent access rights**. Import the page as `private`, use `acl: []`, and set permissions manually after migration.

## 6. Images and attachments

Every file referenced in the manifest must physically exist at the exact ZIP path. Newly generated archives should always include SHA-256 hashes.

Image:

```json
{
  "name": "diagram.png",
  "path": "media/<UUID>/diagram.png",
  "mime": "image/png",
  "sha256": "..."
}
```

Attachment:

```json
{
  "name": "manual.pdf",
  "path": "attachments/<UUID>/manual.pdf",
  "mime": "application/pdf",
  "size": 123456,
  "sha256": "..."
}
```

Markdown must reference media consistently with the converted content. If a converter cannot determine the original page-to-file relationship safely, omitting that file is better than attaching it to the wrong page.

## 7. Tags, aliases, and links

Global tags:

```json
"tags": [
  { "name": "NAS" },
  { "name": "Network" }
]
```

A page uses the tag names directly:

```json
"tags": ["NAS", "Network"]
```

Tag names are limited to 64 characters.

Historical aliases are strings in `aliases`. Internal links should preferably be converted to:

```text
[[Target page title]]
```

If the source system uses internal IDs, first build a `source ID -> target title/UUID` mapping and convert links in a second pass.

## 8. Security and size limits

The current implementation applies the following limits. They may change in a future version, so a long-lived converter should verify the documentation for the target release.

| Limit | Value |
| --- | ---: |
| Uploaded ZIP | 200 MiB |
| ZIP entries | 25,000 |
| Pages | 2,000 |
| One Markdown file | 5 MiB |
| Total uncompressed Markdown | 100 MiB |
| `manifest.json` | 10 MiB |
| One image/media file | 10 MiB |
| Media files | 5,000 |
| Total uncompressed media | 250 MiB |
| One attachment | 50 MiB |
| Attachments | 5,000 |
| Total uncompressed attachments | 500 MiB |

Home Wiki checks declared uncompressed sizes, duplicate paths, and unsafe paths before reading entries and uses bounded reads for individual entries.

## 9. Recommended conversion process

1. Make a copy/export of the source data. Never migrate the only existing copy.
2. Inspect the actual input structure: pages, IDs, parents, ordering, Markdown/HTML, images, attachments, tags, and permissions.
3. Decide whether Markdown ZIP is enough or native `formatVersion: 3` is required.
4. Build a stable page mapping and v4 UUIDs. Do not change generated UUIDs halfway through the same migration.
5. Convert content. Convert HTML to ordinary Markdown without scripts or active content.
6. In a second pass, convert internal links and parent-child relationships.
7. Then add media, attachments, tags, and permissions where they can be mapped reliably.
8. Compute SHA-256 from the **actual bytes stored in the final ZIP**.
9. Validate JSON, duplicate UUIDs/slugs, referenced files, and an acyclic page tree.
10. First import into an empty test user/Wiki and manually compare the result with the source.

## 10. Using AI to create a converter

An AI tool can be very useful for a one-off migration because it can first inspect an unfamiliar export and then create a transformation script. The AI **must not guess the input structure**. Give it a representative sample or the safely available export and require input-format analysis before conversion.

If the source contains private notes, personal data, passwords, tokens, or internal documentation, do not upload it to an untrusted public AI service. Prefer a local model, an approved enterprise environment, or anonymized data.

### Prompt template

```text
I have an export from another wiki/notes application and need to convert it to the Home Wiki import format.

FIRST inspect the input data. Do not assume its structure. Determine:
- where pages and their content are stored,
- whether stable source IDs exist,
- how parent relationships and ordering are represented,
- how internal links are encoded,
- where images and attachments live and how they map to pages,
- whether tags, aliases, or permissions exist,
- whether page content is Markdown, HTML, or another format.

Then propose a mapping to Home Wiki.

For a simple migration you may create a ZIP with .md/.markdown files and directory hierarchy.
For a complete migration create a native Home Wiki ZIP:
- manifest.json: format="hc-homewiki-export", formatVersion=3,
- Markdown pages: pages/<UUIDv4>.md,
- images: media/<UUIDv4>/<file>,
- attachments: attachments/<UUIDv4>/<file>.

For each page, map available source data to:
id, parentId, title, slug, visibility, pinned, sortOrder, createdAt, updatedAt,
revision, allPermissions, inheritSharing, acl, aliases, contentPath,
contentSha256, media, attachments, and tags.

Rules:
- Generate stable version-4 UUIDs and use them consistently through the conversion.
- parentId may reference only another page in the same import or be null.
- contentPath must be exactly pages/<id>.md.
- Do not invent permissions. If they cannot be mapped reliably, use visibility="private", acl=[], and no global permissions.
- Report ambiguous or missing relationships as warnings instead of guessing them.
- Convert internal links to [[Page title]] where possible.
- Convert HTML to clean safe Markdown; do not carry scripts or active content.
- Compute SHA-256 from the actual output files.
- Validate unique UUIDs and slugs, an acyclic tree, and that every manifest path exists in the ZIP.
- Do not add files that are not required by the import.

First show me the input-format analysis and mapping table. Only then create the transformation script. Finally provide a validation report with counts of pages, media, attachments, tags, unresolved links, and every item that could not be converted safely.
```

Prefer asking the AI to create a **converter script** (for example in Python) rather than manually rewriting a large archive. A script can be rerun, reviewed, and compared with the original data.

## 11. Validate the archive before import

At minimum verify that:

- `manifest.json` is valid UTF-8 JSON,
- `format` is exactly `hc-homewiki-export`,
- `formatVersion` is `3`,
- every `id` is a unique v4 UUID,
- every `slug` is unique,
- every `parentId` points to an existing page in the archive or is `null`,
- the tree has no cycle,
- every `contentPath`, `media[].path`, and `attachments[].path` exists in the ZIP,
- SHA-256 values match the actual file bytes,
- media and attachment `name` values contain filenames only, not paths,
- there are no absolute paths or `..`,
- the archive is within the current size limits,
- the target Wiki is empty for a native full restore.

After import, manually inspect the tree, several internal links, images, attachments, tags, and — if migrated — sharing and permissions.
