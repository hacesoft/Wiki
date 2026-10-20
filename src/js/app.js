/** CZ-FUNKCE: Spustí inicializaci aplikace Home Wiki a naváže ji na Shared App Core. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
const fStartHomeWiki = () => {
    'use strict';

    const sAppId = 'hc_homewiki';
    const oRoot = document.getElementById('hc-homewiki-root');
    const oCore = window.HcSharedAppCore;
    if (!oRoot) return;
    if (!oCore) {
        const oError = document.createElement('section');
        oError.setAttribute('role', 'alert');
        oError.textContent = t(sAppId, 'Shared App Core is not installed, enabled, or could not be loaded.');
        oRoot.replaceChildren(oError);
        return;
    }
    const sAppVersion = String(oRoot?.dataset.appVersion || '');
    const sRequiredCoreVersion = String(oRoot?.dataset.requiredCoreVersion || '');
    if (!sAppVersion || !sRequiredCoreVersion) {
        throw new Error(t(sAppId, 'Home Wiki dependency information is invalid.'));
    }
    const nRequiredApiVersion = Number(oRoot.dataset.requiredCoreApiVersion || 0);
    if (oCore.apiVersion !== nRequiredApiVersion) {
        throw new Error(t(sAppId, 'Shared App Core API is not compatible.'));
    }
    oCore.assertCompatible(sRequiredCoreVersion);
    oRoot.classList.add('hc-homewiki-root');

    const oAppTemplate = document.getElementById('hc-homewiki-app-template');
    if (!(oAppTemplate instanceof HTMLTemplateElement)) {
        throw new Error(t(sAppId, 'Home Wiki root is missing.'));
    }
    oRoot.replaceChildren(oAppTemplate.content.cloneNode(true));

    const oLogger = oCore.logger;
    const oLifecycleAbort = new AbortController();
    const oLifecycleSignal = oLifecycleAbort.signal;

    /** @type {Array<Record<string, any>>} */
    let aPages = [];
    /** @type {Array<Record<string, any>>} */
    let aRecentPages = [];
    /** @type {Array<Record<string, any>>} */
    let aFrequentPages = [];
    /** @type {Array<Record<string, any>>} */
    let aOwnedTags = [];
    /** @type {Array<Record<string, any>>} */
    let aVisibleTags = [];
    let sTagFilterId = '';
    /** @type {Record<string, any>|null} */
    let oCurrentPage = null;
    let oEditBase = null;
    /** @type {Array<Record<string, any>>} */
    let aAclDraft = [];
    let sEditorMode = 'read';
    let bSidePreview = true;
    let oCoreEditor = null;
    let bDirty = false;
    let bDestroyed = false;
    let nSaveTimer = 0;
    let oLayoutController = null;
    let oEditControlsObserver = null;
    let oToolbarController = null;
    let oPickerController = null;
    let oAboutController = null;
    let oShareDialog = null;
    let oSidebarReturnFocus = null;
    let bLoadingPage = false;
    let oMenuReturnFocus = null;
    let oAppMenuReturnFocus = null;
    let oSaveQueue = Promise.resolve(true);
    let nEditRevision = 0;
    let aSearchResults = null;
    let nSearchTimer = 0;
    let bInitialNavigationDone = false;
    let bRecentLoaded = false;
    let oRevisionWatcher = null;
    let bSavingPage = false;
    let bHandlingRemoteRevision = false;
    let nDirtyWatchTimer = 0;
    let bTreeDragEnabled = false;
    let bTreeSettingsLoaded = false;
    let sMenuDensity = 'compact';
    let sPageHeaderMode = 'scroll';
    let oSidebarSections = {
        pinned: { visible: true, collapsed: false },
        recent: { visible: true, collapsed: false },
        frequent: { visible: false, collapsed: false },
        tags: { visible: true, collapsed: false },
        pages: { visible: true, collapsed: false },
    };
    let sDraggedPageId = '';
    let nTreePointerId = null;
    let oTreePointerHandle = null;
    let oTreePointerRow = null;
    let oTreeDragGhost = null;
    let nTreeHoverExpandTimer = 0;
    let sTreeHoverExpandPageId = '';
    let nSidebarResizePointerId = null;
    const oCollapsedPageIds = new Set();

    /** @param {string} sId @returns {any} */
    /** CZ-FUNKCE: Vyhledá DOM prvek podle jeho ID. Vstup: `sId`. Výstup: DOM prvek nebo null. */
    const fnById = (sId) => document.getElementById(sId);
    /** CZ-FUNKCE: Ošetří text tak, aby jej bylo možné bezpečně vložit do HTML. Vstup: `sValue`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnEscapeHtml = (sValue) => String(sValue ?? '').replace(/[&<>"']/g, (sCharacter) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[sCharacter]));

    /** CZ-FUNKCE: Zobrazí uživateli chybové hlášení v jednotném formátu aplikace. Vstup: `oError`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnNotifyError = (oError) => {
        const sMessage = oError instanceof Error ? oError.message : String(oError);
        oLogger.error(sMessage, { oError });
        oCore.notifications.error(sMessage, {
            dedupeKey: 'hc_homewiki:error:' + sMessage,
            cooldownMs: 1200,
        });
    };

    /** CZ-FUNKCE: Provede požadavek na API Home Wiki a sjednotí zpracování odpovědi a chyb. Vstup: `sPath`, `oOptions = {}`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnApi = async (sPath, oOptions = {}) => {
        const bFormData = oOptions.body instanceof FormData;
        const sRequestToken = OC.requestToken || /** @type {any} */ (document.querySelector('meta[name="csrf-token"]'))?.content || '';
        const oResponse = await fetch(OC.generateUrl('/apps/' + sAppId + sPath), {
            cache: 'no-store',
            credentials: 'same-origin',
            ...oOptions,
            headers: {
                Accept: 'application/json',
                ...(sRequestToken ? { requesttoken: sRequestToken } : {}),
                ...(bFormData ? {} : { 'Content-Type': 'application/json' }),
                ...(oOptions.headers || {}),
            },
        });

        if (oOptions.raw) {
            return oResponse;
        }

        const sResponseText = await oResponse.text();
        let oData = {};
        try {
            oData = sResponseText ? JSON.parse(sResponseText) : {};
        } catch (oParseError) {
            oLogger.warn('Home Wiki API returned non-JSON data.', { sPath, oParseError });
        }

        if (!oResponse.ok) {
            const oError = new Error(typeof oData.error === 'string' && oData.error
                ? oData.error : t(sAppId, 'Request failed.') + ' HTTP ' + oResponse.status);
            oError.status = oResponse.status;
            oError.response = oResponse;
            throw oError;
        }
        return oData;
    };

    /** CZ-FUNKCE: Převede text na normalizovaný slug používaný Wiki. Vstup: `sValue`. Výstup: řetězec. */
    const fnSlug = (sValue) => String(sValue || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9/_-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^[-/]+|[-/]+$/g, '') || 'page';

    /** CZ-FUNKCE: Ověří a normalizuje bezpečnou HTTP nebo HTTPS adresu. Vstup: `sValue`. Výstup: bezpečná URL nebo prázdná hodnota. */
    const fnSafeHttpUrl = (sValue) => {
        try {
            const oUrl = new URL(String(sValue || '').trim(), window.location.href);
            return ['http:', 'https:'].includes(oUrl.protocol) ? oUrl.href : '';
        } catch (oError) {
            return '';
        }
    };

    /** CZ-FUNKCE: Vyhledá načtenou Wiki stránku podle jejího aliasu. Vstup: `sAlias`. Výstup: objekt stránky nebo null. */
    const fnPageByAlias = (sAlias) => {
        const sCleanAlias = String(sAlias || '').replace(/^\/+|\/+$/g, '');
        return aPages.find((oPage) => oPage.slug === sCleanAlias
            || String(oPage.title).toLowerCase() === sCleanAlias.toLowerCase());
    };

    /** CZ-FUNKCE: Sestaví bezpečnou URL pro médium použité ve Wiki. Vstup: `sSource`. Výstup: URL média nebo prázdná hodnota. */
    const fnMediaUrl = (sSource) => {
        if (!oCurrentPage || !String(sSource).startsWith('../media/')) {
            return fnSafeHttpUrl(sSource) || '';
        }
        const sRelative = String(sSource).replace(/^\.\.\/media\//, '');
        if (!/^(?:[0-9a-f-]{36}|[0-9a-f]{12})\/[^/]+$/i.test(sRelative)) return '';
        const [sFolder, sEncodedName] = sRelative.split('/');
        const sPageId = String(oCurrentPage.id);
        if (sFolder !== sPageId && sFolder !== sPageId.replace(/-/g, '').slice(0, 12)) return '';
        let sName;
        try { sName = decodeURIComponent(sEncodedName); }
        catch { return ''; }
        if (!sName || sName.startsWith('.') || /[\\/\x00-\x1f\x7f]/.test(sName)) return '';
        return OC.generateUrl('/apps/' + sAppId + '/api/pages/'
            + encodeURIComponent(sPageId) + '/media/' + encodeURIComponent(sName));
    };

    /** CZ-FUNKCE: Doplní do vyrenderovaného náhledu chování interních Wiki odkazů. Vstup: `oFragment`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnDecorateWikiPreview = (oFragment) => {
        const oWalker = document.createTreeWalker(oFragment, NodeFilter.SHOW_TEXT);
        const aTextNodes = [];
        while (oWalker.nextNode()) aTextNodes.push(oWalker.currentNode);
        for (const oTextNode of aTextNodes) {
            const sText = String(oTextNode.nodeValue || '');
            const oPattern = /\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g;
            let nOffset = 0; let oMatch = null; let bChanged = false;
            const oReplacement = document.createDocumentFragment();
            while ((oMatch = oPattern.exec(sText)) !== null) {
                bChanged = true;
                oReplacement.append(document.createTextNode(sText.slice(nOffset, oMatch.index)));
                const sAlias = String(oMatch[1] || '').trim();
                const oPage = fnPageByAlias(sAlias);
                const oLink = document.createElement('a');
                oLink.href = '#';
                oLink.className = 'hc-homewiki-internal-link' + (oPage ? '' : ' hc-homewiki-unresolved');
                oLink.dataset.page = String(oPage?.id || '');
                oLink.dataset.alias = sAlias;
                oLink.textContent = String(oMatch[2] || oPage?.title || sAlias);
                oReplacement.append(oLink);
                nOffset = oPattern.lastIndex;
            }
            if (bChanged) {
                oReplacement.append(document.createTextNode(sText.slice(nOffset)));
                oTextNode.replaceWith(oReplacement);
            }
        }
    };

    /** CZ-FUNKCE: Vyrenderuje Markdown do cílového prvku pomocí editoru Shared App Core. Vstup: `oHost`, `sMarkdown`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRenderCoreMarkdown = (oHost, sMarkdown) => {
        if (!oHost) return;
        oHost.classList.add('hc-core-markdown');
        const oFragment = oCore.editor.render(String(sMarkdown || ''), fnMediaUrl);
        fnDecorateWikiPreview(oFragment, String(sMarkdown || ''));
        oHost.replaceChildren(oFragment);
    };

    /** CZ-FUNKCE: Vrátí aktuální textový obsah editoru. Vstup: žádný přímý parametr. Výstup: řetězec s obsahem editoru. */
    const fnCurrentSource = () => oCoreEditor?.getValue() ?? '';

    /** CZ-FUNKCE: Aktualizuje živý náhled právě editovaného Markdownu. Vstup: `sMarkdown = fnCurrentSource()`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRenderLivePreview = (sMarkdown = fnCurrentSource()) => {
        const oLivePreview = fnById('hc-homewiki-live-preview');
        if (!oLivePreview) return;
        fnRenderCoreMarkdown(oLivePreview, String(sMarkdown || ''));
    };

    /** CZ-FUNKCE: Přepočítá výšky prvků potřebné pro sticky záhlaví stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnSyncStickyHeight = () => {
        const oHead = document.querySelector('.hc-homewiki-page-head');
        const oControls = fnById('hc-homewiki-edit-controls');
        const oPage = fnById('hc-homewiki-page');
        if (!oPage) return;
        if (oHead) {
            const nHeadHeight = oHead.getBoundingClientRect().height;
            if (nHeadHeight > 0) oPage.style.setProperty('--hc-homewiki-page-head-height', nHeadHeight + 'px');
        }
        if (oControls && !oControls.hidden) {
            const nHeight = oControls.getBoundingClientRect().height;
            if (nHeight > 0) oPage.style.setProperty('--hc-homewiki-edit-controls-height', nHeight + 'px');
        }
    };

    /** CZ-FUNKCE: Přepne aplikaci mezi režimy zobrazení a editace. Vstup: `sNewMode`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnSetMode = (sNewMode) => {
        if (!oCurrentPage) {
            return;
        }

        const oPreview = fnById('hc-homewiki-preview');
        const oControls = fnById('hc-homewiki-edit-controls');
        const oToolbarRow = fnById('hc-homewiki-editor-toolbar-row');
        const oEdit = fnById('hc-homewiki-edit-page');
        const oSidePreview = fnById('hc-homewiki-side-preview');
        const oCoreEditorHost = fnById('hc-homewiki-core-editor');
        const oLiveEdit = fnById('hc-homewiki-live-edit');
        const oLivePreview = fnById('hc-homewiki-live-preview');
        if (!oPreview || !oControls || !oToolbarRow || !oEdit || !oSidePreview || !oCoreEditorHost || !oLiveEdit || !oLivePreview) {
            return;
        }
        if (!['read', 'edit', 'preview'].includes(sNewMode)) return;
        sEditorMode = sNewMode;
        document.querySelectorAll('.hc-homewiki-tab').forEach((oTab) => {
            oTab.classList.toggle('active', (/** @type {any} */ (oTab)).dataset.mode === sNewMode);
        });
        oControls.hidden = sNewMode === 'read';
        oToolbarRow.hidden = sNewMode !== 'edit';
        oEdit.hidden = !oCurrentPage.permissions.edit || sNewMode !== 'read';
        oLiveEdit.hidden = sNewMode !== 'edit';
        oCoreEditorHost.hidden = false;
        oPreview.hidden = sNewMode === 'edit';
        oSidePreview.hidden = sNewMode !== 'edit';
        oSidePreview.setAttribute('aria-pressed', String(bSidePreview));
        oLiveEdit.classList.toggle('hc-homewiki-preview-off', !bSidePreview);
        oLivePreview.hidden = true;
        if (sNewMode === 'edit') oCoreEditor?.setPreview(bSidePreview);
        if (sNewMode === 'edit') {
            oCoreEditor?.setPreview(bSidePreview);
            oLivePreview.hidden = true;
        } else {
            fnRenderCoreMarkdown(oPreview, sNewMode === 'read'
                ? String(oCurrentPage.content || '') : fnCurrentSource());
        }
        requestAnimationFrame(fnSyncStickyHeight);
    };

    /** CZ-FUNKCE: Ukončí režim editace a bezpečně dokončí rozpracované uložení. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnFinishEditing = async () => {
        if (!(await oSaveQueue) && bDirty) return;
        if (bDirty && !(await fnSave())) return;
        fnSetMode('read');
        const oMain = fnById('hc-homewiki-main');
        if (oMain) oMain.scrollTop = 0;
    };

    /** CZ-FUNKCE: Nastaví vizuální stav ukládání stránky. Vstup: `sState`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnSetSaveState = (sState) => {
        const oState = fnById('hc-homewiki-save-state');
        if (oState) {
            oState.textContent = sState;
        }
    };


    /** CZ-FUNKCE: Vrátí lokální klíč aktuálního uživatele pro per-user nastavení prohlížeče. Vstup: žádný přímý parametr. Výstup: řetězec. */
    const fnCurrentUserLocalKey = () => {
        try {
            return String(OC?.getCurrentUser?.()?.uid || 'default');
        } catch (oError) {
            oLogger.debug('Home Wiki could not determine the current user for local UI state.', { oError });
            return 'default';
        }
    };

    /** CZ-FUNKCE: Sestaví klíč localStorage pro šířku postranního panelu. Vstup: žádný přímý parametr. Výstup: řetězec. */
    const fnSidebarWidthStorageKey = () => sAppId + ':sidebar-width:' + fnCurrentUserLocalKey();

    /** CZ-FUNKCE: Vrátí povolené minimální a maximální rozměry postranního panelu. Vstup: žádný přímý parametr. Výstup: objekt s limity šířky. */
    const fnSidebarWidthLimits = () => {
        const oApp = fnById('hc-homewiki-app');
        const nAvailable = Math.max(0, Number(oApp?.getBoundingClientRect?.().width || 0));
        const nMin = 220;
        const nMax = Math.max(nMin, Math.min(640, Math.floor(nAvailable * 0.58) || 640));
        return { nMin, nMax };
    };

    /** CZ-FUNKCE: Nastaví šířku postranního panelu a volitelně ji uloží. Vstup: `nWidth`, `bPersist = false`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnSetSidebarWidth = (nWidth, bPersist = false) => {
        const oApp = fnById('hc-homewiki-app');
        const oResizer = fnById('hc-homewiki-sidebar-resizer');
        if (!oApp || oApp.dataset.coreLayout === 'mobile' || oApp.dataset.coreLayout === 'tablet') return;
        const { nMin, nMax } = fnSidebarWidthLimits();
        const nSafeWidth = Math.max(nMin, Math.min(nMax, Math.round(Number(nWidth) || 0)));
        oApp.style.setProperty('--hc-homewiki-sidebar-width', nSafeWidth + 'px');
        if (oResizer) {
            oResizer.setAttribute('aria-valuemin', String(nMin));
            oResizer.setAttribute('aria-valuemax', String(nMax));
            oResizer.setAttribute('aria-valuenow', String(nSafeWidth));
        }
        if (bPersist) {
            try { window.localStorage.setItem(fnSidebarWidthStorageKey(), String(nSafeWidth)); }
            catch (oError) { oLogger.debug('Home Wiki could not persist sidebar width.', { oError }); }
        }
    };

    /** CZ-FUNKCE: Obnoví uloženou šířku postranního panelu. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRestoreSidebarWidth = () => {
        try {
            const nStored = Number(window.localStorage.getItem(fnSidebarWidthStorageKey()) || 0);
            if (Number.isFinite(nStored) && nStored > 0) fnSetSidebarWidth(nStored, false);
        } catch (oError) {
            oLogger.debug('Home Wiki sidebar width is not available.', { oError });
        }
    };

    /** CZ-FUNKCE: Přepočítá šířku postranního panelu z vodorovné pozice ukazatele. Vstup: `nClientX`, `bPersist = false`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnResizeSidebarFromClientX = (nClientX, bPersist = false) => {
        const oApp = fnById('hc-homewiki-app');
        if (!oApp || oApp.dataset.coreLayout === 'mobile' || oApp.dataset.coreLayout === 'tablet') return;
        const oRect = oApp.getBoundingClientRect();
        fnSetSidebarWidth(Number(nClientX) - oRect.left, bPersist);
    };

    /** CZ-FUNKCE: Sestaví klíč localStorage pro stav rozbalení stromu stránek. Vstup: žádný přímý parametr. Výstup: řetězec. */
    const fnTreeCollapseStorageKey = () => sAppId + ':tree-collapsed:' + fnCurrentUserLocalKey();

    /** CZ-FUNKCE: Načte lokálně uložený stav sbalených větví stromu. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnLoadCollapsedTreeState = () => {
        oCollapsedPageIds.clear();
        try {
            const aStored = JSON.parse(window.localStorage.getItem(fnTreeCollapseStorageKey()) || '[]');
            if (Array.isArray(aStored)) {
                for (const sId of aStored.slice(0, 500)) {
                    if (/^[0-9a-f-]{36}$/i.test(String(sId))) oCollapsedPageIds.add(String(sId));
                }
            }
        } catch (oError) {
            oLogger.debug('Home Wiki collapsed-tree state is not available.', { oError });
        }
    };

    /** CZ-FUNKCE: Uloží stav sbalených větví stromu do prohlížeče. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnPersistCollapsedTreeState = () => {
        try {
            window.localStorage.setItem(fnTreeCollapseStorageKey(), JSON.stringify(Array.from(oCollapsedPageIds).slice(0, 500)));
        } catch (oError) {
            oLogger.debug('Home Wiki could not persist collapsed-tree state.', { oError });
        }
    };

    /** CZ-FUNKCE: Normalizuje uživatelské nastavení sekcí postranního panelu. Vstup: `oInput = {}`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnNormalizeSidebarSections = (oInput = {}) => {
        const oDefaults = {
            pinned: { visible: true, collapsed: false },
            recent: { visible: true, collapsed: false },
            frequent: { visible: false, collapsed: false },
            tags: { visible: true, collapsed: false },
            pages: { visible: true, collapsed: false },
        };
        const oNext = {};
        for (const [sKey, oDefault] of Object.entries(oDefaults)) {
            const oSaved = oInput && typeof oInput[sKey] === 'object' ? oInput[sKey] : {};
            oNext[sKey] = {
                visible: Object.prototype.hasOwnProperty.call(oSaved, 'visible') ? !!oSaved.visible : oDefault.visible,
                collapsed: Object.prototype.hasOwnProperty.call(oSaved, 'collapsed') ? !!oSaved.collapsed : oDefault.collapsed,
            };
        }
        return oNext;
    };

    /** CZ-FUNKCE: Aplikuje zvolenou hustotu rozhraní na menu a strom stránek. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnApplyMenuDensity = () => {
        const oApp = fnById('hc-homewiki-app');
        if (!oApp) return;
        oApp.dataset.menuDensity = sMenuDensity === 'comfortable' ? 'comfortable' : 'compact';
    };

    /** CZ-FUNKCE: Aplikuje režim rolovacího nebo sticky záhlaví stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnApplyPageHeaderMode = () => {
        const oApp = fnById('hc-homewiki-app');
        if (!oApp) return;
        oApp.dataset.pageHeaderMode = sPageHeaderMode === 'sticky' ? 'sticky' : 'scroll';
        window.requestAnimationFrame(fnSyncStickyHeight);
    };

    /** CZ-FUNKCE: Aplikuje uživatelské nastavení načtené při startu aplikace. Vstup: `oSettings`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnApplyStartupSettings = (oSettings) => {
        if (!oSettings || typeof oSettings !== 'object') return;
        bTreeDragEnabled = !!oSettings.treeDragEnabled;
        sMenuDensity = String(oSettings.menuDensity || 'compact') === 'comfortable' ? 'comfortable' : 'compact';
        sPageHeaderMode = String(oSettings.pageHeaderMode || 'scroll') === 'sticky' ? 'sticky' : 'scroll';
        oSidebarSections = fnNormalizeSidebarSections(oSettings.sidebarSections || oSidebarSections);
        bTreeSettingsLoaded = true;
        fnApplyMenuDensity();
        fnApplyPageHeaderMode();
        fnUpdateTreeDragToggle();
    };

    /** CZ-FUNKCE: Uloží příslušný stav pro další použití. Vstup: `oPrevious = null`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnPersistSidebarSections = async (oPrevious = null) => {
        try {
            const oSaved = await fnApi('/api/settings', {
                method: 'PUT',
                body: JSON.stringify({ sidebarSections: oSidebarSections }),
            });
            oSidebarSections = fnNormalizeSidebarSections(oSaved.sidebarSections || oSidebarSections);
            fnRenderNav();
            return true;
        } catch (oError) {
            if (oPrevious) oSidebarSections = fnNormalizeSidebarSections(oPrevious);
            fnRenderNav();
            fnNotifyError(oError);
            return false;
        }
    };

    /** CZ-FUNKCE: Nastaví příslušný stav aplikace. Vstup: `sKey`, `bCollapsed`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnSetSidebarSectionCollapsed = async (sKey, bCollapsed) => {
        if (!oSidebarSections[sKey]) return false;
        const oPrevious = fnNormalizeSidebarSections(oSidebarSections);
        oSidebarSections[sKey].collapsed = !!bCollapsed;
        fnRenderNav();
        return fnPersistSidebarSections(oPrevious);
    };

    /** CZ-FUNKCE: Nastaví příslušný stav aplikace. Vstup: `sKey`, `bVisible`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnSetSidebarSectionVisible = async (sKey, bVisible) => {
        if (!oSidebarSections[sKey]) return false;
        const oPrevious = fnNormalizeSidebarSections(oSidebarSections);
        oSidebarSections[sKey].visible = !!bVisible;
        if (bVisible) oSidebarSections[sKey].collapsed = false;
        fnRenderNav();
        return fnPersistSidebarSections(oPrevious);
    };

    /** CZ-FUNKCE: Nastaví příslušný stav aplikace. Vstup: `bCollapsed`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnSetAllSidebarSectionsCollapsed = async (bCollapsed) => {
        const oPrevious = fnNormalizeSidebarSections(oSidebarSections);
        for (const oState of Object.values(oSidebarSections)) oState.collapsed = !!bCollapsed;
        fnRenderNav();
        return fnPersistSidebarSections(oPrevious);
    };

    /** CZ-FUNKCE: Aplikuje příslušné nastavení nebo změnu. Vstup: `sKey`, `{ available = true, forceVisible = false, forceExpanded = false } = {}`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnApplySidebarSectionDisplay = (sKey, { available = true, forceVisible = false, forceExpanded = false } = {}) => {
        const oSection = fnById('hc-homewiki-' + sKey + '-section');
        if (!oSection) return;
        const oState = oSidebarSections[sKey] || { visible: true, collapsed: false };
        const bVisible = !!available && (forceVisible || !!oState.visible);
        oSection.hidden = !bVisible;
        if (!bVisible) return;
        const bCollapsed = !forceExpanded && !!oState.collapsed;
        const oBody = oSection.querySelector('.hc-homewiki-section-body');
        const oToggle = oSection.querySelector('[data-section-toggle="' + sKey + '"]');
        if (oBody) oBody.hidden = bCollapsed;
        if (oToggle) {
            oToggle.textContent = bCollapsed ? '▸' : '▾';
            oToggle.setAttribute('aria-expanded', bCollapsed ? 'false' : 'true');
            oToggle.title = t(sAppId, bCollapsed ? 'Expand section' : 'Collapse section');
        }
        oSection.classList.toggle('hc-homewiki-section-collapsed', bCollapsed);
    };

    /** CZ-FUNKCE: Aktualizuje ovládací prvek zapnutí přesouvání stránek ve stromu. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnUpdateTreeDragToggle = () => {
        const oToggle = fnById('hc-homewiki-tree-drag-toggle');
        const oApp = fnById('hc-homewiki-app');
        if (!oToggle || !oApp) return;
        oToggle.textContent = bTreeDragEnabled ? '🔓' : '🔒';
        oToggle.setAttribute('aria-pressed', bTreeDragEnabled ? 'true' : 'false');
        oToggle.title = t(sAppId, bTreeDragEnabled
            ? 'Disable drag-and-drop tree organization'
            : 'Enable drag-and-drop tree organization');
        oApp.classList.toggle('hc-homewiki-tree-organize', bTreeDragEnabled);
    };

    /** CZ-FUNKCE: Nastaví příslušný stav aplikace. Vstup: `bEnabled`, `bPersist = true`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnSetTreeDragEnabled = async (bEnabled, bPersist = true) => {
        const bPrevious = bTreeDragEnabled;
        bTreeDragEnabled = !!bEnabled;
        fnUpdateTreeDragToggle();
        fnRenderNav();
        if (!bPersist) return true;
        try {
            await fnApi('/api/settings', {
                method: 'PUT',
                body: JSON.stringify({ treeDragEnabled: bTreeDragEnabled }),
            });
            return true;
        } catch (oError) {
            bTreeDragEnabled = bPrevious;
            fnUpdateTreeDragToggle();
            fnRenderNav();
            fnNotifyError(oError);
            return false;
        }
    };

    /** CZ-FUNKCE: Rozbalí nebo sbalí větev stromu stránek. Vstup: `sPageId`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnToggleTreeBranch = (sPageId) => {
        const sId = String(sPageId || '');
        if (!sId) return;
        if (oCollapsedPageIds.has(sId)) oCollapsedPageIds.delete(sId);
        else oCollapsedPageIds.add(sId);
        fnPersistCollapsedTreeState();
        fnRenderNav();
    };

    /** CZ-FUNKCE: Rozbalí potřebné předky a zviditelní zadanou stránku ve stromu. Vstup: `sPageId`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRevealPageInTree = (sPageId) => {
        let sNext = String(sPageId || '');
        let bChanged = false;
        const oVisited = new Set();
        while (sNext && !oVisited.has(sNext)) {
            oVisited.add(sNext);
            const oPage = aPages.find((oCandidate) => String(oCandidate.id) === sNext);
            if (!oPage) break;
            const sParentId = String(oPage.parent_id || '');
            if (sParentId && oCollapsedPageIds.delete(sParentId)) bChanged = true;
            sNext = sParentId;
        }
        if (bChanged) fnPersistCollapsedTreeState();
    };

    /** CZ-FUNKCE: Zjistí, zda je jedna stránka potomkem jiné stránky. Vstup: `sCandidateId`, `sAncestorId`. Výstup: boolean. */
    const fnIsDescendantOf = (sCandidateId, sAncestorId) => {
        let sNext = String(sCandidateId || '');
        const oVisited = new Set();
        while (sNext && !oVisited.has(sNext)) {
            oVisited.add(sNext);
            const oPage = aPages.find((oCandidate) => String(oCandidate.id) === sNext);
            if (!oPage) return false;
            const sParentId = String(oPage.parent_id || '');
            if (sParentId === String(sAncestorId || '')) return true;
            sNext = sParentId;
        }
        return false;
    };

    /** CZ-FUNKCE: Ověří, zda lze přesouvanou stránku vložit pod cílovou stránku. Vstup: `oMovedPage`, `oParentPage`. Výstup: boolean. */
    const fnCanDropUnder = (oMovedPage, oParentPage) => {
        if (!oMovedPage?.permissions?.edit) return false;
        if (!oParentPage) return true;
        if (String(oMovedPage.id) === String(oParentPage.id)) return false;
        if (String(oMovedPage.owner_uid) !== String(oParentPage.owner_uid)) return false;
        if (fnIsDescendantOf(String(oParentPage.id), String(oMovedPage.id))) return false;
        return true;
    };

    /** CZ-FUNKCE: Odstraní vizuální značky cíle drag-and-drop ve stromu. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnClearTreeDropMarkers = () => {
        document.querySelectorAll('.hc-homewiki-tree-drop-target, .hc-homewiki-tree-root-drop-active')
            .forEach((oElement) => oElement.classList.remove('hc-homewiki-tree-drop-target', 'hc-homewiki-tree-root-drop-active'));
    };

    /** CZ-FUNKCE: Zruší naplánované automatické rozbalení cílové větve stromu. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnClearTreeHoverExpand = () => {
        if (nTreeHoverExpandTimer) window.clearTimeout(nTreeHoverExpandTimer);
        nTreeHoverExpandTimer = 0;
        sTreeHoverExpandPageId = '';
    };

    /** CZ-FUNKCE: Odstraní vizuální kopii stránky používanou při přetahování. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRemoveTreeDragGhost = () => {
        oTreeDragGhost?.remove();
        oTreeDragGhost = null;
    };

    /** CZ-FUNKCE: Umístí vizuální kopii přetahované stránky k ukazateli. Vstup: `nClientX`, `nClientY`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnPositionTreeDragGhost = (nClientX, nClientY) => {
        if (!oTreeDragGhost) return;
        const oRect = oTreeDragGhost.getBoundingClientRect();
        const nMaxLeft = Math.max(8, window.innerWidth - Math.max(24, oRect.width) - 8);
        const nMaxTop = Math.max(8, window.innerHeight - Math.max(24, oRect.height) - 8);
        const nLeft = Math.min(nMaxLeft, Math.max(8, Number(nClientX) + 14));
        const nTop = Math.min(nMaxTop, Math.max(8, Number(nClientY) + 12));
        oTreeDragGhost.style.left = nLeft + 'px';
        oTreeDragGhost.style.top = nTop + 'px';
    };

    /** CZ-FUNKCE: Vytvoří vizuální kopii stránky pro pointer drag-and-drop. Vstup: `oPage`, `nClientX`, `nClientY`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnCreateTreeDragGhost = (oPage, nClientX, nClientY) => {
        fnRemoveTreeDragGhost();
        const oGhost = document.createElement('div');
        oGhost.className = 'hc-homewiki-tree-drag-ghost';
        oGhost.setAttribute('aria-hidden', 'true');
        const oIcon = document.createElement('span');
        oIcon.className = 'hc-homewiki-tree-drag-ghost-icon';
        oIcon.textContent = oPage?.pinned ? '📌' : '📄';
        const oTitle = document.createElement('span');
        oTitle.className = 'hc-homewiki-tree-drag-ghost-title';
        oTitle.textContent = String(oPage?.title || '');
        oGhost.append(oIcon, oTitle);
        document.body.append(oGhost);
        oTreeDragGhost = oGhost;
        fnPositionTreeDragGhost(nClientX, nClientY);
    };

    /** CZ-FUNKCE: Při přetahování automaticky posune strom poblíž jeho okraje. Vstup: `nClientY`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnAutoScrollTreeDrag = (nClientY) => {
        const oScroll = fnById('hc-homewiki-side-scroll');
        if (!oScroll) return;
        const oRect = oScroll.getBoundingClientRect();
        const nEdge = Math.min(56, Math.max(34, oRect.height * .12));
        if (Number(nClientY) < oRect.top + nEdge) {
            oScroll.scrollTop -= Math.max(8, Math.round((oRect.top + nEdge - Number(nClientY)) / 3));
        } else if (Number(nClientY) > oRect.bottom - nEdge) {
            oScroll.scrollTop += Math.max(8, Math.round((Number(nClientY) - (oRect.bottom - nEdge)) / 3));
        }
    };

    /** CZ-FUNKCE: Naplánuje rozbalení cílové větve při najetí během přetahování. Vstup: `oTarget`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnScheduleTreeTargetExpand = (oTarget) => {
        const sPageId = oTarget?.type === 'page' ? String(oTarget.parentId || '') : '';
        if (!sPageId || !oCollapsedPageIds.has(sPageId)) {
            fnClearTreeHoverExpand();
            return;
        }
        if (sTreeHoverExpandPageId === sPageId && nTreeHoverExpandTimer) return;
        fnClearTreeHoverExpand();
        sTreeHoverExpandPageId = sPageId;
        nTreeHoverExpandTimer = window.setTimeout(() => {
            nTreeHoverExpandTimer = 0;
            if (!sDraggedPageId || sTreeHoverExpandPageId !== sPageId || !oCollapsedPageIds.has(sPageId)) return;
            oCollapsedPageIds.delete(sPageId);
            fnPersistCollapsedTreeState();
            const oNode = Array.from(document.querySelectorAll('.hc-homewiki-tree-node'))
                .find((oCandidate) => String(oCandidate.dataset.pageId || '') === sPageId);
            const oChildren = oNode?.querySelector(':scope > .hc-homewiki-nav-child');
            if (oChildren) oChildren.hidden = false;
            const oToggle = oNode?.querySelector(':scope > .hc-homewiki-nav-item .hc-homewiki-tree-toggle');
            if (oToggle) {
                oToggle.textContent = '▾';
                oToggle.setAttribute('aria-expanded', 'true');
            }
        }, 650);
    };

    /** CZ-FUNKCE: Provede přesun stránky na nové místo ve stromu. Vstup: `sMovedId`, `sParentId = ''`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnMovePageByDrag = async (sMovedId, sParentId = '') => {
        let oMovedPage = aPages.find((oPage) => String(oPage.id) === String(sMovedId));
        const oParentPage = sParentId ? aPages.find((oPage) => String(oPage.id) === String(sParentId)) : null;
        if (!oMovedPage || !fnCanDropUnder(oMovedPage, oParentPage)) return false;
        if (String(oMovedPage.parent_id || '') === String(sParentId || '')) return true;

        if (String(oCurrentPage?.id || '') === String(oMovedPage.id)) {
            if ((bDirty || fnHasLocalEditorChanges()) && !(await fnSave())) return false;
            oMovedPage = oCurrentPage || oMovedPage;
        }

        try {
            const oPayload = oCore.concurrency.withExpectedRevision({
                parentId: sParentId || null,
            }, Number(oMovedPage.revision || 1));
            const oSaved = await fnApi('/api/pages/' + encodeURIComponent(String(oMovedPage.id)), {
                method: 'PUT',
                body: JSON.stringify(oPayload),
            });

            if (String(oCurrentPage?.id || '') === String(oSaved.id)) {
                Object.assign(oCurrentPage, oSaved);
                if (oEditBase) oEditBase.revision = Number(oSaved.revision || oEditBase.revision || 1);
                oRevisionWatcher?.setRevision(Number(oSaved.revision || 1));
            }
            if (sParentId) oCollapsedPageIds.delete(String(sParentId));
            fnPersistCollapsedTreeState();
            await fnLoadPages();
            oCore.notifications.success(t(sAppId, 'Page moved in the tree.'), {
                dedupeKey: 'hc_homewiki:tree-move',
                cooldownMs: 700,
            });
            return true;
        } catch (oError) {
            await fnLoadPages().catch(() => {});
            if (oCore.concurrency.isConflict(oError)) {
                oCore.notifications.warning(t(sAppId, 'The page changed while it was being moved. Try the move again.'));
                return false;
            }
            fnNotifyError(oError);
            return false;
        }
    };

    /** CZ-FUNKCE: Určí aktuální cíl pointer drag-and-drop ve stromu. Vstup: `nClientX`, `nClientY`. Výstup: cílový objekt nebo null. */
    const fnTreePointerTarget = (nClientX, nClientY) => {
        const oElement = document.elementFromPoint(Number(nClientX), Number(nClientY));
        if (!(oElement instanceof Element)) return null;

        const oRootDrop = oElement.closest('.hc-homewiki-tree-root-drop');
        if (oRootDrop) return { type: 'root', element: oRootDrop, parentId: '' };

        const oRow = oElement.closest('.hc-homewiki-nav-item');
        if (!oRow) return null;
        const sParentId = String(oRow.dataset.pageId || '');
        if (!sParentId || sParentId === String(sDraggedPageId || '')) return null;
        const oParentPage = aPages.find((oPage) => String(oPage.id) === sParentId);
        const oMovedPage = aPages.find((oPage) => String(oPage.id) === String(sDraggedPageId));
        if (!oMovedPage || !oParentPage || !fnCanDropUnder(oMovedPage, oParentPage)) return null;
        return { type: 'page', element: oRow, parentId: sParentId };
    };

    /** CZ-FUNKCE: Vyznačí právě vybraný cíl pointer drag-and-drop. Vstup: `oTarget`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnMarkTreePointerTarget = (oTarget) => {
        fnClearTreeDropMarkers();
        fnScheduleTreeTargetExpand(oTarget);
        if (!oTarget?.element) return;
        if (oTarget.type === 'root') oTarget.element.classList.add('hc-homewiki-tree-root-drop-active');
        else oTarget.element.classList.add('hc-homewiki-tree-drop-target');
    };

    /** CZ-FUNKCE: Dokončí nebo zruší probíhající pointer drag-and-drop. Vstup: `oEvent`, `bCommit`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnFinishPointerTreeDrag = async (oEvent, bCommit) => {
        if (nTreePointerId === null || Number(oEvent.pointerId) !== Number(nTreePointerId)) return;
        const oTarget = bCommit ? fnTreePointerTarget(oEvent.clientX, oEvent.clientY) : null;
        const sMovedId = String(sDraggedPageId || '');
        try {
            if (oTreePointerHandle?.hasPointerCapture?.(nTreePointerId)) {
                oTreePointerHandle.releasePointerCapture(nTreePointerId);
            }
        } catch {}
        oTreePointerRow?.classList.remove('hc-homewiki-tree-dragging');
        fnById('hc-homewiki-app')?.classList.remove('hc-homewiki-tree-drag-active');
        fnClearTreeHoverExpand();
        fnClearTreeDropMarkers();
        fnRemoveTreeDragGhost();
        nTreePointerId = null;
        oTreePointerHandle = null;
        oTreePointerRow = null;
        sDraggedPageId = '';
        if (bCommit && sMovedId && oTarget) {
            await fnMovePageByDrag(sMovedId, String(oTarget.parentId || ''));
        }
    };

    /** CZ-FUNKCE: Vytvoří jeden DOM řádek stránky v navigačním stromu. Vstup: `oPage`, `oOptions = {}`. Výstup: DOM prvek řádku. */
    const fnCreateNavRow = (oPage, oOptions = {}) => {
        const bTree = !!oOptions.tree;
        const bHasChildren = !!oOptions.hasChildren;
        const bSearchMode = !!oOptions.searchMode;
        const bCollapsed = bTree && bHasChildren && !bSearchMode && oCollapsedPageIds.has(String(oPage.id));

        const oRow = document.createElement('div');
        oRow.className = 'hc-homewiki-nav-item';
        if (oCurrentPage?.id === oPage.id) oRow.classList.add('active');
        oRow.dataset.pageId = String(oPage.id);

        if (bTree) {
            const oDrag = document.createElement('button');
            oDrag.type = 'button';
            oDrag.className = 'hc-homewiki-tree-drag-handle';
            oDrag.textContent = '⠿';
            oDrag.hidden = !bTreeDragEnabled || bSearchMode || !oPage.permissions.edit;
            const bPointerDrag = 'PointerEvent' in window;
            oDrag.draggable = !oDrag.hidden && !bPointerDrag;
            oDrag.setAttribute('aria-label', t(sAppId, 'Drag page in tree') + ': ' + String(oPage.title));
            oDrag.title = t(sAppId, 'Drag page in tree');
            oDrag.addEventListener('click', (oEvent) => oEvent.preventDefault());

            if (bPointerDrag) {
                oDrag.addEventListener('pointerdown', (oEvent) => {
                    if (!bTreeDragEnabled || bSearchMode || !oPage.permissions.edit) return;
                    if (oEvent.pointerType === 'mouse' && oEvent.button !== 0) return;
                    oEvent.preventDefault();
                    sDraggedPageId = String(oPage.id);
                    nTreePointerId = Number(oEvent.pointerId);
                    oTreePointerHandle = oDrag;
                    oTreePointerRow = oRow;
                    try { oDrag.setPointerCapture(oEvent.pointerId); } catch {}
                    oRow.classList.add('hc-homewiki-tree-dragging');
                    fnById('hc-homewiki-app')?.classList.add('hc-homewiki-tree-drag-active');
                    fnCreateTreeDragGhost(oPage, oEvent.clientX, oEvent.clientY);
                });
                oDrag.addEventListener('pointermove', (oEvent) => {
                    if (nTreePointerId === null || Number(oEvent.pointerId) !== Number(nTreePointerId)) return;
                    oEvent.preventDefault();
                    fnPositionTreeDragGhost(oEvent.clientX, oEvent.clientY);
                    fnAutoScrollTreeDrag(oEvent.clientY);
                    fnMarkTreePointerTarget(fnTreePointerTarget(oEvent.clientX, oEvent.clientY));
                });
                oDrag.addEventListener('pointerup', (oEvent) => {
                    oEvent.preventDefault();
                    void fnFinishPointerTreeDrag(oEvent, true);
                });
                oDrag.addEventListener('pointercancel', (oEvent) => void fnFinishPointerTreeDrag(oEvent, false));
            } else {
                oDrag.addEventListener('dragstart', (oEvent) => {
                    if (!bTreeDragEnabled || bSearchMode || !oPage.permissions.edit) {
                        oEvent.preventDefault();
                        return;
                    }
                    if (!oEvent.dataTransfer) { oEvent.preventDefault(); return; }
                    sDraggedPageId = String(oPage.id);
                    oEvent.dataTransfer.effectAllowed = 'move';
                    oEvent.dataTransfer.setData('text/plain', sDraggedPageId);
                    oRow.classList.add('hc-homewiki-tree-dragging');
                    fnById('hc-homewiki-app')?.classList.add('hc-homewiki-tree-drag-active');
                });
                oDrag.addEventListener('dragend', () => {
                    sDraggedPageId = '';
                    oRow.classList.remove('hc-homewiki-tree-dragging');
                    fnById('hc-homewiki-app')?.classList.remove('hc-homewiki-tree-drag-active');
                    fnClearTreeHoverExpand();
                    fnClearTreeDropMarkers();
                    fnRemoveTreeDragGhost();
                });
            }
            oRow.append(oDrag);

            if (bHasChildren) {
                const oToggle = document.createElement('button');
                oToggle.type = 'button';
                oToggle.className = 'hc-homewiki-tree-toggle';
                oToggle.textContent = bCollapsed ? '▸' : '▾';
                oToggle.setAttribute('aria-expanded', bCollapsed ? 'false' : 'true');
                oToggle.setAttribute('aria-label', (bCollapsed
                    ? t(sAppId, 'Expand subpages')
                    : t(sAppId, 'Collapse subpages')) + ': ' + String(oPage.title));
                oToggle.addEventListener('click', () => fnToggleTreeBranch(String(oPage.id)));
                oRow.append(oToggle);
            } else {
                const oSpacer = document.createElement('span');
                oSpacer.className = 'hc-homewiki-tree-toggle-spacer';
                oSpacer.setAttribute('aria-hidden', 'true');
                oRow.append(oSpacer);
            }
        }

        const oButton = document.createElement('button');
        oButton.className = 'hc-homewiki-nav-row' + (oCurrentPage?.id === oPage.id ? ' active' : '');
        oButton.type = 'button';
        oButton.dataset.id = String(oPage.id);
        oButton.title = String(oPage.title || '');

        const oIcon = document.createElement('span');
        oIcon.textContent = oPage.pinned ? '📌' : '📄';
        const oTitle = document.createElement('span');
        oTitle.className = 'hc-homewiki-nav-title';
        oTitle.textContent = String(oPage.title);
        oTitle.title = String(oPage.title || '');
        const oText = document.createElement('span');
        oText.className = 'hc-homewiki-nav-text';
        oText.append(oTitle);
        if (String(oPage.search_snippet || '').trim()) {
            const oSnippet = document.createElement('small');
            oSnippet.className = 'hc-homewiki-search-snippet';
            oSnippet.textContent = String(oPage.search_snippet);
            oText.append(oSnippet);
        }
        oButton.append(oIcon, oText);

        if (!oPage.permissions.owner) {
            const oShared = document.createElement('small');
            oShared.className = 'hc-homewiki-shared-indicator';
            oShared.textContent = '↗';
            oShared.title = t(sAppId, 'Shared with you');
            oButton.append(oShared);
        }

        oButton.addEventListener('click', () => void fnOpenPage(String(oPage.id)));
        oButton.addEventListener('dblclick', () => void fnOpenPage(String(oPage.id), true));
        oRow.append(oButton);
        if (oOptions.actions !== false) {
            const oActions = document.createElement('button');
            oActions.className = 'hc-homewiki-nav-actions';
            oActions.type = 'button';
            oActions.textContent = '⋯';
            oActions.setAttribute('aria-label', t(sAppId, 'Page actions') + ': ' + String(oPage.title));
            oActions.setAttribute('aria-haspopup', 'menu');
            oActions.setAttribute('aria-expanded', 'false');
            oActions.addEventListener('click', () => fnOpenPageMenu(oPage, oActions));
            oRow.append(oActions);
        }

        if (bTree && bTreeDragEnabled && !bSearchMode) {
            oRow.addEventListener('dragover', (oEvent) => {
                oEvent.stopPropagation();
                const oMovedPage = aPages.find((oCandidate) => String(oCandidate.id) === String(sDraggedPageId));
                if (!oMovedPage || !fnCanDropUnder(oMovedPage, oPage) || !oEvent.dataTransfer) return;
                oEvent.preventDefault();
                oEvent.dataTransfer.dropEffect = 'move';
                fnClearTreeDropMarkers();
                oRow.classList.add('hc-homewiki-tree-drop-target');
            });
            oRow.addEventListener('dragleave', (oEvent) => {
                if (!(oEvent.relatedTarget instanceof Node) || !oRow.contains(oEvent.relatedTarget)) {
                    oRow.classList.remove('hc-homewiki-tree-drop-target');
                }
            });
            oRow.addEventListener('drop', (oEvent) => {
                oEvent.stopPropagation();
                const sMovedId = sDraggedPageId || oEvent.dataTransfer?.getData('text/plain') || '';
                const oMovedPage = aPages.find((oCandidate) => String(oCandidate.id) === String(sMovedId));
                if (!oMovedPage || !fnCanDropUnder(oMovedPage, oPage)) return;
                oEvent.preventDefault();
                fnClearTreeDropMarkers();
                void fnMovePageByDrag(String(oMovedPage.id), String(oPage.id));
            });
        }
        return oRow;
    };

    /** CZ-FUNKCE: Umístí plovoucí nabídku vzhledem k jejímu kotevnímu prvku. Vstup: `oMenu`, `oAnchor`, `nPreferredWidth = 260`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnPositionMenu = (oMenu, oAnchor, nPreferredWidth = 260) => {
        const oRect = oAnchor.getBoundingClientRect();
        const nDensityWidth = sMenuDensity === 'compact' ? Math.max(220, nPreferredWidth - 20) : nPreferredWidth;
        const nWidth = Math.min(nDensityWidth, window.innerWidth - 16);
        oMenu.style.width = nWidth + 'px';
        oMenu.style.left = Math.max(8, Math.min(oRect.right - nWidth, window.innerWidth - nWidth - 8)) + 'px';
        oMenu.style.top = Math.max(8, Math.min(oRect.bottom + 4, window.innerHeight - oMenu.offsetHeight - 8)) + 'px';
    };

    /** CZ-FUNKCE: Zavře hlavní nabídku Home Wiki. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnCloseAppMenu = () => {
        const oMenu = fnById('hc-homewiki-app-menu');
        if (!oMenu || oMenu.hidden) return;
        oMenu.hidden = true;
        oMenu.replaceChildren();
        if (oAppMenuReturnFocus?.isConnected) oAppMenuReturnFocus.setAttribute('aria-expanded', 'false');
        oAppMenuReturnFocus = null;
    };

    /** CZ-FUNKCE: Vyexportuje celou Wiki do přenosného ZIP balíku. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnExportWholeWiki = async () => {
        try {
            const oResponse = await fnApi('/api/wiki/export', { method: 'GET', raw: true });
            if (!oResponse.ok) {
                let sMessage = t(sAppId, 'Could not export Wiki');
                try {
                    const oError = await oResponse.json();
                    if (typeof oError?.error === 'string' && oError.error) sMessage = oError.error;
                } catch (oParseError) {
                    oLogger.debug('Home Wiki export error response was not JSON.', { oParseError });
                }
                throw new Error(sMessage);
            }
            const oBlob = await oResponse.blob();
            let sFilename = 'HomeWiki-export.zip';
            const sDisposition = String(oResponse.headers.get('content-disposition') || '');
            const oMatch = sDisposition.match(/filename\*?=(?:UTF-8''|["']?)([^"';\r\n]+)/i);
            if (oMatch?.[1]) {
                try { sFilename = decodeURIComponent(oMatch[1].trim()); } catch (oError) { sFilename = oMatch[1].trim(); }
            }
            const sObjectUrl = URL.createObjectURL(oBlob);
            const oLink = document.createElement('a');
            oLink.href = sObjectUrl;
            oLink.download = sFilename;
            document.body.append(oLink);
            oLink.click();
            oLink.remove();
            window.setTimeout(() => URL.revokeObjectURL(sObjectUrl), 1000);
            oCore.notifications.success(t(sAppId, 'Wiki export created.'));
        } catch (oError) {
            fnNotifyError(oError);
        }
    };

    /** CZ-FUNKCE: Otevře dialog pro import celé Wiki. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnOpenWikiImport = () => {
        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-management-content';
        const oInfo = document.createElement('p');
        oInfo.textContent = t(sAppId, 'A Home Wiki export restores pages, tree, sharing, aliases, images, attachments and tags. A complete backup can only be restored into an empty Wiki. Generic Markdown files are added as private pages.');
        const oHint = document.createElement('small');
        oHint.textContent = t(sAppId, 'For a generic Markdown ZIP, non-Markdown files are currently ignored. Home Wiki backup ZIP files include their exported images and attachments.');
        const oLabel = document.createElement('label');
        const oLabelText = document.createElement('span');
        oLabelText.textContent = t(sAppId, 'Import file');
        const oInput = document.createElement('input');
        oInput.type = 'file';
        oInput.accept = '.zip,.md,.markdown,application/zip,text/markdown,text/plain';
        oLabel.append(oLabelText, oInput);
        oContent.append(oInfo, oLabel, oHint);

        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Import Wiki'),
            content: oContent,
            size: 'medium',
            actions: [
                { label: t(sAppId, 'Cancel') },
                {
                    label: t(sAppId, 'Import'), variant: 'primary', close: false,
                    onClick: async () => {
                        const oFile = oInput.files?.[0];
                        if (!oFile) {
                            oCore.notifications.error(t(sAppId, 'Choose a file to import.'));
                            return;
                        }
                        const oForm = new FormData();
                        oForm.append('file', oFile, oFile.name);
                        oInput.disabled = true;
                        try {
                            const oResult = await fnApi('/api/wiki/import', { method: 'POST', body: oForm });
                            oDialog.close('action');
                            await fnLoadPages();
                            if (!oCurrentPage && oResult.page?.id) {
                                await fnOpenPage(String(oResult.page.id));
                            } else if (!oCurrentPage && aPages.length) {
                                await fnOpenPage(String(aPages[0].id));
                            }
                            const nIgnored = Number(oResult.ignoredFiles || 0);
                            const nSkippedAcl = Number(oResult.skippedAcl || 0);
                            let sMessage = t(sAppId, 'Imported pages: {count}', { count: Number(oResult.imported || 0) });
                            if (nIgnored > 0) sMessage += ' ' + t(sAppId, 'Ignored files: {count}', { count: nIgnored });
                            if (nSkippedAcl > 0) sMessage += ' ' + t(sAppId, 'Unavailable sharing entries skipped: {count}', { count: nSkippedAcl });
                            oCore.notifications.success(sMessage, { dedupeKey: 'hc_homewiki:wiki-import', cooldownMs: 1200 });
                        } catch (oError) {
                            fnNotifyError(oError);
                        } finally {
                            oInput.disabled = false;
                        }
                    },
                },
            ],
        });
    };

    /** CZ-FUNKCE: Otevře potvrzení pro bezpečné smazání celé vlastní Wiki. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnOpenWholeWikiDelete = () => {
        const sExpected = t(sAppId, 'DELETE');
        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-management-content hc-homewiki-danger-zone';
        const oWarning = document.createElement('p');
        oWarning.textContent = t(sAppId, 'This permanently deletes every live page and every trash item that you own, including their Home Wiki files, images and attachments. Pages owned by other users and shared with you are not deleted.');
        const oBackupHint = document.createElement('p');
        oBackupHint.textContent = t(sAppId, 'Export the Wiki first if you may need this content later. Nextcloud file-version history is not part of a Home Wiki export.');
        const oLabel = document.createElement('label');
        const oLabelText = document.createElement('span');
        oLabelText.textContent = t(sAppId, 'Type {word} to confirm', { word: sExpected });
        const oInput = document.createElement('input');
        oInput.type = 'text';
        oInput.autocomplete = 'off';
        oInput.spellcheck = false;
        oLabel.append(oLabelText, oInput);
        oContent.append(oWarning, oBackupHint, oLabel);

        /** CZ-FUNKCE: Sestaví hodnotu HTTP Basic Authorization pro potvrzení heslem. Vstup: `sPassword`. Výstup: řetězec HTTP Authorization. */
        const fnBasicAuthorization = (sPassword) => {
            const sUid = String(OC?.getCurrentUser?.()?.uid || '');
            if (!sUid) throw new Error(t(sAppId, 'Current user is not available.'));
            const aBytes = new TextEncoder().encode(sUid + ':' + String(sPassword || ''));
            let sBinary = '';
            for (const nByte of aBytes) sBinary += String.fromCharCode(nByte);
            return 'Basic ' + btoa(sBinary);
        };

        /** CZ-FUNKCE: Smaže příslušný objekt nebo data. Vstup: `sPassword`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
        const fnDelete = async (sPassword) => {
            try {
                // Nextcloud 35 strict password confirmation validates the password as part
                // of this exact destructive request. It therefore cannot be bypassed by a
                // login or password confirmation performed a few minutes earlier.
                const oResult = await fnApi('/api/wiki', {
                    method: 'DELETE',
                    body: '{}',
                    headers: { Authorization: fnBasicAuthorization(sPassword) },
                });
                oCurrentPage = null;
                fnStopRevisionWatch();
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
                const oMobileCurrent = fnById('hc-homewiki-mobile-current');
                const oPage = fnById('hc-homewiki-page');
                const oEmpty = fnById('hc-homewiki-empty');
                if (oMobileCurrent) oMobileCurrent.textContent = t(sAppId, 'Home Wiki');
                if (oPage) oPage.hidden = true;
                if (oEmpty) oEmpty.hidden = false;
                await fnLoadPages();
                const sMessage = t(sAppId, 'Your Home Wiki data was deleted. Owned pages: {pages}; trash items: {trash}. Pages shared with you by other users were not deleted.', {
                    pages: Number(oResult.deletedPages || 0), trash: Number(oResult.deletedTrash || 0),
                });
                oCore.notifications.success(sMessage, { dedupeKey: 'hc_homewiki:wiki-deleted', cooldownMs: 1200 });
                if (oResult.cleanupPending) {
                    oCore.notifications.warning(t(sAppId, 'Wiki data was removed, but a staged Files cleanup could not finish. Check the server log.'));
                }
                return true;
            } catch (oError) {
                if (Number(oError?.status || 0) === 412 || Number(oError?.status || 0) === 403) {
                    oCore.notifications.error(t(sAppId, 'Incorrect password. The Wiki was not deleted.'), {
                        dedupeKey: 'hc_homewiki:wiki-wrong-password',
                        cooldownMs: 1200,
                    });
                } else {
                    fnNotifyError(oError);
                }
                return false;
            }
        };

        /** CZ-FUNKCE: Vyžádá nové zadání hesla pro citlivou operaci. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
        const fnPromptFreshPassword = () => {
            const oPasswordContent = document.createElement('div');
            oPasswordContent.className = 'hc-homewiki-management-content hc-homewiki-danger-zone';
            const oPasswordInfo = document.createElement('p');
            oPasswordInfo.textContent = t(sAppId, 'For this destructive operation, your current Nextcloud password is required every time.');
            const oPasswordLabel = document.createElement('label');
            const oPasswordLabelText = document.createElement('span');
            oPasswordLabelText.textContent = t(sAppId, 'Current Nextcloud password');
            const oPasswordInput = document.createElement('input');
            oPasswordInput.type = 'password';
            oPasswordInput.autocomplete = 'current-password';
            oPasswordInput.spellcheck = false;
            oPasswordLabel.append(oPasswordLabelText, oPasswordInput);
            oPasswordContent.append(oPasswordInfo, oPasswordLabel);

            let bSubmitting = false;
            let oPasswordDialog;
            /** CZ-FUNKCE: Odešle zadané heslo k ověření citlivé operace. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
            const fnSubmitPassword = async () => {
                if (bSubmitting) return;
                let sPassword = String(oPasswordInput.value || '');
                if (!sPassword) {
                    oCore.notifications.error(t(sAppId, 'Enter your current Nextcloud password.'));
                    oPasswordInput.focus();
                    return;
                }
                bSubmitting = true;
                oPasswordInput.disabled = true;
                oPasswordInput.value = '';
                const bDeleted = await fnDelete(sPassword);
                sPassword = '';
                bSubmitting = false;
                if (bDeleted) {
                    oPasswordDialog?.close('action');
                    return;
                }
                oPasswordInput.disabled = false;
                oPasswordInput.focus();
            };

            oPasswordDialog = oCore.dialogs.open({
                title: t(sAppId, 'Confirm deletion with password'),
                content: oPasswordContent,
                size: 'small',
                actions: [
                    { label: t(sAppId, 'Cancel') },
                    {
                        label: t(sAppId, 'Delete entire Wiki'),
                        variant: 'danger',
                        close: false,
                        onClick: () => { void fnSubmitPassword(); },
                    },
                ],
            });
            oPasswordInput.addEventListener('keydown', (oEvent) => {
                if (oEvent.key !== 'Enter') return;
                oEvent.preventDefault();
                void fnSubmitPassword();
            });
            window.requestAnimationFrame(() => oPasswordInput.focus());
        };

        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Delete entire Wiki'),
            content: oContent,
            size: 'medium',
            actions: [
                { label: t(sAppId, 'Cancel') },
                {
                    label: t(sAppId, 'Delete entire Wiki'), variant: 'danger', close: false,
                    onClick: () => {
                        if (oInput.value.trim() !== sExpected) {
                            oCore.notifications.error(t(sAppId, 'Confirmation text does not match.'));
                            oInput.focus();
                            return;
                        }
                        oDialog.close('action');
                        fnPromptFreshPassword();
                    },
                },
            ],
        });
        window.requestAnimationFrame(() => oInput.focus());
    };

    /** CZ-FUNKCE: Vykreslí obsah hlavní nabídky Home Wiki. Vstup: `oAnchor`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRenderAppMenu = (oAnchor) => {
        const oMenu = fnById('hc-homewiki-app-menu');
        if (!oMenu) return;
        oMenu.replaceChildren();

        const oHeading = document.createElement('div');
        oHeading.className = 'hc-homewiki-menu-heading';
        oHeading.textContent = t(sAppId, 'Visible sections');
        oMenu.append(oHeading);

        const aSections = [
            ['pinned', '📌', t(sAppId, 'Pinned')],
            ['recent', '🕘', t(sAppId, 'Recent pages')],
            ['frequent', '★', t(sAppId, 'Frequently used')],
            ['tags', '🏷', t(sAppId, 'Tags')],
            ['pages', '📚', t(sAppId, 'Pages')],
        ];
        for (const [sKey, sIcon, sLabel] of aSections) {
            const oButton = document.createElement('button');
            oButton.type = 'button';
            oButton.setAttribute('role', 'menuitemcheckbox');
            oButton.setAttribute('aria-checked', oSidebarSections[sKey]?.visible ? 'true' : 'false');
            oButton.textContent = (oSidebarSections[sKey]?.visible ? '✓ ' : '  ') + sIcon + ' ' + sLabel;
            oButton.addEventListener('click', async () => {
                const bSaved = await fnSetSidebarSectionVisible(sKey, !oSidebarSections[sKey]?.visible);
                if (!bSaved || !oAnchor.isConnected) return;
                fnRenderAppMenu(oAnchor);
                fnPositionMenu(oMenu, oAnchor, 280);
            });
            oMenu.append(oButton);
        }

        const oSeparator = document.createElement('div');
        oSeparator.className = 'hc-homewiki-menu-separator';
        oMenu.append(oSeparator);

        const aVisibleStates = Object.values(oSidebarSections).filter((oState) => oState.visible);
        const bAllCollapsed = aVisibleStates.length > 0 && aVisibleStates.every((oState) => oState.collapsed);
        const oCollapse = document.createElement('button');
        oCollapse.type = 'button';
        oCollapse.setAttribute('role', 'menuitem');
        oCollapse.textContent = t(sAppId, bAllCollapsed ? 'Expand all sections' : 'Collapse all sections');
        oCollapse.addEventListener('click', async () => {
            const bSaved = await fnSetAllSidebarSectionsCollapsed(!bAllCollapsed);
            if (!bSaved || !oAnchor.isConnected) return;
            fnRenderAppMenu(oAnchor);
            fnPositionMenu(oMenu, oAnchor, 280);
        });
        oMenu.append(oCollapse);

        const oSeparator2 = document.createElement('div');
        oSeparator2.className = 'hc-homewiki-menu-separator';
        oMenu.append(oSeparator2);
        const aTools = [
            ['settings', '⚙', t(sAppId, 'Wiki settings')],
            ['manage-tags', '🏷', t(sAppId, 'Manage tags')],
            ['trash', '🗑', t(sAppId, 'Trash')],
            ['about', 'ⓘ', t(sAppId, 'About')],
        ];
        for (const [sAction, sIcon, sLabel] of aTools) {
            const oButton = document.createElement('button');
            oButton.type = 'button';
            oButton.setAttribute('role', 'menuitem');
            oButton.textContent = sIcon + ' ' + sLabel;
            oButton.addEventListener('click', () => {
                fnCloseAppMenu();
                if (sAction === 'settings') void fnOpenSettings();
                else if (sAction === 'manage-tags') void fnOpenTagManager();
                else if (sAction === 'trash') void fnOpenTrash();
                else if (sAction === 'about') fnOpenAbout();
            });
            oMenu.append(oButton);
        }


        const oSeparator3 = document.createElement('div');
        oSeparator3.className = 'hc-homewiki-menu-separator';
        oMenu.append(oSeparator3);
        const oManagementHeading = document.createElement('div');
        oManagementHeading.className = 'hc-homewiki-menu-heading';
        oManagementHeading.textContent = t(sAppId, 'Wiki management');
        oMenu.append(oManagementHeading);
        const sCurrentUid = String(OC?.getCurrentUser?.()?.uid || '');
        const bHasOwnedPages = aPages.some((oPage) => String(oPage.owner_uid || '') === sCurrentUid);
        const aManagement = [
            ['export-wiki', '⇩', t(sAppId, 'Export entire Wiki')],
            ['import-wiki', '⇧', t(sAppId, 'Import Wiki')],
            ['delete-wiki', '⚠', t(sAppId, 'Delete entire Wiki')],
        ];
        for (const [sAction, sIcon, sLabel] of aManagement) {
            const oButton = document.createElement('button');
            oButton.type = 'button';
            oButton.setAttribute('role', 'menuitem');
            if (sAction === 'delete-wiki') oButton.classList.add('hc-homewiki-menu-danger');
            if (sAction === 'export-wiki' && !bHasOwnedPages) {
                oButton.disabled = true;
                oButton.title = t(sAppId, 'There are no owned pages to export');
                oButton.setAttribute('aria-disabled', 'true');
            }
            oButton.textContent = sIcon + ' ' + sLabel;
            oButton.addEventListener('click', () => {
                fnCloseAppMenu();
                if (sAction === 'export-wiki') void fnExportWholeWiki();
                else if (sAction === 'import-wiki') fnOpenWikiImport();
                else if (sAction === 'delete-wiki') fnOpenWholeWikiDelete();
            });
            oMenu.append(oButton);
        }
    };

    /** CZ-FUNKCE: Otevře hlavní nabídku Home Wiki u zadané kotvy. Vstup: `oAnchor`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnOpenAppMenu = (oAnchor) => {
        const oMenu = fnById('hc-homewiki-app-menu');
        if (!oMenu) return;
        if (!oMenu.hidden && oAppMenuReturnFocus === oAnchor) { fnCloseAppMenu(); return; }
        fnClosePageMenu();
        fnCloseAppMenu();
        oAppMenuReturnFocus = oAnchor;
        oAnchor.setAttribute('aria-expanded', 'true');
        fnRenderAppMenu(oAnchor);
        oMenu.hidden = false;
        fnPositionMenu(oMenu, oAnchor, 280);
        oMenu.querySelector('button')?.focus();
    };

    /** CZ-FUNKCE: Zavře kontextovou nabídku stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnClosePageMenu = () => {
        const oMenu = fnById('hc-homewiki-page-menu');
        if (!oMenu || oMenu.hidden) return;
        oMenu.hidden = true;
        oMenu.replaceChildren();
        if (oMenuReturnFocus?.isConnected) oMenuReturnFocus.setAttribute('aria-expanded', 'false');
        oMenuReturnFocus = null;
    };

    /** CZ-FUNKCE: Přepne připnutí aktuální stránky. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnTogglePin = async () => {
        if (!oCurrentPage?.permissions.edit) return;
        const bOld = !!oCurrentPage.pinned;
        oCurrentPage.pinned = !bOld;
        if (!(await fnSave({ pinned: oCurrentPage.pinned }))) oCurrentPage.pinned = bOld;
        fnRenderNav();
    };

    /** CZ-FUNKCE: Spustí přejmenování aktuální stránky. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnRenamePage = async () => {
        if (!oCurrentPage?.permissions.edit) return;
        const sNewTitle = await fnTextDialog(t(sAppId, 'Rename page'), t(sAppId, 'Page title'), String(oCurrentPage.title), t(sAppId, 'Rename'));
        if (!sNewTitle || sNewTitle.trim() === oCurrentPage.title) return;
        if (await fnSave({ title: sNewTitle.trim() })) {
            fnById('hc-homewiki-title').value = sNewTitle.trim();
            fnById('hc-homewiki-mobile-current').textContent = sNewTitle.trim();
        }
    };

    /** CZ-FUNKCE: Otevře výběr nového rodiče stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnAssignParent = () => {
        if (!oCurrentPage?.permissions.edit) return;
        const sPageId = String(oCurrentPage.id);
        const oBlocked = new Set([sPageId]);
        let bAdded;
        do {
            bAdded = false;
            for (const oPage of aPages) {
                if (oBlocked.has(String(oPage.parent_id)) && !oBlocked.has(String(oPage.id))) {
                    oBlocked.add(String(oPage.id));
                    bAdded = true;
                }
            }
        } while (bAdded);
        const oSelect = document.createElement('select');
        oSelect.setAttribute('aria-label', t(sAppId, 'Parent page'));
        const oRootOption = document.createElement('option');
        oRootOption.value = '';
        oRootOption.textContent = t(sAppId, 'Top level');
        oSelect.append(oRootOption);
        for (const oPage of aPages) {
            if (String(oPage.owner_uid) !== String(oCurrentPage.owner_uid) || oBlocked.has(String(oPage.id))) continue;
            const oOption = document.createElement('option');
            oOption.value = String(oPage.id);
            oOption.textContent = String(oPage.title);
            oSelect.append(oOption);
        }
        oSelect.value = String(oCurrentPage.parent_id || '');
        const oContent = document.createElement('div');
        const oLabel = document.createElement('label');
        oLabel.textContent = t(sAppId, 'Parent page');
        oLabel.append(oSelect);
        oContent.append(oLabel);
        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Place page under'), content: oContent, size: 'small',
            actions: [{ label: t(sAppId, 'Cancel') }, {
                label: t(sAppId, 'Save'), variant: 'primary', close: false,
                onClick: async () => {
                    if (await fnSave({ parentId: oSelect.value || null })) oDialog.close('action');
                },
            }],
        });
    };

    /** CZ-FUNKCE: Naformátuje velikost historické verze pro zobrazení uživateli. Vstup: `nBytes`. Výstup: naformátovaný řetězec. */
    const fnFormatHistorySize = (nBytes) => {
        const nValue = Math.max(0, Number(nBytes) || 0);
        if (nValue < 1024) return Math.round(nValue) + ' B';
        if (nValue < 1024 * 1024) return (nValue / 1024).toFixed(nValue < 10 * 1024 ? 1 : 0) + ' KB';
        return (nValue / (1024 * 1024)).toFixed(1) + ' MB';
    };

    /** CZ-FUNKCE: Otevře historii verzí aktuální stránky. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnOpenHistory = async () => {
        if (!oCurrentPage?.permissions.edit) return;
        const sPageId = String(oCurrentPage.id || '');
        if (!sPageId) return;
        if ((bDirty || fnHasLocalEditorChanges()) && !(await fnSave())) return;

        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-history-content';
        const oInfo = document.createElement('p');
        oInfo.className = 'hc-homewiki-history-info';
        oInfo.textContent = t(sAppId, 'Older page versions are managed by Nextcloud file versions and follow the instance retention policy.');
        const oList = document.createElement('div');
        oList.className = 'hc-homewiki-history-list';
        const oPreviewWrap = document.createElement('section');
        oPreviewWrap.className = 'hc-homewiki-history-preview-wrap';
        oPreviewWrap.hidden = true;
        const oPreviewHead = document.createElement('div');
        oPreviewHead.className = 'hc-homewiki-history-preview-head';
        const oPreviewTitle = document.createElement('strong');
        const oPreviewMeta = document.createElement('small');
        oPreviewHead.append(oPreviewTitle, oPreviewMeta);
        const oPreview = document.createElement('div');
        oPreview.className = 'hc-homewiki-history-preview hc-homewiki-preview';
        oPreviewWrap.append(oPreviewHead, oPreview);
        oContent.append(oInfo, oList, oPreviewWrap);

        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Version history'),
            content: oContent,
            size: 'large',
            actions: [{ label: t(sAppId, 'Close') }],
        });

        /** CZ-FUNKCE: Načte potřebná data. Vstup: `oVersion`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
        const fnLoadPreview = async (oVersion) => {
            try {
                oPreviewWrap.hidden = false;
                oPreviewTitle.textContent = t(sAppId, 'Loading…');
                oPreviewMeta.textContent = '';
                oPreview.replaceChildren();
                const oLoaded = await fnApi('/api/pages/' + encodeURIComponent(sPageId)
                    + '/versions/' + encodeURIComponent(String(oVersion.id)));
                const sDate = new Date(Number(oLoaded.timestamp || oVersion.timestamp) * 1000).toLocaleString();
                oPreviewTitle.textContent = t(sAppId, 'Version from {date}', { date: sDate });
                oPreviewMeta.textContent = fnFormatHistorySize(Number(oLoaded.size || oVersion.size || 0));
                fnRenderCoreMarkdown(oPreview, String(oLoaded.content || ''));
                oPreviewWrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } catch (oError) {
                fnNotifyError(oError);
            }
        };

        try {
            const oData = await fnApi('/api/pages/' + encodeURIComponent(sPageId) + '/versions');
            if (!oData.available) {
                oInfo.textContent = t(sAppId, 'Version history is unavailable. Enable the Nextcloud file versions app to use it.');
                return;
            }
            const aVersions = Array.isArray(oData.versions) ? oData.versions : [];
            if (!aVersions.length) {
                const oEmpty = document.createElement('p');
                oEmpty.className = 'hc-homewiki-history-empty';
                oEmpty.textContent = t(sAppId, 'No older versions are available.');
                oList.append(oEmpty);
                return;
            }

            for (const oVersion of aVersions) {
                const oRow = document.createElement('div');
                oRow.className = 'hc-homewiki-history-row';
                const oText = document.createElement('div');
                oText.className = 'hc-homewiki-history-text';
                const oDate = document.createElement('strong');
                const sDate = new Date(Number(oVersion.timestamp || 0) * 1000).toLocaleString();
                oDate.textContent = sDate;
                const oMeta = document.createElement('small');
                oMeta.textContent = fnFormatHistorySize(Number(oVersion.size || 0));
                oText.append(oDate, oMeta);

                const oActions = document.createElement('div');
                oActions.className = 'hc-homewiki-history-actions';
                const oPreviewButton = document.createElement('button');
                oPreviewButton.type = 'button';
                oPreviewButton.textContent = t(sAppId, 'Preview');
                oPreviewButton.addEventListener('click', () => void fnLoadPreview(oVersion));
                const oRestoreButton = document.createElement('button');
                oRestoreButton.type = 'button';
                oRestoreButton.className = 'primary';
                oRestoreButton.textContent = t(sAppId, 'Restore');
                oRestoreButton.addEventListener('click', async () => {
                    const bConfirmed = await oCore.dialogs.confirm({
                        title: t(sAppId, 'Restore version'),
                        message: t(sAppId, 'Restore version from {date}? The current content will remain available as a newer version.', { date: sDate }),
                        confirmLabel: t(sAppId, 'Restore'),
                        cancelLabel: t(sAppId, 'Cancel'),
                    });
                    if (!bConfirmed) return;
                    try {
                        const oResult = await fnApi('/api/pages/' + encodeURIComponent(sPageId)
                            + '/versions/' + encodeURIComponent(String(oVersion.id)) + '/restore', {
                            method: 'POST',
                            body: JSON.stringify({ expectedRevision: Number(oCurrentPage?.revision || 0) }),
                        });
                        if (oResult?.page) Object.assign(oCurrentPage, oResult.page);
                        oDialog.close('action');
                        await fnLoadPages();
                        await fnOpenPage(sPageId, false, true);
                        oCore.notifications.success(t(sAppId, 'Version restored.'), {
                            dedupeKey: 'hc_homewiki:version-restored:' + sPageId,
                            cooldownMs: 800,
                        });
                    } catch (oError) {
                        fnNotifyError(oError);
                    }
                });
                oActions.append(oPreviewButton, oRestoreButton);
                oRow.append(oText, oActions);
                oList.append(oRow);
            }
        } catch (oError) {
            fnNotifyError(oError);
            oDialog.close('error');
        }
    };

    /** CZ-FUNKCE: Sestaví URL endpointu pro konkrétní přílohu stránky. Vstup: `sPageId`, `sName`. Výstup: řetězec URL. */
    const fnAttachmentUrl = (sPageId, sName) => OC.generateUrl('/apps/' + sAppId
        + '/api/pages/' + encodeURIComponent(String(sPageId))
        + '/attachments/' + encodeURIComponent(String(sName)));

    /** CZ-FUNKCE: Vykreslí štítky aktuální stránky do záhlaví. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRenderPageTagBadges = () => {
        const oMeta = fnById('hc-homewiki-page-meta');
        if (!oMeta) return;
        oMeta.querySelector('.hc-homewiki-page-tags')?.remove();
        const aTags = Array.isArray(oCurrentPage?.tags) ? oCurrentPage.tags : [];
        if (!aTags.length) return;
        const oWrap = document.createElement('span');
        oWrap.className = 'hc-homewiki-page-tags';
        for (const oTag of aTags) {
            const oButton = document.createElement('button');
            oButton.type = 'button';
            oButton.className = 'hc-homewiki-tag-chip';
            oButton.textContent = '#' + String(oTag.name || '');
            oButton.title = t(sAppId, 'Filter pages by tag');
            oButton.addEventListener('click', () => {
                const sId = String(oTag.id || '');
                sTagFilterId = sTagFilterId === sId ? '' : sId;
                fnRenderNav();
            });
            oWrap.append(oButton);
        }
        const oAttachment = oMeta.querySelector('.hc-homewiki-attachment-badge');
        if (oAttachment) oMeta.insertBefore(oWrap, oAttachment);
        else oMeta.append(oWrap);
    };

    /** CZ-FUNKCE: Aktualizuje počet příloh zobrazený u stránky. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnRefreshAttachmentBadge = async () => {
        const oMeta = fnById('hc-homewiki-page-meta');
        const sPageId = String(oCurrentPage?.id || '');
        if (!oMeta || !sPageId) return;
        try {
            const oData = await fnApi('/api/pages/' + encodeURIComponent(sPageId) + '/attachments');
            if (String(oCurrentPage?.id || '') !== sPageId) return;
            const nCount = Array.isArray(oData.attachments) ? oData.attachments.length : 0;
            oCurrentPage.attachmentCount = nCount;
            let oButton = oMeta.querySelector('.hc-homewiki-attachment-badge');
            if (!oButton) {
                oButton = document.createElement('button');
                oButton.type = 'button';
                oButton.className = 'hc-homewiki-attachment-badge';
                oButton.addEventListener('click', () => void fnOpenAttachments());
                oMeta.append(oButton);
            }
            oButton.textContent = '📎 ' + nCount;
            oButton.title = t(sAppId, 'Attachments');
            oButton.setAttribute('aria-label', t(sAppId, '{count} attachments', { count: nCount }));
        } catch (oError) {
            oLogger.warn('Home Wiki could not load attachment count.', { oError, sPageId });
        }
    };

    /** CZ-FUNKCE: Otevře správu příloh aktuální stránky. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnOpenAttachments = async () => {
        const sPageId = String(oCurrentPage?.id || '');
        if (!sPageId) return;
        if ((bDirty || fnHasLocalEditorChanges()) && !(await fnSave())) return;

        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-attachments-content';
        const oHelp = document.createElement('p');
        oHelp.className = 'hc-homewiki-attachments-help';
        oHelp.textContent = t(sAppId, 'Files attached to this page are stored in Nextcloud Files together with the Wiki and are included in a full Wiki backup.');
        const oToolbar = document.createElement('div');
        oToolbar.className = 'hc-homewiki-attachments-toolbar';
        const oList = document.createElement('div');
        oList.className = 'hc-homewiki-attachments-list';
        const oFile = document.createElement('input');
        oFile.type = 'file';
        oFile.multiple = true;
        oFile.hidden = true;
        const oAdd = document.createElement('button');
        oAdd.type = 'button';
        oAdd.className = 'primary';
        oAdd.textContent = '＋ ' + t(sAppId, 'Add attachment');
        oToolbar.append(oAdd, oFile);
        oContent.append(oHelp, oToolbar, oList);

        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Attachments'),
            content: oContent,
            size: 'large',
            actions: [{ label: t(sAppId, 'Close') }],
        });

        let bCanEdit = !!oCurrentPage?.permissions?.edit;
        let bCanDelete = !!oCurrentPage?.permissions?.delete;
        /** CZ-FUNKCE: Načte potřebná data. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
        const fnLoad = async () => {
            const oData = await fnApi('/api/pages/' + encodeURIComponent(sPageId) + '/attachments');
            if (String(oCurrentPage?.id || '') !== sPageId) {
                oDialog.close('page-changed');
                return;
            }
            bCanEdit = !!oData.canEdit;
            bCanDelete = !!oData.canDelete;
            oAdd.hidden = !bCanEdit;
            oList.replaceChildren();
            const aAttachments = Array.isArray(oData.attachments) ? oData.attachments : [];
            oCurrentPage.attachmentCount = aAttachments.length;
            if (!aAttachments.length) {
                const oEmpty = document.createElement('p');
                oEmpty.className = 'hc-homewiki-attachments-empty';
                oEmpty.textContent = t(sAppId, 'This page has no attachments.');
                oList.append(oEmpty);
            }
            for (const oAttachment of aAttachments) {
                const sName = String(oAttachment.name || '');
                const oRow = document.createElement('div');
                oRow.className = 'hc-homewiki-attachment-row';
                const oMain = document.createElement('div');
                oMain.className = 'hc-homewiki-attachment-main';
                const oIcon = document.createElement('span');
                oIcon.className = 'hc-homewiki-attachment-icon';
                oIcon.textContent = String(oAttachment.mime || '').startsWith('image/') ? '🖼️' : '📎';
                const oText = document.createElement('div');
                const oName = document.createElement('strong');
                oName.textContent = sName;
                const oMeta = document.createElement('small');
                const nSize = Math.max(0, Number(oAttachment.size || 0));
                const sModified = Number(oAttachment.modified || 0) > 0
                    ? ' · ' + new Date(Number(oAttachment.modified) * 1000).toLocaleString() : '';
                oMeta.textContent = fnFormatHistorySize(nSize) + sModified;
                oText.append(oName, oMeta);
                oMain.append(oIcon, oText);

                const oActions = document.createElement('div');
                oActions.className = 'hc-homewiki-attachment-actions';
                const oDownload = document.createElement('a');
                oDownload.className = 'button';
                oDownload.href = fnAttachmentUrl(sPageId, sName);
                oDownload.download = sName;
                oDownload.textContent = t(sAppId, 'Download');
                oActions.append(oDownload);
                if (bCanDelete) {
                    const oDelete = document.createElement('button');
                    oDelete.type = 'button';
                    oDelete.className = 'danger';
                    oDelete.textContent = t(sAppId, 'Delete');
                    oDelete.addEventListener('click', async () => {
                        const bConfirmed = await oCore.dialogs.confirm({
                            title: t(sAppId, 'Delete attachment'),
                            message: t(sAppId, 'Delete attachment “{name}”?', { name: sName }),
                            confirmLabel: t(sAppId, 'Delete'),
                            cancelLabel: t(sAppId, 'Cancel'),
                            destructive: true,
                        });
                        if (!bConfirmed) return;
                        try {
                            await fnApi('/api/pages/' + encodeURIComponent(sPageId)
                                + '/attachments/' + encodeURIComponent(sName), { method: 'DELETE' });
                            await fnLoad();
                            await fnRefreshAttachmentBadge();
                            oCore.notifications.success(t(sAppId, 'Attachment deleted.'), {
                                dedupeKey: 'hc_homewiki:attachment-deleted:' + sPageId + ':' + sName,
                                cooldownMs: 700,
                            });
                        } catch (oError) { fnNotifyError(oError); }
                    });
                    oActions.append(oDelete);
                }
                oRow.append(oMain, oActions);
                oList.append(oRow);
            }
        };

        oAdd.addEventListener('click', () => oFile.click());
        oFile.addEventListener('change', async () => {
            const aFiles = Array.from(oFile.files || []);
            oFile.value = '';
            if (!aFiles.length || !bCanEdit) return;
            oAdd.disabled = true;
            try {
                for (const oUpload of aFiles) {
                    const oForm = new FormData();
                    oForm.append('file', oUpload);
                    await fnApi('/api/pages/' + encodeURIComponent(sPageId) + '/attachments', {
                        method: 'POST', body: oForm,
                    });
                }
                await fnLoad();
                await fnRefreshAttachmentBadge();
                oCore.notifications.success(t(sAppId, 'Attachments uploaded.'), {
                    dedupeKey: 'hc_homewiki:attachments-uploaded:' + sPageId,
                    cooldownMs: 700,
                });
            } catch (oError) {
                fnNotifyError(oError);
            } finally {
                oAdd.disabled = false;
            }
        });

        try { await fnLoad(); }
        catch (oError) { fnNotifyError(oError); oDialog.close('error'); }
    };

    /** CZ-FUNKCE: Otevře výběr a správu štítků aktuální stránky. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnOpenPageTags = async () => {
        if (!oCurrentPage?.permissions?.owner) {
            oCore.notifications.error(t(sAppId, 'Only the page owner can change tags'));
            return;
        }
        const sPageId = String(oCurrentPage.id || '');
        if (!sPageId) return;
        if ((bDirty || fnHasLocalEditorChanges()) && !(await fnSave())) return;
        await fnLoadTags();

        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-tags-dialog';
        const oHelp = document.createElement('p');
        oHelp.textContent = t(sAppId, 'Tags organize pages across the tree. Applying a tag to a subtree is a one-time update; future subpages are not tagged automatically.');
        const oScopeLabel = document.createElement('label');
        const oScopeText = document.createElement('span');
        oScopeText.textContent = t(sAppId, 'Apply changes to');
        const oScope = document.createElement('select');
        for (const [sValue, sLabel] of [
            ['single', t(sAppId, 'This page only')],
            ['subtree', t(sAppId, 'This page and entire subtree')],
        ]) {
            const oOption = document.createElement('option');
            oOption.value = sValue;
            oOption.textContent = sLabel;
            oScope.append(oOption);
        }
        oScopeLabel.append(oScopeText, oScope);

        const oList = document.createElement('div');
        oList.className = 'hc-homewiki-page-tags-list';
        const oAddRow = document.createElement('div');
        oAddRow.className = 'hc-homewiki-tag-add-row';
        const oInput = document.createElement('input');
        oInput.type = 'text';
        oInput.maxLength = 64;
        oInput.placeholder = t(sAppId, 'Tag name');
        const sListId = 'hc-homewiki-tag-suggestions-' + Date.now();
        oInput.setAttribute('list', sListId);
        const oDataList = document.createElement('datalist');
        oDataList.id = sListId;
        const oAdd = document.createElement('button');
        oAdd.type = 'button';
        oAdd.className = 'primary';
        oAdd.textContent = t(sAppId, 'Add tag');
        oAddRow.append(oInput, oDataList, oAdd);
        oContent.append(oHelp, oScopeLabel, oList, oAddRow);

        /** CZ-FUNKCE: Vykreslí příslušnou část uživatelského rozhraní. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
        const fnRender = () => {
            oList.replaceChildren();
            oDataList.replaceChildren();
            for (const oTag of aOwnedTags) {
                const oOption = document.createElement('option');
                oOption.value = String(oTag.name || '');
                oDataList.append(oOption);
            }
            const aCurrent = Array.isArray(oCurrentPage?.tags) ? oCurrentPage.tags : [];
            if (!aCurrent.length) {
                const oEmpty = document.createElement('small');
                oEmpty.textContent = t(sAppId, 'This page has no tags.');
                oList.append(oEmpty);
                return;
            }
            for (const oTag of aCurrent) {
                const oRow = document.createElement('div');
                oRow.className = 'hc-homewiki-page-tag-row';
                const oName = document.createElement('span');
                oName.className = 'hc-homewiki-tag-chip';
                oName.textContent = '#' + String(oTag.name || '');
                const oRemove = document.createElement('button');
                oRemove.type = 'button';
                oRemove.textContent = t(sAppId, 'Remove');
                oRemove.addEventListener('click', async () => {
                    try {
                        const oResult = await fnApi('/api/pages/' + encodeURIComponent(sPageId)
                            + '/tags/' + encodeURIComponent(String(oTag.id))
                            + '?scope=' + encodeURIComponent(oScope.value), { method: 'DELETE' });
                        if (oResult?.page) oCurrentPage = oResult.page;
                        await fnLoadPages();
                        fnRenderPageTagBadges();
                        fnRender();
                        oCore.notifications.success(t(sAppId, 'Tag removed. Updated pages: {count}', { count: Number(oResult.updated || 0) }));
                    } catch (oError) { fnNotifyError(oError); }
                });
                oRow.append(oName, oRemove);
                oList.append(oRow);
            }
        };

        oAdd.addEventListener('click', async () => {
            const sName = String(oInput.value || '').trim().replace(/^#+\s*/, '');
            if (!sName) { oInput.focus(); return; }
            oAdd.disabled = true;
            try {
                let oTag = aOwnedTags.find((oCandidate) => String(oCandidate.name || '').trim().toLocaleLowerCase()
                    === sName.toLocaleLowerCase());
                if (!oTag) {
                    const oCreated = await fnApi('/api/tags', { method: 'POST', body: JSON.stringify({ name: sName }) });
                    oTag = oCreated.tag;
                }
                if (!oTag?.id) throw new Error(t(sAppId, 'Tag could not be created'));
                const oResult = await fnApi('/api/pages/' + encodeURIComponent(sPageId) + '/tags', {
                    method: 'POST',
                    body: JSON.stringify({ tagId: String(oTag.id), scope: oScope.value }),
                });
                if (oResult?.page) oCurrentPage = oResult.page;
                oInput.value = '';
                await fnLoadPages();
                fnRenderPageTagBadges();
                fnRender();
                oCore.notifications.success(t(sAppId, 'Tag added. Updated pages: {count}', { count: Number(oResult.updated || 0) }));
            } catch (oError) { fnNotifyError(oError); }
            finally { oAdd.disabled = false; }
        });
        oInput.addEventListener('keydown', (oEvent) => {
            if (oEvent.key === 'Enter') { oEvent.preventDefault(); oAdd.click(); }
        });

        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Tags'),
            content: oContent,
            size: 'medium',
            actions: [{ label: t(sAppId, 'Close') }],
        });
        fnRender();
        window.requestAnimationFrame(() => oInput.focus());
    };

    /** CZ-FUNKCE: Otevře globální správu Wiki štítků. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnOpenTagManager = async () => {
        await fnLoadTags();
        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-tag-manager';
        const oHelp = document.createElement('p');
        oHelp.textContent = t(sAppId, 'Create reusable tags here. Renaming changes the tag on every page; deleting a tag removes only the label, not the pages.');
        const oCreateRow = document.createElement('div');
        oCreateRow.className = 'hc-homewiki-tag-add-row';
        const oInput = document.createElement('input');
        oInput.type = 'text';
        oInput.maxLength = 64;
        oInput.placeholder = t(sAppId, 'New tag name');
        const oCreate = document.createElement('button');
        oCreate.type = 'button';
        oCreate.className = 'primary';
        oCreate.textContent = t(sAppId, 'Create tag');
        oCreateRow.append(oInput, oCreate);
        const oList = document.createElement('div');
        oList.className = 'hc-homewiki-tag-manager-list';
        oContent.append(oHelp, oCreateRow, oList);

        /** CZ-FUNKCE: Aktualizuje data nebo zobrazení. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
        const fnRefresh = async () => {
            await fnLoadPages();
            oList.replaceChildren();
            if (!aOwnedTags.length) {
                const oEmpty = document.createElement('small');
                oEmpty.textContent = t(sAppId, 'No tags have been created yet.');
                oList.append(oEmpty);
                return;
            }
            for (const oTag of aOwnedTags) {
                const oRow = document.createElement('div');
                oRow.className = 'hc-homewiki-tag-manager-row';
                const oInfo = document.createElement('span');
                oInfo.textContent = '#' + String(oTag.name) + ' · ' + t(sAppId, '{count} pages', { count: Number(oTag.count || 0) });
                const oActions = document.createElement('div');
                const oRename = document.createElement('button');
                oRename.type = 'button';
                oRename.textContent = t(sAppId, 'Rename');
                oRename.addEventListener('click', async () => {
                    const sName = await fnTextDialog(t(sAppId, 'Rename tag'), t(sAppId, 'Tag name'), String(oTag.name), t(sAppId, 'Rename'), 64);
                    if (!sName || sName.trim() === String(oTag.name)) return;
                    try {
                        await fnApi('/api/tags/' + encodeURIComponent(String(oTag.id)), { method: 'PUT', body: JSON.stringify({ name: sName }) });
                        await fnRefresh();
                        if (oCurrentPage) {
                            const oCurrent = aPages.find((oPage) => String(oPage.id) === String(oCurrentPage.id));
                            if (oCurrent) Object.assign(oCurrentPage, oCurrent);
                            fnRenderPageTagBadges();
                        }
                    } catch (oError) { fnNotifyError(oError); }
                });
                const oDelete = document.createElement('button');
                oDelete.type = 'button';
                oDelete.className = 'danger';
                oDelete.textContent = t(sAppId, 'Delete');
                oDelete.addEventListener('click', async () => {
                    const bConfirmed = await oCore.dialogs.confirm({
                        title: t(sAppId, 'Delete tag'),
                        message: t(sAppId, 'Delete tag “{name}”? It will be removed from {count} pages, but no page will be deleted.', { name: String(oTag.name), count: Number(oTag.count || 0) }),
                        confirmLabel: t(sAppId, 'Delete'),
                        cancelLabel: t(sAppId, 'Cancel'),
                    });
                    if (!bConfirmed) return;
                    try {
                        await fnApi('/api/tags/' + encodeURIComponent(String(oTag.id)), { method: 'DELETE' });
                        if (sTagFilterId === String(oTag.id)) sTagFilterId = '';
                        await fnRefresh();
                        if (oCurrentPage) {
                            const oCurrent = aPages.find((oPage) => String(oPage.id) === String(oCurrentPage.id));
                            if (oCurrent) Object.assign(oCurrentPage, oCurrent);
                            fnRenderPageTagBadges();
                        }
                    } catch (oError) { fnNotifyError(oError); }
                });
                oActions.append(oRename, oDelete);
                oRow.append(oInfo, oActions);
                oList.append(oRow);
            }
        };

        oCreate.addEventListener('click', async () => {
            const sName = String(oInput.value || '').trim().replace(/^#+\s*/, '');
            if (!sName) { oInput.focus(); return; }
            try {
                await fnApi('/api/tags', { method: 'POST', body: JSON.stringify({ name: sName }) });
                oInput.value = '';
                await fnRefresh();
            } catch (oError) { fnNotifyError(oError); }
        });
        oInput.addEventListener('keydown', (oEvent) => {
            if (oEvent.key === 'Enter') { oEvent.preventDefault(); oCreate.click(); }
        });
        oCore.dialogs.open({ title: t(sAppId, 'Manage tags'), content: oContent, size: 'medium', actions: [{ label: t(sAppId, 'Close') }] });
        await fnRefresh();
        window.requestAnimationFrame(() => oInput.focus());
    };

    /** CZ-FUNKCE: Otevře kontextovou nabídku právě vybrané stránky. Vstup: `oPage`, `oAnchor`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnOpenPageMenu = (oPage, oAnchor) => {
        const oMenu = fnById('hc-homewiki-page-menu');
        if (!oMenu) return;
        if (!oMenu.hidden && oMenuReturnFocus === oAnchor) { fnClosePageMenu(); return; }
        fnCloseAppMenu();
        fnClosePageMenu();
        oMenuReturnFocus = oAnchor;
        oAnchor.setAttribute('aria-expanded', 'true');
        const aActions = [
            ['edit', t(sAppId, 'Edit page'), !!oPage.permissions.edit],
            ['rename', t(sAppId, 'Rename page'), !!oPage.permissions.edit],
            ['parent', t(sAppId, 'Place page under'), !!oPage.permissions.edit],
            ['pin', oPage.pinned ? t(sAppId, 'Unpin page') : t(sAppId, 'Pin page'), !!oPage.permissions.edit],
            ['share', t(sAppId, 'Sharing and permissions'), !!oPage.permissions.share],
            ['history', t(sAppId, 'Version history'), !!oPage.permissions.edit],
            ['attachments', t(sAppId, 'Attachments'), true],
            ['tags', t(sAppId, 'Tags'), !!oPage.permissions.owner],
            ['export', t(sAppId, 'Export Markdown'), true],
            ['delete', t(sAppId, 'Move to trash'), !!oPage.permissions.delete],
        ];
        for (const [sAction, sLabel, bVisible] of aActions) {
            if (!bVisible) continue;
            const oButton = document.createElement('button');
            oButton.type = 'button';
            oButton.setAttribute('role', 'menuitem');
            oButton.textContent = sLabel;
            if (sAction === 'delete') oButton.classList.add('danger');
            oButton.addEventListener('click', async () => {
                fnClosePageMenu();
                if (oCurrentPage?.id !== oPage.id && !(await fnOpenPage(String(oPage.id)))) return;
                if (oCurrentPage?.id === oPage.id && bDirty && !(await fnSave())) return;
                if (sAction === 'edit') { fnSetMode('edit'); oCoreEditor?.focus(); }
                else if (sAction === 'rename') await fnRenamePage();
                else if (sAction === 'parent') fnAssignParent();
                else if (sAction === 'pin') await fnTogglePin();
                else if (sAction === 'share') fnOpenShare();
                else if (sAction === 'history') await fnOpenHistory();
                else if (sAction === 'attachments') await fnOpenAttachments();
                else if (sAction === 'tags') await fnOpenPageTags();
                else if (sAction === 'export') fnById('hc-homewiki-export')?.click();
                else if (sAction === 'delete') await fnDeletePage();
            });
            oMenu.append(oButton);
        }
        oMenu.hidden = false;
        fnPositionMenu(oMenu, oAnchor, 250);
        oMenu.querySelector('button')?.focus();
    };

    /** CZ-FUNKCE: Znovu vykreslí strom a pomocné seznamy navigace. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRenderNav = () => {
        const oSearch = fnById('hc-homewiki-search');
        const oPinned = fnById('hc-homewiki-pinned');
        const oRecent = fnById('hc-homewiki-recent');
        const oFrequent = fnById('hc-homewiki-frequent');
        const oTags = fnById('hc-homewiki-tags');
        const oTree = fnById('hc-homewiki-tree');
        if (!oSearch || !oPinned || !oRecent || !oFrequent || !oTags || !oTree) return;

        const sQuery = String(oSearch.value || '').toLowerCase();
        const bSearchMode = !!sQuery;
        /** CZ-FUNKCE: Zjistí, zda má stránka zadaný štítek. Vstup: `oPage`, `sTagId`. Výstup: boolean. */
        const fnHasTag = (oPage, sTagId) => Array.isArray(oPage?.tags)
            && oPage.tags.some((oTag) => String(oTag?.id || '') === String(sTagId || ''));
        const aBasePages = bSearchMode ? (aSearchResults ?? []) : aPages;
        const aVisiblePages = sTagFilterId ? aBasePages.filter((oPage) => fnHasTag(oPage, sTagFilterId)) : aBasePages;
        fnUpdateTreeDragToggle();

        oTags.replaceChildren();
        if (sTagFilterId && !aVisibleTags.some((oTag) => String(oTag.id) === sTagFilterId)) sTagFilterId = '';
        if (sTagFilterId) {
            const oClear = document.createElement('button');
            oClear.type = 'button';
            oClear.className = 'hc-homewiki-tag-filter-clear';
            oClear.textContent = '× ' + t(sAppId, 'Clear tag filter');
            oClear.addEventListener('click', () => { sTagFilterId = ''; fnRenderNav(); });
            oTags.append(oClear);
        }
        for (const oTag of aVisibleTags) {
            const oButton = document.createElement('button');
            oButton.type = 'button';
            oButton.className = 'hc-homewiki-tag-filter';
            if (String(oTag.id) === sTagFilterId) oButton.classList.add('active');
            oButton.textContent = '#' + String(oTag.name) + ' (' + Number(oTag.count || 0) + ')';
            oButton.title = t(sAppId, 'Filter pages by tag');
            oButton.addEventListener('click', () => {
                sTagFilterId = String(oTag.id) === sTagFilterId ? '' : String(oTag.id);
                fnRenderNav();
            });
            oTags.append(oButton);
        }
        if (!aVisibleTags.length) {
            const oEmpty = document.createElement('small');
            oEmpty.textContent = '—';
            oTags.append(oEmpty);
        }

        oPinned.replaceChildren();
        const aPinnedPages = aVisiblePages.filter((oPage) => oPage.pinned);
        if (!aPinnedPages.length) {
            const oEmpty = document.createElement('small');
            oEmpty.textContent = '—';
            oPinned.append(oEmpty);
        } else {
            aPinnedPages.forEach((oPage) => oPinned.append(fnCreateNavRow(oPage)));
        }

        const oVisiblePageIds = new Set(aPages.map((oPage) => String(oPage.id)));
        oRecent.replaceChildren();
        const aRecentVisible = bSearchMode
            ? []
            : aRecentPages.filter((oPage) => oVisiblePageIds.has(String(oPage.id)) && (!sTagFilterId || fnHasTag(oPage, sTagFilterId))).slice(0, 6);
        for (const oPage of aRecentVisible) {
            oRecent.append(fnCreateNavRow(oPage, { actions: false }));
        }

        oFrequent.replaceChildren();
        const aFrequentVisible = bSearchMode
            ? []
            : aFrequentPages.filter((oPage) => oVisiblePageIds.has(String(oPage.id)) && (!sTagFilterId || fnHasTag(oPage, sTagFilterId))).slice(0, 6);
        for (const oPage of aFrequentVisible) {
            oFrequent.append(fnCreateNavRow(oPage, { actions: false }));
        }

        const oByParent = new Map();
        for (const oPage of aVisiblePages) {
            const sParentId = oPage.parent_id && aVisiblePages.some((oCandidate) => oCandidate.id === oPage.parent_id)
                ? String(oPage.parent_id)
                : '';
            if (!oByParent.has(sParentId)) oByParent.set(sParentId, []);
            oByParent.get(sParentId).push(oPage);
        }

        /** CZ-FUNKCE: Sestaví jednu větev hierarchického stromu stránek. Vstup: `oPage`, `oVisited`. Výstup: DOM nebo datová struktura větve. */
        const fnBuildBranch = (oPage, oVisited) => {
            const oNode = document.createElement('div');
            oNode.className = 'hc-homewiki-tree-node';
            oNode.dataset.pageId = String(oPage.id);
            const aChildren = oByParent.get(String(oPage.id)) || [];
            oNode.append(fnCreateNavRow(oPage, {
                tree: true,
                hasChildren: aChildren.length > 0,
                searchMode: bSearchMode,
            }));
            if (oVisited.has(oPage.id)) return oNode;

            const oNextVisited = new Set(oVisited);
            oNextVisited.add(oPage.id);
            if (aChildren.length) {
                const oChildren = document.createElement('div');
                oChildren.className = 'hc-homewiki-nav-child';
                oChildren.hidden = !bSearchMode && oCollapsedPageIds.has(String(oPage.id));
                for (const oChild of aChildren) oChildren.append(fnBuildBranch(oChild, oNextVisited));
                oNode.append(oChildren);
            }
            return oNode;
        };

        oTree.replaceChildren();
        const aRootPages = oByParent.get('') || [];
        if (!aRootPages.length) {
            const oEmpty = document.createElement('small');
            oEmpty.textContent = '—';
            oTree.append(oEmpty);
        } else {
            for (const oPage of aRootPages) oTree.append(fnBuildBranch(oPage, new Set()));
        }

        fnApplySidebarSectionDisplay('pinned');
        fnApplySidebarSectionDisplay('recent', { available: aRecentVisible.length > 0 });
        fnApplySidebarSectionDisplay('frequent', { available: aFrequentVisible.length > 0 });
        fnApplySidebarSectionDisplay('tags', { available: aVisibleTags.length > 0 || !!sTagFilterId, forceVisible: !!sTagFilterId, forceExpanded: !!sTagFilterId });
        // Search/tag filtering must always have somewhere to display its results,
        // even when the normal page tree is hidden or collapsed by the user.
        fnApplySidebarSectionDisplay('pages', { forceVisible: bSearchMode || !!sTagFilterId, forceExpanded: bSearchMode || !!sTagFilterId });
    };

    /** CZ-FUNKCE: Načte přehled posledních a často používaných stránek. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnLoadRecentPages = async () => {
        try {
            const oData = await fnApi('/api/recent');
            aRecentPages = Array.isArray(oData.pages) ? oData.pages : [];
            aFrequentPages = Array.isArray(oData.frequent) ? oData.frequent : [];
            bRecentLoaded = true;
            fnRenderNav();
        } catch (oError) {
            oLogger.warn('Home Wiki recent pages could not be loaded.', { oError });
        }
    };

    /** CZ-FUNKCE: Zapíše návštěvu stránky pro výpočet posledních/častých položek. Vstup: `sPageId`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnRecordPageVisit = async (sPageId) => {
        try {
            const oData = await fnApi('/api/pages/' + encodeURIComponent(String(sPageId)) + '/visit', {
                method: 'POST',
                body: '{}',
            });
            aRecentPages = Array.isArray(oData.pages) ? oData.pages : aRecentPages;
            aFrequentPages = Array.isArray(oData.frequent) ? oData.frequent : aFrequentPages;
            bRecentLoaded = true;
            fnRenderNav();
        } catch (oError) {
            // Recent pages are convenience UI only. A failure must never block opening a page.
            oLogger.warn('Home Wiki could not record a recent page visit.', { oError, sPageId });
        }
    };

    /** CZ-FUNKCE: Načte seznam dostupných Wiki štítků. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnLoadTags = async () => {
        try {
            const oData = await fnApi('/api/tags');
            aOwnedTags = Array.isArray(oData.own) ? oData.own : [];
            aVisibleTags = Array.isArray(oData.visible) ? oData.visible : [];
            if (sTagFilterId && !aVisibleTags.some((oTag) => String(oTag.id) === sTagFilterId)) sTagFilterId = '';
        } catch (oError) {
            oLogger.warn('Home Wiki tags could not be loaded.', { oError });
            aOwnedTags = [];
            aVisibleTags = [];
        }
    };

    /** CZ-FUNKCE: Načte stránky dostupné aktuálnímu uživateli. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnLoadPages = async () => {
        const oData = await fnApi('/api/pages');
        aPages = Array.isArray(oData.pages) ? oData.pages : [];
        await fnLoadTags();
        if (!bRecentLoaded) {
            await fnLoadRecentPages();
        }

        let oSettings = null;
        if (!bTreeSettingsLoaded || !bInitialNavigationDone) {
            try {
                oSettings = await fnApi('/api/settings');
                fnApplyStartupSettings(oSettings);
            } catch (oError) {
                fnNotifyError(oError);
            }
        }

        // Remove local collapse markers for pages that no longer exist or are no longer visible.
        const oVisibleIds = new Set(aPages.map((oPage) => String(oPage.id)));
        let bCollapsedChanged = false;
        for (const sId of Array.from(oCollapsedPageIds)) {
            if (!oVisibleIds.has(sId)) {
                oCollapsedPageIds.delete(sId);
                bCollapsedChanged = true;
            }
        }
        if (bCollapsedChanged) fnPersistCollapsedTreeState();
        if (oCurrentPage?.id) fnRevealPageInTree(String(oCurrentPage.id));
        fnRenderNav();

        if (!bInitialNavigationDone) {
            bInitialNavigationDone = true;
            const sHashId = decodeURIComponent(window.location.hash.replace(/^#/, ''));
            if (sHashId && aPages.some((oPage) => oPage.id === sHashId)) {
                await fnOpenPage(sHashId);
                return;
            }
            if (!oSettings) {
                try { oSettings = await fnApi('/api/settings'); }
                catch (oError) { fnNotifyError(oError); }
            }
            const sHomeId = String(oSettings?.effectiveHomePageId || '');
            if (sHomeId && aPages.some((oPage) => String(oPage.id) === sHomeId)) {
                await fnOpenPage(sHomeId);
            }
        }
    };

    /** CZ-FUNKCE: Vykreslí zpětné odkazy vedoucí na aktuální stránku. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRenderBacklinks = () => {
        const oBacklinks = fnById('hc-homewiki-backlinks');
        if (!oBacklinks) {
            return;
        }
        oBacklinks.replaceChildren();

        const aBacklinks = Array.isArray(oCurrentPage?.backlinks) ? oCurrentPage.backlinks : [];
        if (!aBacklinks.length) {
            return;
        }

        const oLabel = document.createElement('strong');
        oLabel.textContent = t(sAppId, 'Backlinks:');
        oBacklinks.append(oLabel, document.createTextNode(' '));
        aBacklinks.forEach((oPage, nIndex) => {
            const oLink = document.createElement('a');
            oLink.href = '#';
            oLink.textContent = String(oPage.title);
            oLink.addEventListener('click', (oEvent) => {
                oEvent.preventDefault();
                void fnOpenPage(String(oPage.id));
            });
            oBacklinks.append(oLink);
            if (nIndex < aBacklinks.length - 1) {
                oBacklinks.append(document.createTextNode(', '));
            }
        });
    };

    /** CZ-FUNKCE: Zastaví sledování vzdálené revize aktuální stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnStopRevisionWatch = () => {
        oRevisionWatcher?.destroy();
        oRevisionWatcher = null;
    };

    /** CZ-FUNKCE: Vyhodnotí novou vzdálenou revizi a případný konflikt s lokální editací. Vstup: `sPageId`, `nRemoteRevision`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnHandleRemoteRevision = async (sPageId, nRemoteRevision) => {
        if (bHandlingRemoteRevision || bDestroyed || !oCurrentPage || String(oCurrentPage.id) !== String(sPageId)) return;
        bHandlingRemoteRevision = true;
        try {
            const oRemotePage = await fnApi('/api/pages/' + encodeURIComponent(String(sPageId)));
            const nLoadedRevision = Number(oRemotePage.revision || nRemoteRevision || 1);
            if (!oCurrentPage || String(oCurrentPage.id) !== String(sPageId)) return;
            if (Number(oCurrentPage.revision || 1) === nLoadedRevision && !bDirty) {
                oRevisionWatcher?.setRevision(nLoadedRevision);
                return;
            }

            // No local edits: refresh immediately but preserve the current read/edit/preview mode.
            if (!bDirty) {
                await fnOpenPage(String(sPageId), false, true);
                return;
            }

            const sLocalContent = fnCurrentSource();
            const oTitle = fnById('hc-homewiki-title');
            const sLocalTitle = String(oTitle?.value || oCurrentPage.title || '');
            const sBaseContent = String(oEditBase?.content || '');
            const sBaseTitle = String(oEditBase?.title || '');
            const sRemoteContent = String(oRemotePage.content || '');
            const sRemoteTitle = String(oRemotePage.title || '');
            const oMerged = oCore.concurrency.mergeText(sBaseContent, sLocalContent, sRemoteContent);

            let sMergedTitle = null;
            if (sLocalTitle === sRemoteTitle) sMergedTitle = sLocalTitle;
            else if (sLocalTitle === sBaseTitle) sMergedTitle = sRemoteTitle;
            else if (sRemoteTitle === sBaseTitle) sMergedTitle = sLocalTitle;

            if (oMerged.status !== 'conflict' && sMergedTitle !== null) {
                Object.assign(oCurrentPage, oRemotePage);
                oEditBase = {
                    content: sRemoteContent,
                    title: sRemoteTitle,
                    revision: nLoadedRevision,
                };
                oRevisionWatcher?.setRevision(nLoadedRevision);
                oCoreEditor?.setValue(oMerged.text);
                if (oTitle) oTitle.value = sMergedTitle;
                nEditRevision++;
                bDirty = oMerged.text !== sRemoteContent || sMergedTitle !== sRemoteTitle;
                fnSetSaveState(t(sAppId, bDirty ? 'Unsaved…' : 'Saved'));
                if (bDirty) {
                    window.clearTimeout(nSaveTimer);
                    nSaveTimer = window.setTimeout(() => void fnSave(), 350);
                }
                if (oMerged.status === 'merged') {
                    oCore.notifications.info(t(sAppId, 'Non-overlapping changes from both users were merged automatically.'), {
                        dedupeKey: 'hc_homewiki:live-merge',
                        cooldownMs: 3000,
                    });
                }
                return;
            }

            // Remember that this remote revision was already reported so the 2 s watcher
            // does not reopen the same conflict repeatedly. The server CAS still protects save.
            oRevisionWatcher?.setRevision(nLoadedRevision);
            const oChoice = await oCore.concurrency.resolveConflict({
                title: t(sAppId, 'Edit conflict'),
                message: t(sAppId, 'The same part of this page was changed elsewhere. Your local text is still preserved.'),
                reloadLabel: t(sAppId, 'Load newer version'),
                keepEditingLabel: t(sAppId, 'Keep my changes'),
            });
            if (oChoice === 'reload') {
                await fnOpenPage(String(sPageId), false, true);
            } else {
                bDirty = true;
                fnSetSaveState(t(sAppId, 'Unsaved…'));
            }
        } catch (oError) {
            if (Number(oError?.status || 0) === 404) {
                fnStopRevisionWatch();
                oCore.notifications.warning(t(sAppId, 'The page no longer exists or you no longer have access to it.'), {
                    dedupeKey: 'hc_homewiki:remote-page-gone',
                    cooldownMs: 3000,
                });
                await fnLoadPages();
            } else {
                fnNotifyError(oError);
            }
        } finally {
            bHandlingRemoteRevision = false;
        }
    };

    /** CZ-FUNKCE: Spustí periodické sledování změn revize otevřené stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnStartRevisionWatch = () => {
        fnStopRevisionWatch();
        if (!oCurrentPage || typeof oCore.concurrency.watchRevision !== 'function') return;
        const sPageId = String(oCurrentPage.id);
        oRevisionWatcher = oCore.concurrency.watchRevision({
            initialRevision: Number(oCurrentPage.revision || 1),
            intervalMs: 2000,
            loadRevision: async () => {
                if (bLoadingPage || bSavingPage || bHandlingRemoteRevision || !oCurrentPage || String(oCurrentPage.id) !== sPageId) {
                    return oRevisionWatcher?.getRevision() ?? Number(oCurrentPage?.revision || 1);
                }
                const oState = await fnApi('/api/pages/' + encodeURIComponent(sPageId) + '/state');
                return Number(oState.revision || 1);
            },
            onChange: async ({ revision }) => {
                await fnHandleRemoteRevision(sPageId, Number(revision));
            },
            onError: (oError) => {
                if (Number(oError?.status || 0) === 404) {
                    fnStopRevisionWatch();
                    void fnLoadPages();
                } else {
                    oLogger.warn('Home Wiki live revision check failed.', { oError, sPageId });
                }
            },
        });
    };

    /** CZ-FUNKCE: Načte a otevře vybranou Wiki stránku. Vstup: `sPageId`, `bFocusEditor = false`, `bForceReload = false`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnOpenPage = async (sPageId, bFocusEditor = false, bForceReload = false) => {
        const sModeBeforeLoad = sEditorMode;
        try {
            if (!bForceReload && oCurrentPage?.id === sPageId && !bLoadingPage) {
                if (bFocusEditor && oCurrentPage.permissions.edit) {
                    fnSetMode('edit');
                    oCoreEditor?.focus();
                }
                return true;
            }
            if (bForceReload) {
                // A conflict handler can run inside the current save promise. Waiting
                // for oSaveQueue here would deadlock on that very same promise.
                window.clearTimeout(nSaveTimer);
                bDirty = false;
            } else {
                if (!(await oSaveQueue) && bDirty) return false;
                if (bDirty) {
                    if (!(await fnSave())) return false;
                }
            }
            fnClosePageMenu();
            fnCloseAppMenu();
            fnStopRevisionWatch();
            bLoadingPage = true;
            oCurrentPage = await fnApi('/api/pages/' + encodeURIComponent(sPageId));
            window.history.replaceState(null, '', '#' + encodeURIComponent(sPageId));

            const oEmpty = fnById('hc-homewiki-empty');
            const oPage = fnById('hc-homewiki-page');
            const oTitle = fnById('hc-homewiki-title');
            const oSource = fnById('hc-homewiki-source');
            const oPin = fnById('hc-homewiki-pin');
            const oShare = fnById('hc-homewiki-share');
            const oDelete = fnById('hc-homewiki-delete');
            const oMeta = fnById('hc-homewiki-page-meta');
            const oMobileCurrent = fnById('hc-homewiki-mobile-current');
            if (!oEmpty || !oPage || !oTitle || !oSource || !oPin || !oShare || !oDelete || !oMeta || !oMobileCurrent) {
                throw new Error(t(sAppId, 'Home Wiki interface is incomplete.'));
            }

            oEmpty.hidden = true;
            oPage.hidden = false;
            oTitle.value = String(oCurrentPage.title);
            oSource.value = String(oCurrentPage.content || '');
            oCoreEditor?.setValue(oSource.value);
            oPin.textContent = oCurrentPage.pinned ? '★' : '☆';
            oShare.disabled = !oCurrentPage.permissions.share;
            oTitle.readOnly = true;
            oSource.readOnly = !oCurrentPage.permissions.edit;
            oCoreEditor?.setReadOnly(!oCurrentPage.permissions.edit);

            const sVisibility = oCurrentPage.visibility === 'all'
                ? t(sAppId, 'All Nextcloud users')
                : oCurrentPage.visibility === 'shared'
                    ? t(sAppId, 'Shared')
                    : t(sAppId, 'Private');
            oMeta.replaceChildren();
            const aMetaValues = [
                [String(oCurrentPage.slug || ''), t(sAppId, 'Page address')],
                [sVisibility, t(sAppId, 'Visibility')],
                [new Date(Number(oCurrentPage.updated_at) * 1000).toLocaleString(), t(sAppId, 'Last updated')],
            ];
            for (const [sValue, sLabel] of aMetaValues) {
                if (!sValue) continue;
                const oItem = document.createElement('span');
                oItem.className = 'hc-homewiki-page-meta-item';
                oItem.textContent = sValue;
                oItem.title = sLabel + ': ' + sValue;
                oMeta.append(oItem);
            }
            fnRenderPageTagBadges();
            void fnRefreshAttachmentBadge();

            oMobileCurrent.textContent = String(oCurrentPage.title);
            oEditBase = {
                content: String(oCurrentPage.content || ''),
                title: String(oCurrentPage.title || ''),
                revision: Number(oCurrentPage.revision || 1),
            };
            nEditRevision = 0;
            bDirty = false;
            fnSetSaveState(t(sAppId, 'Saved'));
            const sReloadMode = bForceReload && ['edit', 'preview'].includes(sModeBeforeLoad)
                ? sModeBeforeLoad : 'read';
            const sNextMode = bFocusEditor && oCurrentPage.permissions.edit
                ? 'edit'
                : (sReloadMode === 'edit' && !oCurrentPage.permissions.edit ? 'read' : sReloadMode);
            fnSetMode(sNextMode);
            const oMain = fnById('hc-homewiki-main');
            if (oMain) oMain.scrollTop = 0;
            fnRenderBacklinks();
            fnCloseSidebar();
            fnRevealPageInTree(String(oCurrentPage.id));
            fnRenderNav();
            fnUpdateToolbar();
            bLoadingPage = false;
            fnStartRevisionWatch();
            void fnRecordPageVisit(String(oCurrentPage.id));
            if (bFocusEditor && oCurrentPage.permissions.edit) requestAnimationFrame(() => oCoreEditor?.focus());
            return true;
        } catch (oError) {
            bLoadingPage = false;
            fnNotifyError(oError);
            return false;
        }
    };

    /** CZ-FUNKCE: Vytvoří novou Wiki stránku. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnCreatePage = () => {
        const oFormController = oCore.forms.create([{
            id: 'title',
            label: t(sAppId, 'Page title'),
            type: 'text',
            value: '',
            placeholder: t(sAppId, 'New page'),
            required: true,
            maxLength: 255,
        }, {
            id: 'template',
            label: t(sAppId, 'Page template'),
            type: 'select',
            value: 'blank',
            options: [
                { value: 'blank', label: t(sAppId, 'Blank page') },
                { value: 'note', label: t(sAppId, 'General note') },
                { value: 'device', label: t(sAppId, 'Device') },
                { value: 'network', label: t(sAppId, 'Network device') },
                { value: 'procedure', label: t(sAppId, 'Procedure / guide') },
            ],
        }]);

        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Create page'),
            content: oFormController.element,
            size: 'small',
            closeLabel: t(sAppId, 'Close'),
            actions: [
                { label: t(sAppId, 'Cancel') },
                {
                    label: t(sAppId, 'Create page'),
                    variant: 'primary',
                    close: false,
                    onClick: async () => {
                        if (!oFormController.validate()) {
                            return;
                        }
                        const sTitle = String(oFormController.values().title || '').trim();
                        if (!sTitle) {
                            return;
                        }
                        try {
                            const sTemplate = String(oFormController.values().template || 'blank');
                            const oTemplates = {
                                blank: '',
                                note: `# ${sTitle}\n\n## Přehled\n\n\n## Poznámky\n\n`,
                                device: `# ${sTitle}\n\n## Základní informace\n\n| Parametr | Hodnota |\n| --- | --- |\n| Výrobce | |\n| Model | |\n| Umístění | |\n\n## Konfigurace\n\n\n## Poznámky\n\n`,
                                network: `# ${sTitle}\n\n## Základní informace\n\n| Parametr | Hodnota |\n| --- | --- |\n| Výrobce | |\n| Model | |\n| Management IP | |\n| Management VLAN | |\n\n## Připojení\n\n\n## VLAN\n\n\n## Poznámky\n\n`,
                                procedure: `# ${sTitle}\n\n## Cíl\n\n\n## Postup\n\n1. \n2. \n3. \n\n## Ověření\n\n`,
                            };
                            const oPage = await fnApi('/api/pages', {
                                method: 'POST',
                                body: JSON.stringify({ title: sTitle, content: oTemplates[sTemplate] ?? '' }),
                            });
                            oDialog.close('action');
                            await fnLoadPages();
                            await fnOpenPage(String(oPage.id), true);
                            oCore.notifications.success(t(sAppId, 'Page created.'), {
                                dedupeKey: 'hc_homewiki:page-created',
                                cooldownMs: 700,
                            });
                        } catch (oError) {
                            fnNotifyError(oError);
                        }
                    },
                },
            ],
            onClose: () => oFormController.destroy(),
        });
        requestAnimationFrame(() => oFormController.element.querySelector('input')?.focus());
    };

    /** CZ-FUNKCE: Zjistí, zda editor obsahuje lokální neuložené změny. Vstup: žádný přímý parametr. Výstup: boolean. */
    const fnHasLocalEditorChanges = () => {
        if (!oCurrentPage?.permissions.edit || !oEditBase) return false;
        const oTitle = fnById('hc-homewiki-title');
        const sCurrentTitle = String(oTitle?.value || oCurrentPage.title || '').trim();
        return fnCurrentSource() !== String(oEditBase.content || '')
            || sCurrentTitle !== String(oEditBase.title || '');
    };

    /** CZ-FUNKCE: Označí editor jako lokálně změněný. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnMarkDirty = () => {
        if (bLoadingPage || !oCurrentPage?.permissions.edit) return;
        nEditRevision++;
        bDirty = true;
        fnSetSaveState(t(sAppId, 'Unsaved…'));
        window.clearTimeout(nSaveTimer);
        window.clearTimeout(nSearchTimer);
        nSaveTimer = window.setTimeout(() => void fnSave(), 1100);
    };

    // Dirty state must never depend on one editor callback only. After a remote
    // live reload the editor controller remains mounted; this local watchdog
    // compares the real editor value with the last server-backed edit base and
    // restores autosave if a browser/editor input callback was missed. It does
    // not access the network and is idle while no local content differs.
    /** CZ-FUNKCE: Spustí sledování změn obsahu editoru. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnStartDirtyWatch = () => {
        if (nDirtyWatchTimer) window.clearInterval(nDirtyWatchTimer);
        nDirtyWatchTimer = window.setInterval(() => {
            if (bDestroyed || bLoadingPage || bSavingPage || sEditorMode !== 'edit'
                || !oCurrentPage?.permissions.edit || bDirty) return;
            if (fnHasLocalEditorChanges()) fnMarkDirty();
        }, 300);
    };

    /** CZ-FUNKCE: Okamžitě uloží aktuální obsah editoru. Vstup: `oExtra = {}`, `nMergeAttempt = 0`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnSaveNow = async (oExtra = {}, nMergeAttempt = 0) => {
        if (!oCurrentPage || !oCurrentPage.permissions.edit) {
            return true;
        }
        const bEditorChanged = fnHasLocalEditorChanges();
        if (!bDirty && !bEditorChanged && !Object.keys(oExtra).length) {
            return true;
        }
        if (bEditorChanged && !bDirty) {
            // Manual Save is authoritative too: if the editor content differs
            // from the loaded base, force a write even when an input event was
            // missed and bDirty was not set.
            nEditRevision++;
            bDirty = true;
            fnSetSaveState(t(sAppId, 'Unsaved…'));
        }
        window.clearTimeout(nSaveTimer);
        fnSetSaveState(t(sAppId, 'Saving…'));

        const sContent = fnCurrentSource();
        const nRevision = nEditRevision;
        const oTitle = fnById('hc-homewiki-title');
        const sLocalTitle = String(oTitle?.value || '').trim() || String(oCurrentPage.title);
        const oPayload = oCore.concurrency.withExpectedRevision({
            title: sLocalTitle,
            slug: fnSlug(String(oCurrentPage.slug)),
            content: sContent,
            pinned: !!oCurrentPage.pinned,
            ...oExtra,
        }, Number(oCurrentPage.revision || 1));

        try {
            let oSavedPage;
            bSavingPage = true;
            try {
                oSavedPage = await fnApi('/api/pages/' + encodeURIComponent(String(oCurrentPage.id)), {
                    method: 'PUT',
                    body: JSON.stringify(oPayload),
                });
            } finally {
                bSavingPage = false;
            }
            Object.assign(oCurrentPage, oSavedPage, { content: sContent });
            oRevisionWatcher?.setRevision(Number(oSavedPage.revision || oCurrentPage.revision || 1));
            oEditBase = {
                content: sContent,
                title: String(oSavedPage.title || sLocalTitle),
                revision: Number(oSavedPage.revision || oCurrentPage.revision || 1),
            };
            bDirty = nEditRevision !== nRevision || fnCurrentSource() !== sContent;
            fnSetSaveState(t(sAppId, bDirty ? 'Unsaved…' : 'Saved'));
            await fnLoadPages();
            if (bDirty) fnMarkDirty();
            return !bDirty;
        } catch (oError) {
            fnSetSaveState(t(sAppId, 'Save failed'));
            if (oCore.concurrency.isConflict(oError)) {
                const sPageId = String(oCurrentPage?.id || '');
                const sLocalContent = sContent;
                let oRemotePage = null;
                try {
                    if (sPageId) oRemotePage = await fnApi('/api/pages/' + encodeURIComponent(sPageId));
                } catch (oReloadError) {
                    if (Number(oReloadError?.status || 0) === 404) {
                        oCore.notifications.warning(t(sAppId, 'The page no longer exists or you no longer have access to it.'));
                    } else {
                        fnNotifyError(oReloadError);
                    }
                }

                // For a normal text autosave, attempt one conservative three-way
                // merge against the exact content that this editor originally loaded.
                // Core refuses overlapping edits; server CAS is still authoritative.
                if (oRemotePage && oEditBase && !Object.keys(oExtra).length && nMergeAttempt < 1) {
                    const oMerged = oCore.concurrency.mergeText(
                        String(oEditBase.content || ''),
                        sLocalContent,
                        String(oRemotePage.content || ''),
                    );
                    const sBaseTitle = String(oEditBase.title || '');
                    const sRemoteTitle = String(oRemotePage.title || '');
                    let sMergedTitle = null;
                    if (sLocalTitle === sRemoteTitle) sMergedTitle = sLocalTitle;
                    else if (sLocalTitle === sBaseTitle) sMergedTitle = sRemoteTitle;
                    else if (sRemoteTitle === sBaseTitle) sMergedTitle = sLocalTitle;

                    if (oMerged.status !== 'conflict' && sMergedTitle !== null) {
                        if (oMerged.text === String(oRemotePage.content || '') && sMergedTitle === sRemoteTitle) {
                            // Nothing local remains after resolving the conflict. Force a
                            // fresh render without waiting for the save queue (we are inside it).
                            await fnOpenPage(sPageId, false, true);
                            oCore.notifications.info(t(sAppId, 'A newer version was loaded.'));
                            return true;
                        }

                        Object.assign(oCurrentPage, oRemotePage);
                        oEditBase = {
                            content: String(oRemotePage.content || ''),
                            title: sRemoteTitle,
                            revision: Number(oRemotePage.revision || 1),
                        };
                        oCoreEditor?.setValue(oMerged.text);
                        if (oTitle) oTitle.value = sMergedTitle;
                        nEditRevision++;
                        bDirty = true;
                        fnSetSaveState(t(sAppId, 'Merging changes…'));
                        const bSaved = await fnSaveNow({}, nMergeAttempt + 1);
                        if (bSaved) {
                            oCore.notifications.success(t(sAppId, 'Non-overlapping changes from both users were merged automatically.'), {
                                dedupeKey: 'hc_homewiki:auto-merge',
                                cooldownMs: 1000,
                            });
                        }
                        return bSaved;
                    }
                }

                const oChoice = await oCore.concurrency.resolveConflict({
                    title: t(sAppId, 'Edit conflict'),
                    message: t(sAppId, 'The same part of this page was changed elsewhere. Your local text is still preserved.'),
                    reloadLabel: t(sAppId, 'Load newer version'),
                    keepEditingLabel: t(sAppId, 'Keep my changes'),
                });
                if (oChoice === 'reload' && sPageId) {
                    await fnOpenPage(sPageId, false, true);
                    oCore.notifications.info(t(sAppId, 'A newer version was loaded.'));
                } else {
                    bDirty = true;
                    oCoreEditor?.setValue(sLocalContent);
                    fnSetSaveState(t(sAppId, 'Unsaved…'));
                }
                return false;
            }
            fnNotifyError(oError);
            return false;
        }
    };

    // Every autosave and page action waits for the previous write to finish.
    /** CZ-FUNKCE: Naplánuje nebo provede uložení aktuální stránky podle stavu editoru. Vstup: `oExtra = {}`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnSave = (oExtra = {}) => {
        const oNext = oSaveQueue.then(() => fnSaveNow(oExtra));
        oSaveQueue = oNext.catch(() => false);
        return oNext;
    };

    /** CZ-FUNKCE: Vytvoří jednoduchý textový dialog pro uživatelský vstup. Vstup: `sTitle`, `sLabel`, `sInitialValue = ''`, `sActionLabel = t(sAppId, 'Insert')`, `nMaxLength = 1024`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnTextDialog = async (sTitle, sLabel, sInitialValue = '', sActionLabel = t(sAppId, 'Insert'), nMaxLength = 1024) => {
        const oFormController = oCore.forms.create([{
            id: 'value',
            label: sLabel,
            type: 'text',
            value: sInitialValue,
            required: true,
            maxLength: nMaxLength,
        }]);

        return new Promise((fnResolve) => {
            let bResolved = false;
            /** CZ-FUNKCE: Dokončí příslušnou operaci a uklidí její dočasný stav. Vstup: `sValue`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
            const fnFinish = (sValue) => {
                if (!bResolved) {
                    bResolved = true;
                    fnResolve(sValue);
                }
            };
            const oDialog = oCore.dialogs.open({
                title: sTitle,
                content: oFormController.element,
                size: 'small',
                actions: [
                    { label: t(sAppId, 'Cancel'), onClick: () => fnFinish(null) },
                    {
                        label: sActionLabel,
                        variant: 'primary',
                        close: false,
                        onClick: () => {
                            if (!oFormController.validate()) {
                                return;
                            }
                            fnFinish(String(oFormController.values().value || ''));
                            oDialog.close('action');
                        },
                    },
                ],
                onClose: () => {
                    oFormController.destroy();
                    fnFinish(null);
                },
            });
        });
    };

    /** CZ-FUNKCE: Vloží do editoru interní odkaz na vybranou Wiki stránku. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnInsertWikiLink = async () => {
        if (!oCurrentPage?.permissions.edit) return;
        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-page-picker';
        const oSearch = document.createElement('input');
        oSearch.type = 'search';
        oSearch.placeholder = t(sAppId, 'Search wiki');
        const oResults = document.createElement('div');
        oResults.className = 'hc-homewiki-page-picker-results';
        oContent.append(oSearch, oResults);
        let oDialog = null;
        /** CZ-FUNKCE: Vykreslí příslušnou část uživatelského rozhraní. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
        const fnRender = () => {
            const sNeedle = oSearch.value.trim().toLowerCase();
            oResults.replaceChildren();
            aPages.filter((oPage) => String(oPage.id) !== String(oCurrentPage.id))
                .filter((oPage) => !sNeedle || (String(oPage.title) + ' ' + String(oPage.slug)).toLowerCase().includes(sNeedle))
                .slice(0, 50).forEach((oPage) => {
                    const oButton = document.createElement('button');
                    oButton.type = 'button';
                    oButton.className = 'hc-homewiki-page-picker-item';
                    oButton.textContent = String(oPage.title);
                    oButton.addEventListener('click', () => {
                        fnSetMode('edit');
                        oCoreEditor?.insertText('[[' + String(oPage.title).replace(/[\]\n]/g, '') + ']]');
                        oDialog?.close('selected');
                    });
                    oResults.append(oButton);
                });
        };
        oSearch.addEventListener('input', fnRender);
        fnRender();
        oDialog = oCore.dialogs.open({ title: t(sAppId, 'Wiki link'), content: oContent, size: 'small', actions: [{ label: t(sAppId, 'Cancel') }] });
        requestAnimationFrame(() => oSearch.focus());
    };

    /** CZ-FUNKCE: Sestaví sadu akcí editorového toolbaru. Vstup: žádný přímý parametr. Výstup: pole akcí toolbaru. */
    const fnToolbarActions = () => [{
        id: 'wikilink', label: t(sAppId, 'Wiki link'), icon: '[[ ]]', compact: true,
        disabled: !oCurrentPage?.permissions.edit, onClick: fnInsertWikiLink,
    }, {
        id: 'save', label: t(sAppId, 'Save'), icon: '💾', compact: true,
        disabled: !oCurrentPage?.permissions.edit, onClick: () => fnSave(),
    }];

    /** CZ-FUNKCE: Aktualizuje stav a dostupnost editorového toolbaru. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnUpdateToolbar = () => {
        oToolbarController?.update(fnToolbarActions());
    };

    /** CZ-FUNKCE: Nahraje obrázek a vloží jeho odkaz do editoru. Vstup: `oFile`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnUploadImage = async (oFile) => {
        if (!oCurrentPage || !oFile || !oCurrentPage.permissions.edit) {
            return;
        }
        const oFormData = new FormData();
        oFormData.append('file', oFile);

        try {
            const oResult = await fnApi('/api/pages/' + encodeURIComponent(String(oCurrentPage.id)) + '/media', {
                method: 'POST',
                body: oFormData,
            });
            const sRelativePath = String(oResult.path || '').startsWith('media/') ? '../' + oResult.path : String(oResult.path || '');
            const sSource = fnMediaUrl(sRelativePath);
            if (sEditorMode !== 'edit') {
                fnSetMode('edit');
            }
            document.execCommand(
                'insertHTML',
                false,
                `<p><img src="${fnEscapeHtml(sSource)}" alt="${fnEscapeHtml(oFile.name)}"></p>`,
            );
            fnMarkDirty();
        } catch (oError) {
            fnNotifyError(oError);
        }
    };

    /** CZ-FUNKCE: Vykreslí řádky explicitních oprávnění ke stránce. Vstup: `oHost`, `bReadInherited = false`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnRenderAclRows = (oHost, bReadInherited = false) => {
        oHost.replaceChildren();
        for (const [nIndex, oAclEntry] of aAclDraft.entries()) {
            const oRow = document.createElement('div');
            oRow.className = 'hc-homewiki-acl-row';

            const oName = document.createElement('span');
            oName.className = 'hc-homewiki-acl-name';
            const oIdentity = document.createElement('strong');
            oIdentity.textContent = String(oAclEntry.label || oAclEntry.id);
            const oId = document.createElement('small');
            oId.textContent = String(oAclEntry.id);
            oName.append(document.createTextNode(oAclEntry.type === 'group' ? '👥 ' : '👤 '), oIdentity, document.createElement('br'), oId);

            /** CZ-FUNKCE: Normalizuje hodnotu oprávnění používanou ve sdílení. Vstup: `sKey`, `sLabel`. Výstup: normalizovaná hodnota oprávnění. */
            const fnPermission = (sKey, sLabel) => {
                const oLabel = document.createElement('label');
                const oInput = document.createElement('input');
                oInput.type = 'checkbox';
                oInput.checked = sKey === 'canRead' && bReadInherited ? true : !!oAclEntry[sKey];
                oInput.disabled = sKey === 'canRead' && bReadInherited;
                if (sKey === 'canRead' && bReadInherited) {
                    aAclDraft[nIndex].canRead = true;
                }
                oInput.addEventListener('change', () => {
                    aAclDraft[nIndex][sKey] = oInput.checked;
                    if (sKey === 'canRead' && !oInput.checked) {
                        aAclDraft[nIndex].canEdit = false;
                        aAclDraft[nIndex].canDelete = false;
                        aAclDraft[nIndex].canShare = false;
                        fnRenderAclRows(oHost, bReadInherited);
                    } else if (sKey !== 'canRead' && oInput.checked) {
                        aAclDraft[nIndex].canRead = true;
                        fnRenderAclRows(oHost, bReadInherited);
                    }
                });
                oLabel.append(oInput, document.createTextNode(' ' + sLabel));
                return oLabel;
            };

            oRow.append(
                oName,
                fnPermission('canRead', t(sAppId, 'Read')),
                fnPermission('canEdit', t(sAppId, 'Edit')),
                fnPermission('canDelete', t(sAppId, 'Delete')),
                fnPermission('canShare', t(sAppId, 'Share further')),
            );
            oHost.append(oRow);
        }
    };

    /** CZ-FUNKCE: Otevře dialog sdílení a oprávnění stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnOpenShare = () => {
        if (!oCurrentPage?.permissions.share) {
            return;
        }

        aAclDraft = (oCurrentPage.acl || []).map((oEntry) => ({ ...oEntry, label: oEntry.id }));
        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-share-content';

        const oVisibilityLabel = document.createElement('label');
        const oVisibilityText = document.createElement('span');
        const oVisibilitySelect = document.createElement('select');
        oVisibilityText.textContent = t(sAppId, 'Visibility');
        for (const [sValue, sLabel] of [
            ['private', t(sAppId, 'Private')],
            ['shared', t(sAppId, 'Selected users and groups')],
            ['all', t(sAppId, 'All Nextcloud users')],
        ]) {
            const oOption = document.createElement('option');
            oOption.value = sValue;
            oOption.textContent = sLabel;
            oVisibilitySelect.append(oOption);
        }
        oVisibilitySelect.value = String(oCurrentPage.visibility);
        oVisibilityLabel.append(oVisibilityText, oVisibilitySelect);

        const oPrivateInfo = document.createElement('p');
        oPrivateInfo.className = 'hc-homewiki-share-info';
        oPrivateInfo.textContent = t(sAppId, 'Only the page owner can access a private page.');

        const oGlobalBlock = document.createElement('fieldset');
        oGlobalBlock.className = 'hc-homewiki-global-permissions';
        const oGlobalLegend = document.createElement('legend');
        oGlobalLegend.textContent = t(sAppId, 'Permissions for all Nextcloud users');
        const oGlobalHelp = document.createElement('p');
        oGlobalHelp.className = 'hc-homewiki-share-info';
        oGlobalHelp.textContent = t(sAppId, 'Read is always enabled in this mode. Other permissions apply to every authenticated Nextcloud user.');

        /** CZ-FUNKCE: Vrátí globální oprávnění aktuální stránky. Vstup: `sLabel`, `bChecked`, `bDisabled = false`. Výstup: hodnota globálního oprávnění. */
        const fnGlobalPermission = (sLabel, bChecked, bDisabled = false) => {
            const oLabel = document.createElement('label');
            const oInput = document.createElement('input');
            oInput.type = 'checkbox';
            oInput.checked = bChecked;
            oInput.disabled = bDisabled;
            oLabel.append(oInput, document.createTextNode(' ' + sLabel));
            return { label: oLabel, input: oInput };
        };
        const oGlobalRead = fnGlobalPermission(t(sAppId, 'Read'), true, true);
        const oGlobalEdit = fnGlobalPermission(t(sAppId, 'Edit'), !!oCurrentPage.allPermissions?.edit);
        const oGlobalDelete = fnGlobalPermission(t(sAppId, 'Delete'), !!oCurrentPage.allPermissions?.delete);
        const oGlobalShare = fnGlobalPermission(t(sAppId, 'Share further'), !!oCurrentPage.allPermissions?.share);
        const oGlobalChecks = document.createElement('div');
        oGlobalChecks.className = 'hc-homewiki-permission-grid';
        oGlobalChecks.append(oGlobalRead.label, oGlobalEdit.label, oGlobalDelete.label, oGlobalShare.label);
        oGlobalBlock.append(oGlobalLegend, oGlobalHelp, oGlobalChecks);

        const oSpecificDetails = document.createElement('details');
        oSpecificDetails.className = 'hc-homewiki-specific-permissions';
        const oSpecificSummary = document.createElement('summary');
        const oSpecificHelp = document.createElement('p');
        oSpecificHelp.className = 'hc-homewiki-share-info';
        const oPickerHost = document.createElement('div');
        oPickerHost.className = 'hc-homewiki-share-picker';
        const oAclHost = document.createElement('div');
        oAclHost.className = 'hc-homewiki-acl-list hc-shared-app-core-scroll-area';
        oSpecificDetails.append(oSpecificSummary, oSpecificHelp, oPickerHost, oAclHost);

        const oDescendantBlock = document.createElement('div');
        oDescendantBlock.className = 'hc-homewiki-share-descendants';
        const oDescendantLabel = document.createElement('label');
        const oDescendantInput = document.createElement('input');
        oDescendantInput.type = 'checkbox';
        oDescendantLabel.append(oDescendantInput, document.createTextNode(' ' + t(sAppId, 'Apply the same sharing and permissions to all existing subpages')));
        const oDescendantHelp = document.createElement('small');
        oDescendantHelp.textContent = t(sAppId, 'This is a one-time update of current descendants. Future subpages keep their own sharing settings.');
        oDescendantBlock.append(oDescendantLabel, oDescendantHelp);

        oContent.append(oVisibilityLabel, oPrivateInfo, oGlobalBlock, oSpecificDetails, oDescendantBlock);

        /** CZ-FUNKCE: Převede výsledky vyhledávání identit na položky pickeru. Vstup: žádný přímý parametr. Výstup: pole položek pickeru. */
        const fnPickerItems = () => aAclDraft.map((oEntry) => ({
            id: oEntry.id,
            label: oEntry.label || oEntry.id,
            type: oEntry.type,
        }));

        oPickerController = oCore.picker.create(oPickerHost, {
            endpoint: OC.generateUrl('/apps/' + sAppId + '/api/users'),
            types: ['user', 'group'],
            multiple: true,
            placeholder: t(sAppId, 'Search users or groups'),
            selected: fnPickerItems(),
            onChange: (aSelected) => {
                const oOldEntries = new Map(aAclDraft.map((oEntry) => [oEntry.type + ':' + oEntry.id, oEntry]));
                aAclDraft = aSelected.map((oSelected) => {
                    const oPrevious = oOldEntries.get(oSelected.type + ':' + oSelected.id);
                    return {
                        type: oSelected.type,
                        id: oSelected.id,
                        label: oSelected.label,
                        canRead: oPrevious?.canRead ?? true,
                        canEdit: oPrevious?.canEdit || false,
                        canDelete: oPrevious?.canDelete || false,
                        canShare: oPrevious?.canShare || false,
                    };
                });
                fnRenderAclRows(oAclHost, oVisibilitySelect.value === 'all');
            },
        });

        /** CZ-FUNKCE: Aktualizuje zobrazenou viditelnost a oprávnění stránky. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
        const fnRefreshVisibility = () => {
            const sVisibility = oVisibilitySelect.value;
            const bPrivate = sVisibility === 'private';
            const bShared = sVisibility === 'shared';
            const bAll = sVisibility === 'all';

            // Use both hidden and display so a component stylesheet cannot make
            // an inactive sharing mode visible again.
            oPrivateInfo.hidden = !bPrivate;
            oPrivateInfo.style.display = bPrivate ? '' : 'none';
            oGlobalBlock.hidden = !bAll;
            oGlobalBlock.style.display = bAll ? '' : 'none';
            oSpecificDetails.hidden = bPrivate;
            oSpecificDetails.style.display = bPrivate ? 'none' : '';

            if (bShared) {
                oSpecificSummary.textContent = t(sAppId, 'Selected users and groups');
                oSpecificHelp.textContent = t(sAppId, 'Choose who can access the page and set permissions for each user or group.');
                oSpecificDetails.open = true;
            } else if (bAll) {
                oSpecificSummary.textContent = t(sAppId, 'Individual permission extensions');
                oSpecificHelp.textContent = t(sAppId, 'Optional: grant selected users or groups additional permissions beyond the permissions for everyone.');
                oSpecificDetails.open = aAclDraft.length > 0;
            }
            fnRenderAclRows(oAclHost, bAll);
        };

        oVisibilitySelect.addEventListener('change', fnRefreshVisibility);
        fnRefreshVisibility();

        oShareDialog = oCore.dialogs.open({
            title: t(sAppId, 'Page sharing'),
            content: oContent,
            size: 'medium',
            closeLabel: t(sAppId, 'Close'),
            actions: [
                { label: t(sAppId, 'Cancel') },
                {
                    label: t(sAppId, 'Save'),
                    variant: 'primary',
                    close: false,
                    onClick: async () => {
                        try {
                            const sVisibility = oVisibilitySelect.value;
                            const oSavedPage = await fnApi('/api/pages/' + encodeURIComponent(String(oCurrentPage.id)), {
                                method: 'PUT',
                                body: JSON.stringify(oCore.concurrency.withExpectedRevision({
                                    visibility: sVisibility,
                                    allPermissions: sVisibility === 'all' ? {
                                        read: true,
                                        edit: oGlobalEdit.input.checked,
                                        delete: oGlobalDelete.input.checked,
                                        share: oGlobalShare.input.checked,
                                    } : { read: false, edit: false, delete: false, share: false },
                                    acl: sVisibility === 'private' ? [] : aAclDraft.map((oEntry) => ({
                                        type: oEntry.type,
                                        id: oEntry.id,
                                        canRead: !!oEntry.canRead,
                                        canEdit: !!oEntry.canEdit,
                                        canDelete: !!oEntry.canDelete,
                                        canShare: !!oEntry.canShare,
                                    })),
                                    applyToDescendants: oDescendantInput.checked,
                                }, Number(oCurrentPage.revision || 1))),
                            });
                            Object.assign(oCurrentPage, oSavedPage);
                            oRevisionWatcher?.setRevision(Number(oSavedPage.revision || oCurrentPage.revision || 1));
                            oShareDialog?.close('action');
                            const oPropagation = oSavedPage.sharePropagation;
                            const sShareMessage = oPropagation && Number(oPropagation.total || 0) > 0
                                ? t(sAppId, 'Sharing saved. Updated subpages: {updated}; skipped: {skipped}.', {
                                    updated: Number(oPropagation.updated || 0),
                                    skipped: Number(oPropagation.skipped || 0),
                                })
                                : t(sAppId, 'Sharing saved.');
                            oCore.notifications.success(sShareMessage, {
                                dedupeKey: 'hc_homewiki:sharing',
                                cooldownMs: 800,
                            });
                            await fnLoadPages();
                            fnRenderNav();
                        } catch (oError) {
                            if (oCore.concurrency.isConflict(oError)) {
                                oShareDialog?.close('conflict');
                                const oChoice = await oCore.concurrency.resolveConflict({
                                    title: t(sAppId, 'Edit conflict'),
                                    message: t(sAppId, 'Sharing was changed in another window. Your changes were not written.'),
                                    reloadLabel: t(sAppId, 'Load newer version'),
                                    keepEditingLabel: t(sAppId, 'Close'),
                                });
                                if (oChoice === 'reload' && oCurrentPage) { const sId = String(oCurrentPage.id); await fnOpenPage(sId, false, true); }
                            } else {
                                fnNotifyError(oError);
                            }
                        }
                    },
                },
            ],
            onClose: () => {
                oPickerController?.destroy();
                oPickerController = null;
                oShareDialog = null;
            },
        });
    };

    /** CZ-FUNKCE: Otevře uživatelské nastavení Home Wiki. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnOpenSettings = async () => {
        try {
            const oSettings = await fnApi('/api/settings');
            const oContent = document.createElement('div');
            oContent.className = 'hc-homewiki-settings-content';

            /** CZ-FUNKCE: Vytvoří výběrový prvek pro volbu Wiki stránky. Vstup: `sValue`, `bOnlyGlobalSafe = false`. Výstup: DOM prvek výběru. */
            const fnPageSelect = (sValue, bOnlyGlobalSafe = false) => {
                const oSelect = document.createElement('select');
                const oNone = document.createElement('option');
                oNone.value = '';
                oNone.textContent = t(sAppId, 'No custom start page');
                oSelect.append(oNone);
                for (const oPage of aPages) {
                    if (bOnlyGlobalSafe && String(oPage.visibility) !== 'all') continue;
                    const oOption = document.createElement('option');
                    oOption.value = String(oPage.id);
                    oOption.textContent = String(oPage.title);
                    oSelect.append(oOption);
                }
                oSelect.value = String(sValue || '');
                return oSelect;
            };

            const oPersonalLabel = document.createElement('label');
            const oPersonalText = document.createElement('span');
            oPersonalText.textContent = t(sAppId, 'My start page');
            const oPersonal = fnPageSelect(oSettings.personalHomePageId);
            oPersonalLabel.append(oPersonalText, oPersonal);
            oContent.append(oPersonalLabel);

            const oAppearanceSeparator = document.createElement('hr');
            const oAppearanceTitle = document.createElement('strong');
            oAppearanceTitle.textContent = t(sAppId, 'Appearance');
            const oMenuDensityLabel = document.createElement('label');
            const oMenuDensityText = document.createElement('span');
            oMenuDensityText.textContent = t(sAppId, 'Menu and tree density');
            const oMenuDensity = document.createElement('select');
            for (const [sValue, sLabel] of [
                ['compact', t(sAppId, 'Compact')],
                ['comfortable', t(sAppId, 'Comfortable')],
            ]) {
                const oOption = document.createElement('option');
                oOption.value = sValue;
                oOption.textContent = sLabel;
                oMenuDensity.append(oOption);
            }
            oMenuDensity.value = String(oSettings.menuDensity || 'compact') === 'comfortable' ? 'comfortable' : 'compact';
            oMenuDensityLabel.append(oMenuDensityText, oMenuDensity);
            const oMenuDensityHint = document.createElement('small');
            oMenuDensityHint.textContent = t(sAppId, 'Compact mode uses less vertical space in menus and the page tree.');

            const oPageHeaderLabel = document.createElement('label');
            const oPageHeaderText = document.createElement('span');
            oPageHeaderText.textContent = t(sAppId, 'Page header');
            const oPageHeaderMode = document.createElement('select');
            for (const [sValue, sLabel] of [
                ['scroll', t(sAppId, 'Scroll with content')],
                ['sticky', t(sAppId, 'Keep visible while scrolling')],
            ]) {
                const oOption = document.createElement('option');
                oOption.value = sValue;
                oOption.textContent = sLabel;
                oPageHeaderMode.append(oOption);
            }
            oPageHeaderMode.value = String(oSettings.pageHeaderMode || 'scroll') === 'sticky' ? 'sticky' : 'scroll';
            oPageHeaderLabel.append(oPageHeaderText, oPageHeaderMode);
            const oPageHeaderHint = document.createElement('small');
            oPageHeaderHint.textContent = t(sAppId, 'The page header keeps the title, visibility, date, tags, attachments and page actions together on one line.');

            oContent.append(
                oAppearanceSeparator,
                oAppearanceTitle,
                oMenuDensityLabel,
                oMenuDensityHint,
                oPageHeaderLabel,
                oPageHeaderHint,
            );

            const oTreeSeparator = document.createElement('hr');
            const oTreeTitle = document.createElement('strong');
            oTreeTitle.textContent = t(sAppId, 'Page tree');
            const oTreeDragLabel = document.createElement('label');
            oTreeDragLabel.className = 'hc-homewiki-settings-check';
            const oTreeDrag = document.createElement('input');
            oTreeDrag.type = 'checkbox';
            oTreeDrag.checked = !!oSettings.treeDragEnabled;
            oTreeDragLabel.append(oTreeDrag, document.createTextNode(' ' + t(sAppId, 'Enable drag-and-drop tree organization')));
            const oTreeHint = document.createElement('small');
            oTreeHint.textContent = t(sAppId, 'Keep this disabled when the page tree is stable to prevent accidental moves.');
            oContent.append(oTreeSeparator, oTreeTitle, oTreeDragLabel, oTreeHint);

            let oAdminPage = null;
            let oAdminScope = null;
            if (oSettings.isAdmin) {
                const oSeparator = document.createElement('hr');
                oContent.append(oSeparator);
                const oAdminTitle = document.createElement('strong');
                oAdminTitle.textContent = t(sAppId, 'Administrator default');
                oContent.append(oAdminTitle);

                const oScopeLabel = document.createElement('label');
                const oScopeText = document.createElement('span');
                oScopeText.textContent = t(sAppId, 'Default applies to');
                oAdminScope = document.createElement('select');
                for (const [sValue, sLabel] of [
                    ['owner', t(sAppId, 'Only me')],
                    ['all', t(sAppId, 'All users without their own start page')],
                ]) {
                    const oOption = document.createElement('option');
                    oOption.value = sValue;
                    oOption.textContent = sLabel;
                    oAdminScope.append(oOption);
                }
                oAdminScope.value = String(oSettings.adminDefaultScope || 'all');
                oScopeLabel.append(oScopeText, oAdminScope);

                const oAdminLabel = document.createElement('label');
                const oAdminText = document.createElement('span');
                oAdminText.textContent = t(sAppId, 'Administrator start page');
                oAdminPage = fnPageSelect(oSettings.adminDefaultHomePageId);
                oAdminLabel.append(oAdminText, oAdminPage);
                const oHint = document.createElement('small');
                oHint.textContent = t(sAppId, 'For all users, the selected page must be visible to all Nextcloud users.');
                /** CZ-FUNKCE: Aplikuje administrátorský rozsah na příslušné nastavení. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
                const fnApplyAdminScope = () => {
                    const bGlobal = oAdminScope?.value === 'all';
                    for (const oOption of Array.from(oAdminPage?.options || [])) {
                        if (!oOption.value) continue;
                        const oPage = aPages.find((oCandidate) => String(oCandidate.id) === String(oOption.value));
                        oOption.disabled = !!(bGlobal && oPage && String(oPage.visibility) !== 'all');
                    }
                    if (oAdminPage?.selectedOptions?.[0]?.disabled) {
                        oAdminPage.value = '';
                    }
                };
                oAdminScope.addEventListener('change', fnApplyAdminScope);
                fnApplyAdminScope();
                oContent.append(oScopeLabel, oAdminLabel, oHint);
            }

            const oDialog = oCore.dialogs.open({
                title: t(sAppId, 'Wiki settings'),
                content: oContent,
                size: 'medium',
                actions: [
                    { label: t(sAppId, 'Cancel') },
                    {
                        label: t(sAppId, 'Save'), variant: 'primary', close: false,
                        onClick: async () => {
                            try {
                                const oPayload = {
                                    personalHomePageId: oPersonal.value || '',
                                    treeDragEnabled: !!oTreeDrag.checked,
                                    menuDensity: oMenuDensity.value === 'comfortable' ? 'comfortable' : 'compact',
                                    pageHeaderMode: oPageHeaderMode.value === 'sticky' ? 'sticky' : 'scroll',
                                };
                                if (oSettings.isAdmin) {
                                    oPayload.adminDefaultHomePageId = oAdminPage?.value || '';
                                    oPayload.adminDefaultScope = oAdminScope?.value || 'all';
                                }
                                const oSavedSettings = await fnApi('/api/settings', { method: 'PUT', body: JSON.stringify(oPayload) });
                                fnApplyStartupSettings(oSavedSettings);
                                fnRenderNav();
                                oDialog.close('action');
                                oCore.notifications.success(t(sAppId, 'Settings saved.'));
                                const sEffectiveHomeId = String(oSavedSettings?.effectiveHomePageId || '');
                                if (!oPayload.personalHomePageId && sEffectiveHomeId) {
                                    await fnOpenPage(sEffectiveHomeId);
                                }
                            } catch (oError) {
                                fnNotifyError(oError);
                            }
                        },
                    },
                ],
            });
        } catch (oError) {
            fnNotifyError(oError);
        }
    };

    /** CZ-FUNKCE: Otevře informace o aplikaci a dostupných aktualizacích. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnOpenAbout = () => {
        const oHost = document.createElement('div');
        const oAboutHost = document.createElement('div');
        const oBackupActions = document.createElement('div');
        oBackupActions.className = 'hc-homewiki-backup-actions';
        const oSnapshot = document.createElement('button');
        oSnapshot.type = 'button';
        oSnapshot.textContent = t(sAppId, 'Update Markdown backup metadata');
        oSnapshot.onclick = async () => {
            try {
                const oResult = await fnApi('/api/snapshot', { method: 'POST', body: '{}' });
                oCore.notifications.success(t(sAppId, 'Updated pages: {count}', { count: oResult.updated }));
            } catch (oError) { fnNotifyError(oError); }
        };
        const oRestore = document.createElement('button');
        oRestore.type = 'button';
        oRestore.textContent = t(sAppId, 'Restore pages from Markdown');
        oRestore.onclick = () => void fnRecoverPages();
        oBackupActions.append(oSnapshot, oRestore);
        oHost.append(oAboutHost, oBackupActions);
        oAboutController = oCore.about.mount(oAboutHost, {
            requiredCoreVersion: sRequiredCoreVersion,
            heading: t(sAppId, 'About Home Wiki'),
        });
        oCore.dialogs.open({
            title: t(sAppId, 'About'),
            content: oHost,
            size: 'large',
            closeLabel: t(sAppId, 'Close'),
            actions: [{ label: t(sAppId, 'Close') }],
            onClose: () => {
                oAboutController?.destroy();
                oAboutController = null;
            },
        });
    };

    /** CZ-FUNKCE: Spustí obnovu stránek z dostupných souborových dat. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnRecoverPages = async () => {
        try {
            const oPreview = await fnApi('/api/recovery');
            if (!oPreview.available) {
                oCore.notifications.info(t(sAppId, 'No missing pages were found in Markdown files.'));
                return;
            }
            const bConfirmed = await oCore.dialogs.confirm({
                title: t(sAppId, 'Restore pages from Markdown'),
                message: t(sAppId, 'Add {count} missing pages to the Wiki index? Existing pages will not be changed.', { count: oPreview.available }),
                confirmLabel: t(sAppId, 'Restore'),
                cancelLabel: t(sAppId, 'Cancel'),
            });
            if (!bConfirmed) return;
            const oResult = await fnApi('/api/recovery', { method: 'POST', body: '{}' });
            await fnLoadPages();
            if (!oCurrentPage && aPages.length) await fnOpenPage(String(aPages[0].id));
            oCore.notifications.success(t(sAppId, 'Restored pages: {count}', { count: oResult.restored }));
        } catch (oError) { fnNotifyError(oError); }
    };

    /** CZ-FUNKCE: Nastaví obsah a stav pomocného postranního panelu. Vstup: `bOpen`, `oReturnFocus = null`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnSetSidebar = (bOpen, oReturnFocus = null) => {
        const oApp = fnById('hc-homewiki-app');
        const oBackdrop = fnById('hc-homewiki-sidebar-backdrop');
        const oOpenButton = fnById('hc-homewiki-sidebar-open');
        const oCloseButton = fnById('hc-homewiki-sidebar-close');
        if (!oApp || !oBackdrop || !oOpenButton) {
            return;
        }

        if (bOpen) {
            oSidebarReturnFocus = oReturnFocus || document.activeElement;
        }
        oApp.classList.toggle('hc-homewiki-sidebar-open', bOpen);
        oBackdrop.hidden = !bOpen;
        oOpenButton.setAttribute('aria-expanded', bOpen ? 'true' : 'false');

        if (bOpen) {
            window.requestAnimationFrame(() => oCloseButton?.focus());
        } else if (oSidebarReturnFocus instanceof HTMLElement) {
            oSidebarReturnFocus.focus();
            oSidebarReturnFocus = null;
        }
    };

    /** CZ-FUNKCE: Zavře pomocný postranní panel. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnCloseSidebar = () => {
        if (fnById('hc-homewiki-app')?.dataset.coreLayout !== 'desktop') {
            fnSetSidebar(false);
        }
    };

    /** CZ-FUNKCE: Otevře Koš Home Wiki. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnOpenTrash = async () => {
        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-trash-content';
        const oInfo = document.createElement('p');
        oInfo.className = 'hc-homewiki-trash-info';
        oInfo.textContent = t(sAppId, 'Only pages you own are shown here. Pages deleted by a collaborator are returned to the owner’s trash.');

        const oTools = document.createElement('div');
        oTools.className = 'hc-homewiki-trash-tools';
        const oRetentionLabel = document.createElement('label');
        oRetentionLabel.className = 'hc-homewiki-trash-retention';
        const oRetentionText = document.createElement('span');
        oRetentionText.textContent = t(sAppId, 'Automatically delete trash after');
        const oRetention = document.createElement('select');
        for (const [nDays, sLabel] of [
            [0, t(sAppId, 'Off')],
            [7, t(sAppId, '{days} days', { days: 7 })],
            [14, t(sAppId, '{days} days', { days: 14 })],
            [30, t(sAppId, '{days} days', { days: 30 })],
            [90, t(sAppId, '{days} days', { days: 90 })],
        ]) {
            const oOption = document.createElement('option');
            oOption.value = String(nDays);
            oOption.textContent = sLabel;
            oRetention.append(oOption);
        }
        oRetentionLabel.append(oRetentionText, oRetention);

        const oEmptyAll = document.createElement('button');
        oEmptyAll.type = 'button';
        oEmptyAll.className = 'danger';
        oEmptyAll.textContent = t(sAppId, 'Empty trash');
        oTools.append(oRetentionLabel, oEmptyAll);

        const oRetentionHint = document.createElement('small');
        oRetentionHint.className = 'hc-homewiki-trash-retention-hint';
        oRetentionHint.textContent = t(sAppId, 'Automatic cleanup is performed by the Nextcloud background job and permanently deletes only your own Home Wiki trash items.');

        const oList = document.createElement('div');
        oList.className = 'hc-homewiki-trash-list';
        oContent.append(oInfo, oTools, oRetentionHint, oList);

        const oDialog = oCore.dialogs.open({
            title: t(sAppId, 'Trash'),
            content: oContent,
            size: 'medium',
            actions: [{ label: t(sAppId, 'Close') }],
        });

        let nTrashCount = 0;
        try {
            const oSettings = await fnApi('/api/settings');
            oRetention.value = String(Number(oSettings.trashRetentionDays || 0));
        } catch (oError) {
            fnNotifyError(oError);
        }

        oRetention.addEventListener('change', async () => {
            oRetention.disabled = true;
            try {
                const oSaved = await fnApi('/api/settings', {
                    method: 'PUT',
                    body: JSON.stringify({ trashRetentionDays: Number(oRetention.value || 0) }),
                });
                oRetention.value = String(Number(oSaved.trashRetentionDays || 0));
                oCore.notifications.success(t(sAppId, 'Trash cleanup setting saved.'));
            } catch (oError) {
                fnNotifyError(oError);
            } finally {
                oRetention.disabled = false;
            }
        });

        /** CZ-FUNKCE: Znovu načte obsah Koše. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
        const fnReloadTrash = async () => {
            oList.replaceChildren();
            const oLoading = document.createElement('p');
            oLoading.textContent = t(sAppId, 'Loading…');
            oList.append(oLoading);
            try {
                const oData = await fnApi('/api/trash');
                const aTrash = Array.isArray(oData.pages) ? oData.pages : [];
                nTrashCount = aTrash.length;
                oEmptyAll.disabled = nTrashCount === 0;
                oList.replaceChildren();
                if (!aTrash.length) {
                    const oEmpty = document.createElement('p');
                    oEmpty.className = 'hc-homewiki-trash-empty';
                    oEmpty.textContent = t(sAppId, 'Trash is empty.');
                    oList.append(oEmpty);
                    return;
                }

                for (const oDeleted of aTrash) {
                    const oRow = document.createElement('div');
                    oRow.className = 'hc-homewiki-trash-row';
                    const oText = document.createElement('div');
                    oText.className = 'hc-homewiki-trash-text';
                    const oTitle = document.createElement('strong');
                    oTitle.textContent = String(oDeleted.title || t(sAppId, 'Deleted page'));
                    const oMeta = document.createElement('small');
                    const sDate = Number(oDeleted.deleted_at || 0) > 0
                        ? new Date(Number(oDeleted.deleted_at) * 1000).toLocaleString()
                        : '—';
                    oMeta.textContent = t(sAppId, 'Deleted by {user} on {date}', {
                        user: String(oDeleted.deleted_by || '—'), date: sDate,
                    });
                    oText.append(oTitle, oMeta);

                    const oActions = document.createElement('div');
                    oActions.className = 'hc-homewiki-trash-actions';
                    const oRestore = document.createElement('button');
                    oRestore.type = 'button';
                    oRestore.className = 'primary';
                    oRestore.textContent = t(sAppId, 'Restore');
                    oRestore.addEventListener('click', async () => {
                        oRestore.disabled = true;
                        try {
                            const oResult = await fnApi('/api/trash/' + encodeURIComponent(String(oDeleted.id)) + '/restore', { method: 'POST', body: '{}' });
                            await fnLoadPages();
                            oRow.remove();
                            oCore.notifications.success(t(sAppId, 'Page restored.'), {
                                dedupeKey: 'hc_homewiki:trash-restored:' + String(oDeleted.id), cooldownMs: 800,
                            });
                            await fnReloadTrash();
                            if (!oCurrentPage && oResult.page?.id) await fnOpenPage(String(oResult.page.id));
                        } catch (oError) {
                            fnNotifyError(oError);
                        } finally {
                            oRestore.disabled = false;
                        }
                    });

                    const oDelete = document.createElement('button');
                    oDelete.type = 'button';
                    oDelete.className = 'danger';
                    oDelete.textContent = t(sAppId, 'Delete permanently');
                    oDelete.addEventListener('click', async () => {
                        const bConfirmed = await oCore.dialogs.confirm({
                            title: t(sAppId, 'Delete permanently'),
                            message: t(sAppId, 'Permanently delete “{title}”? This cannot be undone.', { title: String(oDeleted.title || '') }),
                            confirmLabel: t(sAppId, 'Delete permanently'),
                            cancelLabel: t(sAppId, 'Cancel'),
                            danger: true,
                        });
                        if (!bConfirmed) return;
                        oDelete.disabled = true;
                        try {
                            await fnApi('/api/trash/' + encodeURIComponent(String(oDeleted.id)), { method: 'DELETE' });
                            await fnLoadPages();
                            oRow.remove();
                            oCore.notifications.success(t(sAppId, 'Page permanently deleted.'), {
                                dedupeKey: 'hc_homewiki:trash-deleted:' + String(oDeleted.id), cooldownMs: 800,
                            });
                            await fnReloadTrash();
                        } catch (oError) {
                            fnNotifyError(oError);
                        } finally {
                            oDelete.disabled = false;
                        }
                    });
                    oActions.append(oRestore, oDelete);
                    oRow.append(oText, oActions);
                    oList.append(oRow);
                }
            } catch (oError) {
                nTrashCount = 0;
                oEmptyAll.disabled = true;
                oList.replaceChildren();
                fnNotifyError(oError);
            }
        };

        oEmptyAll.addEventListener('click', async () => {
            if (nTrashCount <= 0) return;
            const bConfirmed = await oCore.dialogs.confirm({
                title: t(sAppId, 'Empty trash'),
                message: t(sAppId, 'Permanently delete all {count} items from your Home Wiki trash? This cannot be undone.', { count: nTrashCount }),
                confirmLabel: t(sAppId, 'Empty trash'),
                cancelLabel: t(sAppId, 'Cancel'),
                danger: true,
            });
            if (!bConfirmed) return;
            oEmptyAll.disabled = true;
            try {
                const oResult = await fnApi('/api/trash', { method: 'DELETE' });
                await fnLoadPages();
                await fnReloadTrash();
                oCore.notifications.success(t(sAppId, 'Trash emptied. Permanently deleted: {count}', {
                    count: Number(oResult.deleted || 0),
                }));
            } catch (oError) {
                fnNotifyError(oError);
                await fnReloadTrash();
            }
        });

        await fnReloadTrash();
        return oDialog;
    };

    /** CZ-FUNKCE: Spočítá počet potomků zadané stránky. Vstup: `sRootId`. Výstup: číslo. */
    const fnDescendantCount = (sRootId) => {
        const oChildren = new Map();
        for (const oPage of aPages) {
            const sParentId = String(oPage.parent_id || '');
            if (!oChildren.has(sParentId)) oChildren.set(sParentId, []);
            oChildren.get(sParentId).push(String(oPage.id));
        }
        const oSeen = new Set([String(sRootId)]);
        const aQueue = [...(oChildren.get(String(sRootId)) || [])];
        let nCount = 0;
        while (aQueue.length) {
            const sId = String(aQueue.shift() || '');
            if (!sId || oSeen.has(sId)) continue;
            oSeen.add(sId);
            nCount++;
            aQueue.push(...(oChildren.get(sId) || []));
        }
        return nCount;
    };

    /** CZ-FUNKCE: Nechá uživatele zvolit rozsah smazání stránky a podstromu. Vstup: `oPage`, `nDescendants`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnChooseDeleteScope = async (oPage, nDescendants) => {
        if (nDescendants < 1) {
            const bConfirmed = await oCore.dialogs.confirm({
                title: t(sAppId, 'Move to trash'),
                message: t(sAppId, 'Move page “{title}” to trash? It can be restored by the page owner.', { title: oPage.title }),
                confirmLabel: t(sAppId, 'Move to trash'),
                cancelLabel: t(sAppId, 'Cancel'),
                danger: true,
            });
            return bConfirmed ? 'single' : null;
        }

        const oContent = document.createElement('div');
        oContent.className = 'hc-homewiki-delete-scope';
        const oMessage = document.createElement('p');
        oMessage.textContent = t(sAppId, 'Page “{title}” has {count} subpages. What should be moved to trash?', {
            title: String(oPage.title || ''),
            count: nDescendants,
        });
        const oHint = document.createElement('p');
        oHint.className = 'hc-homewiki-delete-scope-hint';
        oHint.textContent = t(sAppId, 'Deleting only this page keeps its subpages and moves them one level up. Deleting the whole tree moves this page and all its subpages to trash.');
        oContent.append(oMessage, oHint);

        return new Promise((fnResolve) => {
            let bResolved = false;
            /** CZ-FUNKCE: Dokončí příslušnou operaci a uklidí její dočasný stav. Vstup: `sValue`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
            const fnFinish = (sValue) => {
                if (bResolved) return;
                bResolved = true;
                fnResolve(sValue);
            };
            const oDialog = oCore.dialogs.open({
                title: t(sAppId, 'Move tree to trash'),
                content: oContent,
                size: 'small',
                actions: [
                    { label: t(sAppId, 'Cancel'), onClick: () => fnFinish(null) },
                    {
                        label: t(sAppId, 'Only this page'),
                        close: false,
                        onClick: () => {
                            fnFinish('single');
                            oDialog.close('action');
                        },
                    },
                    {
                        label: t(sAppId, 'Whole tree'),
                        variant: 'primary',
                        close: false,
                        onClick: () => {
                            fnFinish('subtree');
                            oDialog.close('action');
                        },
                    },
                ],
                onClose: () => fnFinish(null),
            });
        });
    };

    /** CZ-FUNKCE: Smaže stránku ve zvoleném rozsahu. Vstup: žádný přímý parametr. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
    const fnDeletePage = async () => {
        if (!oCurrentPage) {
            return;
        }
        const nDescendants = fnDescendantCount(String(oCurrentPage.id));
        const sDeleteScope = await fnChooseDeleteScope(oCurrentPage, nDescendants);
        if (!sDeleteScope) return;

        try {
            const bWasOwner = !!oCurrentPage.permissions?.owner;
            await fnApi('/api/pages/' + encodeURIComponent(String(oCurrentPage.id)), {
                method: 'DELETE',
                body: JSON.stringify(oCore.concurrency.withExpectedRevision({
                    scope: sDeleteScope,
                }, Number(oCurrentPage.revision || 1))),
            });
            oCurrentPage = null;
            const oMobileCurrent = fnById('hc-homewiki-mobile-current');
            const oPage = fnById('hc-homewiki-page');
            const oEmpty = fnById('hc-homewiki-empty');
            if (oMobileCurrent) oMobileCurrent.textContent = t(sAppId, 'Home Wiki');
            if (oPage) oPage.hidden = true;
            if (oEmpty) oEmpty.hidden = false;
            await fnLoadPages();
            const sDeleteMessage = sDeleteScope === 'subtree'
                ? (bWasOwner ? 'Tree moved to trash.' : 'Tree moved to the owner’s trash.')
                : (bWasOwner ? 'Page moved to trash.' : 'Page moved to the owner’s trash.');
            oCore.notifications.success(t(sAppId, sDeleteMessage), {
                dedupeKey: 'hc_homewiki:deleted',
                cooldownMs: 800,
            });
        } catch (oError) {
            if (oCore.concurrency.isConflict(oError)) {
                const oChoice = await oCore.concurrency.resolveConflict({
                    title: t(sAppId, 'Edit conflict'),
                    message: t(sAppId, 'The page changed before it could be deleted.'),
                    reloadLabel: t(sAppId, 'Load newer version'),
                    keepEditingLabel: t(sAppId, 'Cancel'),
                });
                if (oChoice === 'reload' && oCurrentPage) { const sId = String(oCurrentPage.id); await fnOpenPage(sId, false, true); }
            } else {
                fnNotifyError(oError);
            }
        }
    };

    /** CZ-FUNKCE: Naváže události uživatelského rozhraní na obslužné funkce. Vstup: `sId`, `sEvent`, `fnHandler`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnBind = (sId, sEvent, fnHandler) => {
        fnById(sId)?.addEventListener(sEvent, fnHandler, { signal: oLifecycleSignal });
    };

    /** CZ-FUNKCE: Uvolní posluchače a dočasné prostředky aplikace. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnDestroy = () => {
        if (bDestroyed) {
            return;
        }
        bDestroyed = true;
        window.clearTimeout(nSaveTimer);
        if (nDirtyWatchTimer) window.clearInterval(nDirtyWatchTimer);
        nDirtyWatchTimer = 0;
        fnClearTreeHoverExpand();
        fnRemoveTreeDragGhost();
        fnStopRevisionWatch();
        oLifecycleAbort.abort();
        oShareDialog?.close('destroy');
        oPickerController?.destroy();
        oCoreEditor?.destroy();
        oCoreEditor = null;
        oAboutController?.destroy();
        oToolbarController?.destroy();
        oLayoutController?.destroy();
        oEditControlsObserver?.disconnect();
        oPickerController = null;
        oAboutController = null;
        oToolbarController = null;
        oLayoutController = null;
        oEditControlsObserver = null;
    };

    /** CZ-FUNKCE: Inicializuje datový a uživatelský stav Home Wiki. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnInit = () => {
        const oApp = fnById('hc-homewiki-app');
        if (!oApp) {
            throw new Error(t(sAppId, 'Home Wiki root is missing.'));
        }

        const oEditorHost = fnById('hc-homewiki-core-editor');
        if (oEditorHost) oCoreEditor = oCore.editor.create(oEditorHost, {
            value: '',
            showPreview: true,
            splitView: true,
            decoratePreview: fnDecorateWikiPreview,
            resolveImageUrl: fnMediaUrl,
            translate: (sLabel) => t(sAppId, sLabel),
            onError: (sMessage) => fnNotifyError(new Error(t(sAppId, sMessage))),
            onChange: (sValue) => {
                const oSource = fnById('hc-homewiki-source');
                if (oSource) oSource.value = sValue;
                if (oCurrentPage && !bLoadingPage) fnMarkDirty();
            },
            uploadImage: async (oFile) => {
                const sPageId = String(oCurrentPage?.id || '');
                if (!sPageId || !oCurrentPage.permissions.edit) throw new Error('Page editing is unavailable.');
                const oFormData = new FormData();
                oFormData.append('file', oFile);
                const oResult = await fnApi('/api/pages/' + encodeURIComponent(sPageId) + '/media', { method: 'POST', body: oFormData });
                if (String(oCurrentPage?.id || '') !== sPageId) throw new Error('The page changed during upload.');
                const sRelative = String(oResult.path || '').startsWith('media/')
                    ? '../media/' + String(oResult.path).slice(6).split('/').map(encodeURIComponent).join('/')
                    : String(oResult.path || '');
                return sRelative;
            },
        });
        fnStartDirtyWatch();

        const sCoreScrollAreaClass = oCore.layout.classes?.scrollArea || '';
        if (sCoreScrollAreaClass) {
            fnById('hc-homewiki-side-scroll')?.classList.add(sCoreScrollAreaClass);
            fnById('hc-homewiki-main')?.classList.add(sCoreScrollAreaClass);
        }

        oCore.about.register({
            id: sAppId,
            name: 'Home Wiki',
            version: sAppVersion,
            repository: 'https://github.com/hacesoft/Wiki',
            releaseNotes: 'https://github.com/hacesoft/Wiki/releases',
            documentation: 'https://github.com/hacesoft/Wiki#readme',
        });

        oLayoutController = oCore.layout.observe(oApp, {
            topOffset: 50,
            onResize: (oMetrics) => {
                if (oMetrics.mode === 'desktop' && oApp.classList.contains('hc-homewiki-sidebar-open')) {
                    fnSetSidebar(false);
                }
                if (oMetrics.mode === 'desktop') fnRestoreSidebarWidth();
            },
        });
        fnRestoreSidebarWidth();

        const oSidebarResizer = fnById('hc-homewiki-sidebar-resizer');
        oSidebarResizer?.addEventListener('pointerdown', (oEvent) => {
            if (oApp.dataset.coreLayout !== 'desktop') return;
            if (oEvent.pointerType === 'mouse' && oEvent.button !== 0) return;
            oEvent.preventDefault();
            nSidebarResizePointerId = Number(oEvent.pointerId);
            oApp.classList.add('hc-homewiki-sidebar-resizing');
            try { oSidebarResizer.setPointerCapture(oEvent.pointerId); } catch {}
            fnResizeSidebarFromClientX(oEvent.clientX, false);
        }, { signal: oLifecycleSignal });
        oSidebarResizer?.addEventListener('pointermove', (oEvent) => {
            if (nSidebarResizePointerId === null || Number(oEvent.pointerId) !== Number(nSidebarResizePointerId)) return;
            oEvent.preventDefault();
            fnResizeSidebarFromClientX(oEvent.clientX, false);
        }, { signal: oLifecycleSignal });
        /** CZ-FUNKCE: Dokončí změnu šířky postranního panelu. Vstup: `oEvent`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
        const fnFinishSidebarResize = (oEvent) => {
            if (nSidebarResizePointerId === null || Number(oEvent.pointerId) !== Number(nSidebarResizePointerId)) return;
            oEvent.preventDefault();
            fnResizeSidebarFromClientX(oEvent.clientX, true);
            try {
                if (oSidebarResizer.hasPointerCapture(nSidebarResizePointerId)) {
                    oSidebarResizer.releasePointerCapture(nSidebarResizePointerId);
                }
            } catch {}
            nSidebarResizePointerId = null;
            oApp.classList.remove('hc-homewiki-sidebar-resizing');
        };
        oSidebarResizer?.addEventListener('pointerup', fnFinishSidebarResize, { signal: oLifecycleSignal });
        oSidebarResizer?.addEventListener('pointercancel', fnFinishSidebarResize, { signal: oLifecycleSignal });
        oSidebarResizer?.addEventListener('keydown', (oEvent) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(oEvent.key) || oApp.dataset.coreLayout !== 'desktop') return;
            oEvent.preventDefault();
            const { nMin, nMax } = fnSidebarWidthLimits();
            const nCurrent = Number.parseInt(getComputedStyle(oApp).getPropertyValue('--hc-homewiki-sidebar-width'), 10) || 300;
            const nNext = oEvent.key === 'Home' ? nMin
                : oEvent.key === 'End' ? nMax
                    : nCurrent + (oEvent.key === 'ArrowLeft' ? -20 : 20);
            fnSetSidebarWidth(nNext, true);
        }, { signal: oLifecycleSignal });

        oToolbarController = oCore.toolbar.create(fnById('hc-homewiki-toolbar'), {
            ariaLabel: t(sAppId, 'Editor toolbar'),
            actions: fnToolbarActions(),
        });

        if (window.ResizeObserver) {
            oEditControlsObserver = new window.ResizeObserver(fnSyncStickyHeight);
            const oEditControls = fnById('hc-homewiki-edit-controls');
            const oPageHead = document.querySelector('.hc-homewiki-page-head');
            if (oEditControls) oEditControlsObserver.observe(oEditControls);
            if (oPageHead) oEditControlsObserver.observe(oPageHead);
        }
        fnSyncStickyHeight();

        const oTreeRootDrop = fnById('hc-homewiki-tree-root-drop');
        oTreeRootDrop?.addEventListener('dragover', (oEvent) => {
            const oMovedPage = aPages.find((oCandidate) => String(oCandidate.id) === String(sDraggedPageId));
            if (!bTreeDragEnabled || !oMovedPage?.permissions?.edit || !oEvent.dataTransfer) return;
            oEvent.preventDefault();
            oEvent.dataTransfer.dropEffect = 'move';
            fnClearTreeDropMarkers();
            oTreeRootDrop.classList.add('hc-homewiki-tree-root-drop-active');
        }, { signal: oLifecycleSignal });
        oTreeRootDrop?.addEventListener('dragleave', () => {
            oTreeRootDrop.classList.remove('hc-homewiki-tree-root-drop-active');
        }, { signal: oLifecycleSignal });
        oTreeRootDrop?.addEventListener('drop', (oEvent) => {
            const sMovedId = sDraggedPageId || oEvent.dataTransfer?.getData('text/plain') || '';
            const oMovedPage = aPages.find((oCandidate) => String(oCandidate.id) === String(sMovedId));
            if (!bTreeDragEnabled || !oMovedPage?.permissions?.edit) return;
            oEvent.preventDefault();
            fnClearTreeDropMarkers();
            void fnMovePageByDrag(String(oMovedPage.id), '');
        }, { signal: oLifecycleSignal });
        fnById('hc-homewiki-side-scroll')?.addEventListener('dragover', (oEvent) => {
            if (sDraggedPageId) fnAutoScrollTreeDrag(oEvent.clientY);
        }, { signal: oLifecycleSignal });

        fnBind('hc-homewiki-sidebar-open', 'click', (oEvent) => fnSetSidebar(true, oEvent.currentTarget));
        fnBind('hc-homewiki-sidebar-close', 'click', () => fnSetSidebar(false));
        fnBind('hc-homewiki-sidebar-backdrop', 'click', () => fnSetSidebar(false));
        fnBind('hc-homewiki-mobile-new', 'click', fnCreatePage);
        fnBind('hc-homewiki-new', 'click', fnCreatePage);
        fnBind('hc-homewiki-app-actions', 'click', (oEvent) => fnOpenAppMenu(oEvent.currentTarget));
        fnBind('hc-homewiki-empty-new', 'click', fnCreatePage);
        fnBind('hc-homewiki-empty-recover', 'click', () => void fnRecoverPages());
        document.querySelectorAll('[data-section-toggle]').forEach((oToggle) => {
            oToggle.addEventListener('click', () => {
                const sKey = String(oToggle.getAttribute('data-section-toggle') || '');
                if (!sKey || !oSidebarSections[sKey]) return;
                void fnSetSidebarSectionCollapsed(sKey, !oSidebarSections[sKey].collapsed);
            }, { signal: oLifecycleSignal });
        });
        fnBind('hc-homewiki-search', 'input', () => {
            window.clearTimeout(nSearchTimer);
            const sQuery = String(fnById('hc-homewiki-search')?.value || '').trim();
            if (!sQuery) {
                aSearchResults = null;
                fnRenderNav();
                return;
            }
            nSearchTimer = window.setTimeout(async () => {
                try {
                    const oResult = await fnApi('/api/search?q=' + encodeURIComponent(sQuery));
                    if (String(fnById('hc-homewiki-search')?.value || '').trim() !== sQuery) return;
                    aSearchResults = Array.isArray(oResult.pages) ? oResult.pages : [];
                    fnRenderNav();
                } catch (oError) { fnNotifyError(oError); }
            }, 250);
        });
        fnBind('hc-homewiki-pin', 'click', () => void fnTogglePin());
        fnBind('hc-homewiki-edit-page', 'click', () => {
            if (oCurrentPage?.permissions.edit) { fnSetMode('edit'); oCoreEditor?.focus(); }
        });
        fnBind('hc-homewiki-finish-edit', 'click', () => void fnFinishEditing());
        fnBind('hc-homewiki-side-preview', 'click', () => {
            bSidePreview = !bSidePreview;
            fnSetMode('edit');
        });
        fnBind('hc-homewiki-page-actions', 'click', (oEvent) => {
            if (oCurrentPage) fnOpenPageMenu(oCurrentPage, oEvent.currentTarget);
        });
        fnBind('hc-homewiki-export', 'click', () => {
            if (oCurrentPage) {
                window.location.href = OC.generateUrl('/apps/' + sAppId + '/api/pages/' + encodeURIComponent(String(oCurrentPage.id)) + '/export');
            }
        });
        fnBind('hc-homewiki-delete', 'click', () => void fnDeletePage());
        fnBind('hc-homewiki-share', 'click', fnOpenShare);
        fnBind('hc-homewiki-tree-drag-toggle', 'click', () => void fnSetTreeDragEnabled(!bTreeDragEnabled));
        fnBind('hc-homewiki-file', 'change', () => {
            const oInput = fnById('hc-homewiki-file');
            const oFile = oInput?.files?.[0];
            if (oFile) {
                void fnUploadImage(oFile);
            }
            if (oInput) {
                oInput.value = '';
            }
        });

        document.querySelectorAll('.hc-homewiki-tab').forEach((oTab) => {
            oTab.addEventListener('click', () => fnSetMode(String((/** @type {any} */ (oTab)).dataset.mode || 'edit')), { signal: oLifecycleSignal });
        });

        /** CZ-FUNKCE: Zpracuje kliknutí na interní Wiki odkaz. Vstup: `oEvent`. Výstup: Promise; po dokončení obsahuje výsledek operace nebo potvrzení jejího provedení. */
        const fnFollowWikiLink = async (oEvent) => {
            const oTarget = oEvent.target instanceof Element ? oEvent.target.closest('.hc-homewiki-internal-link') : null;
            if (!oTarget) return;
            oEvent.preventDefault();
            let sPageId = oTarget.getAttribute('data-page') || '';
            if (!sPageId) {
                const sAlias = oTarget.getAttribute('data-alias') || '';
                try {
                    const oResolved = await fnApi('/api/resolve?alias=' + encodeURIComponent(sAlias)
                        + '&ownerUid=' + encodeURIComponent(String(oCurrentPage?.owner_uid || '')));
                    sPageId = String(oResolved.page?.id || '');
                    if (sPageId) {
                        oTarget.setAttribute('data-page', sPageId);
                        oTarget.classList.remove('hc-homewiki-unresolved', 'hc-homewiki-broken');
                    }
                } catch (oError) {
                    oTarget.classList.remove('hc-homewiki-unresolved');
                    oTarget.classList.add('hc-homewiki-broken');
                    fnNotifyError(oError);
                    return;
                }
            }
            if (sPageId) void fnOpenPage(sPageId);
        };
        fnById('hc-homewiki-preview')?.addEventListener('click', fnFollowWikiLink, { signal: oLifecycleSignal });
        fnById('hc-homewiki-live-preview')?.addEventListener('click', fnFollowWikiLink, { signal: oLifecycleSignal });
        fnById('hc-homewiki-preview')?.addEventListener('dblclick', () => {
            if (oCurrentPage?.permissions.edit) { fnSetMode('edit'); oCoreEditor?.focus(); }
        }, { signal: oLifecycleSignal });

        document.addEventListener('keydown', (oEvent) => {
            if (oEvent.key === 'Escape' && !fnById('hc-homewiki-page-menu')?.hidden) {
                oEvent.preventDefault();
                const oFocus = oMenuReturnFocus;
                fnClosePageMenu();
                oFocus?.focus();
                return;
            }
            if (oEvent.key === 'Escape' && !fnById('hc-homewiki-app-menu')?.hidden) {
                oEvent.preventDefault();
                const oFocus = oAppMenuReturnFocus;
                fnCloseAppMenu();
                oFocus?.focus();
                return;
            }
            if (oEvent.key === 'Escape' && oApp.classList.contains('hc-homewiki-sidebar-open')) {
                oEvent.preventDefault();
                fnSetSidebar(false);
            }
        }, { signal: oLifecycleSignal });
        document.addEventListener('click', (oEvent) => {
            const oPageMenu = fnById('hc-homewiki-page-menu');
            if (!oPageMenu?.hidden && !oPageMenu.contains(oEvent.target) && !oEvent.target.closest?.('.hc-homewiki-nav-actions, #hc-homewiki-page-actions')) fnClosePageMenu();
            const oAppMenu = fnById('hc-homewiki-app-menu');
            if (!oAppMenu?.hidden && !oAppMenu.contains(oEvent.target) && !oEvent.target.closest?.('#hc-homewiki-app-actions')) fnCloseAppMenu();
        }, { signal: oLifecycleSignal });
        /** CZ-FUNKCE: Zavře všechny otevřené plovoucí nabídky aplikace. Vstup: žádný přímý parametr. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
        const fnCloseFloatingMenus = () => { fnClosePageMenu(); fnCloseAppMenu(); };
        fnById('hc-homewiki-main')?.addEventListener('scroll', fnCloseFloatingMenus, { signal: oLifecycleSignal });
        fnById('hc-homewiki-sidebar')?.addEventListener('scroll', fnCloseFloatingMenus, { signal: oLifecycleSignal });

        window.addEventListener('beforeunload', (oEvent) => {
            if (bDirty) {
                oEvent.preventDefault();
                oEvent.returnValue = '';
            }
        }, { signal: oLifecycleSignal });
        window.addEventListener('pagehide', fnDestroy, { once: true, signal: oLifecycleSignal });

        fnLoadCollapsedTreeState();
        fnUpdateTreeDragToggle();
        void fnLoadPages().catch(fnNotifyError);
        oLogger.info('Home Wiki started.', { sAppVersion, sCoreVersion: oCore.version });
    };

    try {
        fnInit();
        window.dispatchEvent(new CustomEvent('hc_homewiki:ready'));
    } catch (oError) {
        window.dispatchEvent(new CustomEvent('hc_homewiki:boot-error', {
            detail: { message: oError instanceof Error ? oError.message : String(oError) },
        }));
        throw oError;
    }
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fStartHomeWiki, { once: true });
else fStartHomeWiki();
