document.addEventListener('DOMContentLoaded', () => {
    // --- Handle saving all settings ---
    const settingsInputs = document.querySelectorAll('#pdf_tagger_settings .setting');
    settingsInputs.forEach(input => {
        input.addEventListener('change', (event) => {
            const key = event.target.dataset.key;
            const value = event.target.type === 'checkbox' ? (event.target.checked ? '1' : '0') : event.target.value;
            OC.AppConfig.setValue('pdf_tagger', key, value);
        });
    });

    // --- Logic for the processing mode hint ---
    const parserCheckbox = document.getElementById('pdf_tagger_use_pdf_parser');
    const parserHint = document.getElementById('parser-hint');

    const updateHint = () => {
        if (parserCheckbox.checked) {
            parserHint.innerHTML = '<b>Recommended.</b> Extracts text from PDFs. Works with any standard text model (e.g., `llama3`, `mistral`).';
        } else {
            parserHint.innerHTML = 'Sends the entire PDF file to Ollama. You <b>must</b> use a multi-modal model (e.g., `llava`, `moondream`).';
        }
    };

    // Initial hint on page load
    updateHint();
    // Update hint when checkbox is toggled
    parserCheckbox.addEventListener('change', updateHint);


    // --- Handle the "Start" button ---
    const startBtn = document.getElementById('start-categorization-btn');
    const statusSpan = document.getElementById('categorization-status');

    if (startBtn) {
        startBtn.addEventListener('click', () => {
            statusSpan.textContent = 'Scheduling job...';
            statusSpan.style.color = '#333';
            
            const url = OC.generateUrl('/apps/pdf_tagger/start_categorization');

            fetch(url, {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (data.status === 'success') {
                    statusSpan.textContent = 'Success! Job has been scheduled to run in the background.';
                    statusSpan.style.color = 'green';
                } else {
                    statusSpan.textContent = `Error: ${data.message}`;
                    statusSpan.style.color = 'red';
                }
            })
            .catch(error => {
                console.error('Error starting categorization:', error);
                statusSpan.textContent = 'Failed to schedule job. See browser console for details.';
                statusSpan.style.color = 'red';
            });
        });
    }
});
