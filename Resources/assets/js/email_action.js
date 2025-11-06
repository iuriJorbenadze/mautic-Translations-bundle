// plugins/LeuchtfeuerTranslationsBundle/Resources/assets/js/email_action.js

document.addEventListener('DOMContentLoaded', () => {
    const translateButton = document.querySelector('.btn-clone-translate');
    if (!translateButton) return;

    // All DeepL target languages (API codes)
    // Note: 'EN' and 'PT' exist for backward compatibility; prefer EN-GB/EN-US and PT-BR/PT-PT.
    // Some marked languages are next-gen text translation only (see docs).
    const DEEPL_TARGET_LANGUAGES = [
        { code: 'AR',      name: 'Arabic' },
        { code: 'BG',      name: 'Bulgarian' },
        { code: 'CS',      name: 'Czech' },
        { code: 'DA',      name: 'Danish' },
        { code: 'DE',      name: 'German' },
        { code: 'EL',      name: 'Greek' },

        // English
        { code: 'EN',      name: 'English (unspecified)' }, // prefer EN-GB or EN-US
        { code: 'EN-GB',   name: 'English (British)' },
        { code: 'EN-US',   name: 'English (American)' },

        // Spanish
        { code: 'ES',      name: 'Spanish' },
        { code: 'ES-419',  name: 'Spanish (Latin American)' }, // next-gen text translation only

        { code: 'ET',      name: 'Estonian' },
        { code: 'FI',      name: 'Finnish' },
        { code: 'FR',      name: 'French' },

        { code: 'HE',      name: 'Hebrew' }, // next-gen text translation only

        { code: 'HU',      name: 'Hungarian' },
        { code: 'ID',      name: 'Indonesian' },
        { code: 'IT',      name: 'Italian' },
        { code: 'JA',      name: 'Japanese' },
        { code: 'KO',      name: 'Korean' },
        { code: 'LT',      name: 'Lithuanian' },
        { code: 'LV',      name: 'Latvian' },
        { code: 'NB',      name: 'Norwegian Bokmål' },
        { code: 'NL',      name: 'Dutch' },
        { code: 'PL',      name: 'Polish' },

        // Portuguese
        { code: 'PT',      name: 'Portuguese (unspecified)' }, // prefer PT-BR or PT-PT
        { code: 'PT-BR',   name: 'Portuguese (Brazilian)' },
        { code: 'PT-PT',   name: 'Portuguese (European)' },

        { code: 'RO',      name: 'Romanian' },
        { code: 'RU',      name: 'Russian' },
        { code: 'SK',      name: 'Slovak' },
        { code: 'SL',      name: 'Slovenian' },
        { code: 'SV',      name: 'Swedish' },

        { code: 'TH',      name: 'Thai' }, // next-gen text translation only

        { code: 'TR',      name: 'Turkish' },
        { code: 'UK',      name: 'Ukrainian' },

        { code: 'VI',      name: 'Vietnamese' }, // next-gen text translation only

        // Chinese
        { code: 'ZH',       name: 'Chinese (unspecified)' }, // prefer ZH-HANS or ZH-HANT
        { code: 'ZH-HANS',  name: 'Chinese (Simplified)' },
        { code: 'ZH-HANT',  name: 'Chinese (Traditional)' },
    ];

    function openLanguagePicker(defaultCode = 'DE') {
        return new Promise((resolve) => {
            // Overlay
            const overlay = document.createElement('div');
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(0,0,0,0.4)';
            overlay.style.zIndex = '9999';
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) cleanup(null);
            });

            // Modal
            const modal = document.createElement('div');
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

            const title = document.createElement('h3');
            title.textContent = 'Choose target language';
            title.style.marginTop = '0';
            modal.appendChild(title);

            const select = document.createElement('select');
            select.style.width = '100%';
            select.style.margin = '8px 0';
            DEEPL_TARGET_LANGUAGES.forEach(l => {
                const opt = document.createElement('option');
                opt.value = l.code;
                opt.textContent = `${l.name} (${l.code})`;
                if (l.code.toUpperCase() === defaultCode.toUpperCase()) opt.selected = true;
                select.appendChild(opt);
            });
            modal.appendChild(select);

            const actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.justifyContent = 'flex-end';
            actions.style.gap = '8px';
            actions.style.marginTop = '12px';
            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.textContent = 'Cancel';
            const okBtn = document.createElement('button');
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
                document.removeEventListener('keydown', onKey);
                overlay.remove();
                resolve(result);
            }

            function onKey(e) {
                if (e.key === 'Escape') cleanup(null);
                if (e.key === 'Enter') choose();
            }

            cancelBtn.addEventListener('click', () => cleanup(null));
            okBtn.addEventListener('click', choose);
            document.addEventListener('keydown', onKey);

            document.body.appendChild(overlay);
            select.focus();
        });
    }

    translateButton.addEventListener('click', async (event) => {
        event.preventDefault();

        // 1) Simple dropdown (prefilled with DE)
        const targetLang = await openLanguagePicker('DE');
        if (!targetLang) return;

        // 2) Loading feedback
        try { if (window.Mautic && Mautic.showLoadingBar) Mautic.showLoadingBar(); } catch (_) {}
        const originalButtonHtml = translateButton.innerHTML;
        translateButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Translating...';
        translateButton.disabled = true;

        const url = translateButton.getAttribute('href');

        // 3) CSRF token from Mautic global
        const csrf = (typeof mauticAjaxCsrf !== 'undefined' && mauticAjaxCsrf) || '';
        if (!csrf) {
            alert('No CSRF token found. Please refresh the page and try again.');
            try { if (window.Mautic && Mautic.stopLoadingBar) Mautic.stopLoadingBar(); } catch (_) {}
            translateButton.innerHTML = originalButtonHtml;
            translateButton.disabled = false;
            return;
        }

        // 4) Build form body
        const form = new URLSearchParams();
        form.append('targetLang', targetLang);

        // 5) Make the secure AJAX call
        fetch(url, {
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
            .then(response => response.json())
            .then(data => {
                alert(data.message || 'Done.');
            })
            .catch(error => {
                console.error('Translation Error:', error);
                alert('An unexpected error occurred. Check the console for details.');
            })
            .finally(() => {
                try { if (window.Mautic && Mautic.stopLoadingBar) Mautic.stopLoadingBar(); } catch (_) {}
                translateButton.innerHTML = originalButtonHtml;
                translateButton.disabled = false;
            });
    });
});
