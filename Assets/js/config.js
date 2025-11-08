/* global window, document, mauticAjaxCsrf */
;(function (win) {
    'use strict';

    // Namespace for shared config between bundle scripts
    const NS = (win.LFTranslations = win.LFTranslations || {});

    // ---- I18N defaults (non-destructive: keep pre-seeded keys if any) ----
    NS.I18N = Object.assign(
        {
            choose_target_language: 'Choose target language',
            cancel: 'Cancel',
            translate: 'Translate',
            missing_csrf: 'No CSRF token found. Please refresh the page and try again.',
            missing_email_id: 'Could not determine Email ID.',
            done: 'Done.',
            next_step:
                'Next step:\nOpen the Email Builder, review the translated content, and click Save.',
            please_choose_language: 'Please choose a language.',
            unexpected_error: 'Unexpected error, check console.',
        },
        NS.I18N || {}
    );

    // ---- DeepL target languages (only set if not already provided) ----
    NS.DEEPL_LANGS =
        NS.DEEPL_LANGS ||
        [
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
            { code: 'ZH-HANT', name: 'Chinese (Traditional)' },
        ];

    // ---- Preserve your existing "Test Deepl API" button behavior ----
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('test-deepl-api');
        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            var result = document.getElementById('deepl-api-test-result');
            result.innerText = 'Testing...';

            // Get CSRF token rendered by Mautic
            var csrf = (typeof mauticAjaxCsrf !== 'undefined' && mauticAjaxCsrf) || '';
            if (!csrf) {
                result.innerText = 'Missing CSRF token';
                btn.disabled = false;
                return;
            }

            fetch('/s/plugin/ai-translate/test-api', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: '', // no body required
            })
                .then((r) => r.json())
                .then((data) => {
                    result.innerText = data.message;
                    btn.disabled = false;
                })
                .catch((e) => {
                    console.error('LeuchtfeuerTranslations test-api error:', e);
                    result.innerText = 'Request failed';
                    btn.disabled = false;
                });
        });
    });
})(window);
