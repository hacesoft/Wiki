<?php /** @var array $_ */ ?>
<div id="hc-homewiki-root"
     data-app-version="<?php p($_['version']); ?>"
     data-required-core-version="<?php p($_['requiredCoreVersion']); ?>"
     data-required-core-api-version="<?php p($_['requiredCoreApiVersion']); ?>"
     data-core-download-url="<?php p($_['coreDownloadUrl']); ?>"></div>

<template id="hc-homewiki-app-template">
<div id="hc-homewiki-app" class="hc-homewiki-app" data-menu-density="compact" data-page-header-mode="scroll">
    <aside class="hc-homewiki-sidebar" id="hc-homewiki-sidebar">
        <div class="hc-homewiki-sidebar-mobile-head">
            <strong><?php p($l->t('Navigation')); ?></strong>
            <button id="hc-homewiki-sidebar-close" type="button" aria-label="<?php p($l->t('Close')); ?>">×</button>
        </div>

        <div class="hc-homewiki-brand">
            <div>
                <h2><?php p($l->t('Home Wiki')); ?></h2>
                <small>v<?php p($_['version']); ?></small>
            </div>
            <div class="hc-homewiki-brand-actions">
                <button id="hc-homewiki-app-actions"
                        class="hc-homewiki-app-actions"
                        type="button"
                        aria-haspopup="menu"
                        aria-expanded="false"
                        title="<?php p($l->t('Wiki menu')); ?>"
                        aria-label="<?php p($l->t('Wiki menu')); ?>">⋯</button>
                <button id="hc-homewiki-new" class="primary" type="button" aria-label="<?php p($l->t('Create page')); ?>">＋</button>
            </div>
        </div>

        <div class="hc-homewiki-search-wrap">
            <label class="hidden-visually" for="hc-homewiki-search"><?php p($l->t('Search wiki')); ?></label>
            <input id="hc-homewiki-search" type="search" placeholder="<?php p($l->t('Search wiki')); ?>">
        </div>

        <div id="hc-homewiki-side-scroll" class="hc-homewiki-side-scroll hc-shared-app-core-scroll-area">
            <div id="hc-homewiki-pinned-section" class="hc-homewiki-side-section" data-sidebar-section="pinned">
                <div class="hc-homewiki-section-head">
                    <button class="hc-homewiki-section-toggle" type="button" data-section-toggle="pinned" aria-expanded="true" title="<?php p($l->t('Collapse section')); ?>">▾</button>
                    <strong>📌 <?php p($l->t('Pinned')); ?></strong>
                </div>
                <div id="hc-homewiki-pinned" class="hc-homewiki-section-body"></div>
            </div>

            <div id="hc-homewiki-recent-section" class="hc-homewiki-side-section" data-sidebar-section="recent" hidden>
                <div class="hc-homewiki-section-head">
                    <button class="hc-homewiki-section-toggle" type="button" data-section-toggle="recent" aria-expanded="true" title="<?php p($l->t('Collapse section')); ?>">▾</button>
                    <strong>🕘 <?php p($l->t('Recent pages')); ?></strong>
                </div>
                <div id="hc-homewiki-recent" class="hc-homewiki-section-body"></div>
            </div>

            <div id="hc-homewiki-frequent-section" class="hc-homewiki-side-section" data-sidebar-section="frequent" hidden>
                <div class="hc-homewiki-section-head">
                    <button class="hc-homewiki-section-toggle" type="button" data-section-toggle="frequent" aria-expanded="true" title="<?php p($l->t('Collapse section')); ?>">▾</button>
                    <strong>★ <?php p($l->t('Frequently used')); ?></strong>
                </div>
                <div id="hc-homewiki-frequent" class="hc-homewiki-section-body"></div>
            </div>

            <div id="hc-homewiki-tags-section" class="hc-homewiki-side-section" data-sidebar-section="tags">
                <div class="hc-homewiki-section-head">
                    <button class="hc-homewiki-section-toggle" type="button" data-section-toggle="tags" aria-expanded="true" title="<?php p($l->t('Collapse section')); ?>">▾</button>
                    <strong>🏷 <?php p($l->t('Tags')); ?></strong>
                </div>
                <div id="hc-homewiki-tags" class="hc-homewiki-section-body hc-homewiki-tags-list"></div>
            </div>

            <div id="hc-homewiki-pages-section" class="hc-homewiki-side-section hc-homewiki-tree-section" data-sidebar-section="pages">
                <div class="hc-homewiki-tree-section-head hc-homewiki-section-head">
                    <div class="hc-homewiki-section-head-main">
                        <button class="hc-homewiki-section-toggle" type="button" data-section-toggle="pages" aria-expanded="true" title="<?php p($l->t('Collapse section')); ?>">▾</button>
                        <strong id="hc-homewiki-tree-root-drop"
                                class="hc-homewiki-tree-root-drop"
                                title="<?php p($l->t('Move to top level')); ?>">📚 <?php p($l->t('Pages')); ?></strong>
                    </div>
                    <button id="hc-homewiki-tree-drag-toggle"
                            class="hc-homewiki-tree-drag-toggle"
                            type="button"
                            aria-pressed="false"
                            title="<?php p($l->t('Enable drag-and-drop tree organization')); ?>">🔒</button>
                </div>
                <div id="hc-homewiki-tree" class="hc-homewiki-tree hc-homewiki-section-body"></div>
            </div>
        </div>

    </aside>

    <div id="hc-homewiki-sidebar-resizer"
         class="hc-homewiki-sidebar-resizer"
         role="separator"
         aria-orientation="vertical"
         aria-label="<?php p($l->t('Resize navigation')); ?>"
         aria-valuemin="220"
         aria-valuemax="640"
         aria-valuenow="300"
         tabindex="0"></div>

    <button id="hc-homewiki-sidebar-backdrop"
            class="hc-homewiki-sidebar-backdrop"
            type="button"
            aria-label="<?php p($l->t('Close navigation')); ?>"
            hidden></button>

    <main class="hc-homewiki-main hc-shared-app-core-scroll-area" id="hc-homewiki-main">
        <div class="hc-homewiki-mobile-bar">
            <button id="hc-homewiki-sidebar-open"
                    type="button"
                    class="hc-homewiki-mobile-menu"
                    aria-controls="hc-homewiki-sidebar"
                    aria-expanded="false">☰ <span><?php p($l->t('Wiki')); ?></span></button>
            <strong class="hc-homewiki-mobile-current" id="hc-homewiki-mobile-current"><?php p($l->t('Home Wiki')); ?></strong>
            <button id="hc-homewiki-mobile-new" type="button" class="primary" aria-label="<?php p($l->t('Create page')); ?>">＋</button>
        </div>

        <div id="hc-homewiki-empty" class="hc-homewiki-empty-state">
            <div class="hc-homewiki-empty-icon" aria-hidden="true">📖</div>
            <h2><?php p($l->t('Your private Home Wiki')); ?></h2>
            <p><?php p($l->t('Create the first page or select one from the navigation.')); ?></p>
            <button id="hc-homewiki-empty-new" class="primary" type="button"><?php p($l->t('Create page')); ?></button>
            <button id="hc-homewiki-empty-recover" type="button"><?php p($l->t('Restore pages from Markdown')); ?></button>
        </div>

        <article id="hc-homewiki-page" class="hc-homewiki-page" hidden>
            <header class="hc-homewiki-page-head">
                <div class="hc-homewiki-page-title-wrap">
                    <label class="hidden-visually" for="hc-homewiki-title"><?php p($l->t('Page title')); ?></label>
                    <input id="hc-homewiki-title" class="hc-homewiki-title" maxlength="255" placeholder="<?php p($l->t('Page title')); ?>">
                    <div id="hc-homewiki-page-meta" class="hc-homewiki-page-meta"></div>
                </div>
                <div class="hc-homewiki-head-actions">
                    <button id="hc-homewiki-edit-page" type="button" hidden>✏️ <?php p($l->t('Edit page')); ?></button>
                    <button id="hc-homewiki-page-actions" type="button" aria-haspopup="menu" aria-expanded="false" aria-label="<?php p($l->t('Page actions')); ?>">⋯</button>
                    <button id="hc-homewiki-pin" type="button" hidden>☆</button>
                    <button id="hc-homewiki-share" type="button" hidden><?php p($l->t('Sharing')); ?></button>
                    <button id="hc-homewiki-export" type="button" hidden><?php p($l->t('Export Markdown')); ?></button>
                    <button id="hc-homewiki-delete" type="button" hidden><?php p($l->t('Move to trash')); ?></button>
                </div>
            </header>

            <div id="hc-homewiki-edit-controls" class="hc-homewiki-edit-controls" hidden>
                <nav class="hc-homewiki-tabs" aria-label="<?php p($l->t('Editor mode')); ?>">
                    <button class="hc-homewiki-tab active" data-mode="edit" type="button"><?php p($l->t('Editor')); ?></button>
                    <button class="hc-homewiki-tab" data-mode="preview" type="button"><?php p($l->t('Preview')); ?></button>
                    <button id="hc-homewiki-side-preview" type="button" aria-pressed="true"><?php p($l->t('Preview on the right')); ?></button>
                    <button id="hc-homewiki-finish-edit" class="primary" type="button"><?php p($l->t('View page')); ?></button>
                </nav>

                <div id="hc-homewiki-editor-toolbar-row" class="hc-homewiki-editor-toolbar-row">
                    <div id="hc-homewiki-toolbar" class="hc-homewiki-toolbar-host"></div>
                    <span id="hc-homewiki-save-state" class="hc-homewiki-save-state" aria-live="polite"><?php p($l->t('Saved')); ?></span>
                </div>
            </div>

            <div class="hc-homewiki-editor-shell">
                <textarea id="hc-homewiki-source" class="hc-homewiki-source" spellcheck="false" hidden></textarea>
                <div id="hc-homewiki-live-edit" class="hc-homewiki-live-edit">
                    <div id="hc-homewiki-core-editor"></div>
                    <div id="hc-homewiki-live-preview" class="hc-homewiki-preview hc-homewiki-live-preview" aria-label="<?php p($l->t('Live preview')); ?>"></div>
                </div>
                <div id="hc-homewiki-preview" class="hc-homewiki-preview" hidden></div>
            </div>
            <input id="hc-homewiki-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>

            <footer class="hc-homewiki-page-footer">
                <div id="hc-homewiki-backlinks"></div>
            </footer>
        </article>
    </main>
    <div id="hc-homewiki-page-menu" class="hc-homewiki-page-menu" role="menu" hidden></div>
    <div id="hc-homewiki-app-menu" class="hc-homewiki-page-menu hc-homewiki-app-menu" role="menu" hidden></div>
</div>
</template>
