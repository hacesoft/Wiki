(() => {
    'use strict';

    const sAppId = 'hc_homewiki';

    /** CZ-FUNKCE: Ošetří text určený pro bezpečné zobrazení v HTML. Vstup: `sValue`. Výstup: výsledek operace nebo změna aplikačního/UI stavu. */
    const fnEscape = (sValue) => String(sValue ?? '').replace(/[&<>"']/g, (sChar) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[sChar]);

    OCA.Dashboard.register(sAppId, async (oElement) => {
        oElement.classList.add('hc-homewiki-widget-root');
        oElement.innerHTML = '<div class="hc-homewiki-widget">' + fnEscape(t(sAppId, 'Loading…')) + '</div>';

        try {
            const oResponse = await fetch(OC.generateUrl('/apps/' + sAppId + '/api/dashboard'), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    requesttoken: OC.requestToken,
                },
            });
            if (!oResponse.ok) {
                throw new Error('HTTP ' + oResponse.status);
            }

            const oData = await oResponse.json();
            const sBaseUrl = OC.generateUrl('/apps/' + sAppId + '/');
            const aPages = Array.isArray(oData.pages) ? oData.pages : [];
            const sRows = aPages.map((oPage) => (
                '<a class="hc-homewiki-widget-row" href="' + sBaseUrl + '#' + encodeURIComponent(String(oPage.id)) + '">' +
                    '<span aria-hidden="true">📌</span>' +
                    '<span>' + fnEscape(oPage.title) + '</span>' +
                '</a>'
            )).join('');

            oElement.innerHTML = '<div class="hc-homewiki-widget">' +
                (sRows || '<p>' + fnEscape(t(sAppId, 'No pinned pages yet.')) + '</p>') +
                '<a class="button primary hc-homewiki-widget-open" href="' + sBaseUrl + '">' + fnEscape(t(sAppId, 'Open Wiki')) + '</a>' +
                '</div>';
        } catch {
            oElement.innerHTML = '<div class="hc-homewiki-widget">' + fnEscape(t(sAppId, 'Home Wiki could not be loaded.')) + '</div>';
        }
    });
})();
