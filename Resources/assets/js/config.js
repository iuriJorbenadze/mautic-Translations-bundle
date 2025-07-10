document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('test-deepl-api');
    if (!btn) return;
    btn.addEventListener('click', function() {
        btn.disabled = true;
        var result = document.getElementById('deepl-api-test-result');
        result.innerText = 'Testing...';

        fetch('/s/plugin/ai-translate/test-api', { method: 'POST', credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                result.innerText = data.message;
                btn.disabled = false;
            })
            .catch(e => {
                result.innerText = 'Request failed';
                btn.disabled = false;
            });
    });
});
