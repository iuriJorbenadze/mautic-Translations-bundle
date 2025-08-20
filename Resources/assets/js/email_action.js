// plugins/AiTranslateBundle/Resources/assets/js/email_action.js

document.addEventListener('DOMContentLoaded', () => {
    const translateButton = document.querySelector('.btn-clone-translate');

    if (!translateButton) {
        return;
    }

    translateButton.addEventListener('click', (event) => {
        event.preventDefault();

        // 1. A simple prompt to get the target language
        const targetLang = prompt('Please enter the target language code (e.g., DE, FR, ES):', 'DE');
        if (!targetLang || targetLang.trim() === '') {
            return; // User cancelled or entered nothing
        }

        // 2. Provide user feedback
        Mautic.showLoadingBar();
        const originalButtonHtml = translateButton.innerHTML;
        translateButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Translating...';
        translateButton.disabled = true;

        const url = translateButton.getAttribute('href');
        const formData = new FormData();
        formData.append('targetLang', targetLang.trim().toUpperCase());

        // 3. Make the AJAX call to our new controller endpoint
        fetch(url, {
            method: 'POST',
            body: new URLSearchParams(formData) // Standard way to send form data
        })
            .then(response => response.json())
            .then(data => {
                alert(data.message); // Show the success/error message from the controller

                // In the future, on success, you might redirect to the new email:
                // if (data.success && data.newEmailUrl) {
                //     window.location.href = data.newEmailUrl;
                // }
            })
            .catch(error => {
                console.error('Translation Error:', error);
                alert('An unexpected error occurred. Check the console for details.');
            })
            .finally(() => {
                // 4. Restore the button
                Mautic.stopLoadingBar();
                translateButton.innerHTML = originalButtonHtml;
                translateButton.disabled = false;
            });
    });
});