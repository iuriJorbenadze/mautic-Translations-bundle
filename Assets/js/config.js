document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('test-deepl-api');
    if (!btn) return;

    btn.addEventListener('click', function() {
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
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: '' // no body required
        })
            .then(r => r.json())
            .then(data => {
                result.innerText = data.message;
                btn.disabled = false;
            })
            .catch(e => {
                console.error('LeuchtfeuerTranslations test-api error:', e);
                result.innerText = 'Request failed';
                btn.disabled = false;
            });
    });
});
