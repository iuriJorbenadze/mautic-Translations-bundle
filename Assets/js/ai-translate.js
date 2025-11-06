/* global mauticAjaxCsrf */
;(function (win, doc) {
    const NS = (win.LFTranslations = win.LFTranslations || {});

    function openLanguagePicker(defaultCode) {
        const DEEPL_LANGS = [
            { code: 'AR', name: 'Arabic' },
            { code: 'BG', name: 'Bulgarian' },
            { code: 'CS', name: 'Czech' },
            { code: 'DA', name: 'Danish' },
            { code: 'DE', name: 'German' },
            { code: 'EL', name: 'Greek' },
            { code: 'EN', name: 'English' },
            { code: 'EN-GB', name: 'English (UK)' },
            { code: 'EN-US', name: 'English (US)' },
            { code: 'ES', name: 'Spanish' },
            { code: 'ES-419', name: 'Spanish (Latin American)' },
            { code: 'ET', name: 'Estonian' },
            { code: 'FI', name: 'Finnish' },
            { code: 'FR', name: 'French' },
            { code: 'HE', name: 'Hebrew' },
            { code: 'HU', name: 'Hungarian' },
            { code: 'ID', name: 'Indonesian' },
            { code: 'IT', name: 'Italian' },
            { code: 'JA', name: 'Japanese' },
            { code: 'KO', name: 'Korean' },
            { code: 'LT', name: 'Lithuanian' },
            { code: 'LV', name: 'Latvian' },
            { code: 'NB', name: 'Norwegian (Bokmål)' },
            { code: 'NL', name: 'Dutch' },
            { code: 'PL', name: 'Polish' },
            { code: 'PT', name: 'Portuguese' },
            { code: 'PT-BR', name: 'Portuguese (Brazil)' },
            { code: 'PT-PT', name: 'Portuguese (Portugal)' },
            { code: 'RO', name: 'Romanian' },
            { code: 'RU', name: 'Russian' },
            { code: 'SK', name: 'Slovak' },
            { code: 'SL', name: 'Slovenian' },
            { code: 'SV', name: 'Swedish' },
            { code: 'TH', name: 'Thai' },
            { code: 'TR', name: 'Turkish' },
            { code: 'UK', name: 'Ukrainian' },
            { code: 'VI', name: 'Vietnamese' },
            { code: 'ZH', name: 'Chinese' },
            { code: 'ZH-HANS', name: 'Chinese (Simplified)' },
            { code: 'ZH-HANT', name: 'Chinese (Traditional)' }
        ];

        return new Promise(function (resolve) {
            const overlay = doc.createElement('div');
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(0,0,0,0.4)';
            overlay.style.zIndex = '9999';
            overlay.addEventListener('click', (ev) => {
                if (ev.target === overlay) cleanup(null);
            });

            const modal = doc.createElement('div');
            modal.style.position = 'absolute';
            modal.style.top = '50%';
            modal.style.left = '50%';
            modal.style.transform = 'translate(-50%, -50%)';
            modal.style.background = '#fff';
            modal.style.padding = '16px';
            modal.style.borderRadius = '8px';
            modal.style.boxShadow = '0 10px 30px rgba(0,0,0,0.2)';
            modal.style.width = '420px';
            modal.style.maxWidth = '90%';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            overlay.appendChild(modal);

            const title = doc.createElement('h3');
            title.textContent = 'Choose target language';
            title.style.marginTop = '0';
            modal.appendChild(title);

            const select = doc.createElement('select');
            select.style.width = '100%';
            select.style.margin = '8px 0';

            const def = (defaultCode || 'DE').toUpperCase();
            DEEPL_LANGS.forEach((l) => {
                const opt = doc.createElement('option');
                opt.value = l.code;
                opt.textContent = `${l.name} (${l.code})`;
                if (def === l.code.toUpperCase()) opt.selected = true;
                select.appendChild(opt);
            });
            modal.appendChild(select);

            const actions = doc.createElement('div');
            actions.style.display = 'flex';
            actions.style.justifyContent = 'flex-end';
            actions.style.gap = '8px';
            actions.style.marginTop = '12px';
            const cancelBtn = doc.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.textContent = 'Cancel';
            const okBtn = doc.createElement('button');
            okBtn.type = 'button';
            okBtn.textContent = 'Translate';
            okBtn.className = 'btn btn-primary';
            actions.appendChild(cancelBtn);
            actions.appendChild(okBtn);
            modal.appendChild(actions);

            function choose() {
                const code = (select.value || '').trim().toUpperCase();
                if (!code) {
                    alert('Please choose a language.');
                    return;
                }
                cleanup(code);
            }

            function cleanup(result) {
                doc.removeEventListener('keydown', onKey);
                overlay.remove();
                resolve(result);
            }

            function onKey(ev) {
                if (ev.key === 'Escape') cleanup(null);
                if (ev.key === 'Enter') choose();
            }

            cancelBtn.addEventListener('click', () => cleanup(null));
            okBtn.addEventListener('click', choose);
            doc.addEventListener('keydown', onKey);

            doc.body.appendChild(overlay);
            select.focus();
        });
    }

    NS.openDialog = function (ev) {
        if (ev) {
            ev.preventDefault();
            ev.stopPropagation();
        }

        // Extract Email ID from /s/emails/view/{id}
        const m = (win.location.pathname || '').match(/\/s\/emails\/view\/(\d+)/);
        const id = m ? m[1] : null;
        if (!id) {
            alert('Could not determine Email ID.');
            return false;
        }

        return openLanguagePicker('DE').then(function (targetLang) {
            if (!targetLang) return false;

            try {
                if (win.Mautic && Mautic.showLoadingBar) Mautic.showLoadingBar();
            } catch (_) {}

            const form = new URLSearchParams();
            form.append('targetLang', targetLang.toUpperCase());

            const csrf = typeof mauticAjaxCsrf !== 'undefined' && mauticAjaxCsrf ? mauticAjaxCsrf : '';
            if (!csrf) {
                alert('No CSRF token found. Please refresh the page and try again.');
                return false;
            }

            return fetch('/s/plugin/ai-translate/email/' + id + '/translate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: form.toString()
            })
                .then((r) => r.json())
                .then((d) => {
                    const baseMsg = d && d.message ? d.message : 'Done.';
                    const warn = '\n\nNext step:\nOpen the Email Builder, review the translated content, and click Save.';
                    alert(baseMsg + warn);

                    if (d && d.clone && d.clone.urls && d.clone.urls.edit) {
                        win.location.href = d.clone.urls.edit;
                    }
                })
                .catch((err) => {
                    console.error('LeuchtfeuerTranslations error:', err);
                    alert('Unexpected error, check console.');
                })
                .finally(() => {
                    try {
                        if (win.Mautic && Mautic.stopLoadingBar) Mautic.stopLoadingBar();
                    } catch (_) {}
                });
        });
    };

    // Attach click handler once (delegated) — avoids brittle inline JS
    doc.addEventListener('click', function (ev) {
        const el = ev.target.closest('[data-lf-translate="1"], #ai-translate-dropdown');
        if (!el) return;
        NS.openDialog(ev);
    });
})(window, document);
