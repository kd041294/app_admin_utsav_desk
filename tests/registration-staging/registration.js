(() => {
    // Normalize pasted formatting before maxlength can truncate the actual value.
    const fields = [
        ['access_code', (value) => value.replace(/\s/g, '').toUpperCase(), /^[A-Z0-9]{16}$/, 'Enter the complete 16-character access code (letters and numbers).'],
        ['contact_number', (value) => value.replace(/[\s().-]/g, ''), /^\+?[0-9]{7,15}$/, 'Enter 7–15 digits, optionally starting with +, for example +919876543210.'],
        ['whatsapp_number', (value) => value.replace(/[\s().-]/g, ''), /^\+?[0-9]{7,15}$/, 'Enter 7–15 digits, optionally starting with +, for example +919876543210.'],
    ];
    fields.forEach(([id, normalize, format, message]) => {
        const field = document.getElementById(id);
        if (!field) return;
        function validate() {
            field.value = normalize(field.value);
            field.setCustomValidity(field.value && !format.test(field.value) ? message : '');
        }
        field.addEventListener('input', validate);
        field.addEventListener('change', validate);
        field.addEventListener('invalid', validate);
        field.addEventListener('paste', (event) => {
            if (!event.clipboardData) return;
            event.preventDefault();
            const start = field.selectionStart ?? 0;
            const end = field.selectionEnd ?? field.value.length;
            field.value = field.value.slice(0, start) + normalize(event.clipboardData.getData('text')) + field.value.slice(end);
            validate();
        });
    });
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const icon = button.querySelector('i');
        const label = button.dataset.visibilityLabel || 'password';

        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-label', `${visible ? 'Hide' : 'Show'} ${label}`);
            button.title = `${visible ? 'Hide' : 'Show'} ${label}`;
            icon.classList.toggle('bi-eye', !visible);
            icon.classList.toggle('bi-eye-slash', visible);
        });
    });

    const form = document.querySelector('[data-registration-form]');
    if (!form) return;

    const sections = [...form.querySelectorAll('[data-registration-step]')];
    const progressItems = [...document.querySelectorAll('[data-progress-step]')];
    const stepInput = form.querySelector('[name="form_step"]');

    function showStep(name, focus = false) {
        sections.forEach((section) => {
            const active = section.dataset.registrationStep === name;
            section.hidden = !active;
            section.querySelectorAll('[data-step-required]').forEach((field) => {
                field.required = active;
            });
        });
        progressItems.forEach((item) => {
            const active = item.dataset.progressStep === name;
            item.classList.toggle('active', active);
            if (active) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });
        stepInput.value = name;
        if (focus) sections.find((section) => section.dataset.registrationStep === name)
            ?.querySelector('input:not([type="hidden"]), select, button')?.focus();
    }

    form.querySelector('[data-next-step]')?.addEventListener('click', () => {
        const profile = sections.find((section) => section.dataset.registrationStep === 'profile');
        const invalid = [...profile.querySelectorAll('input, select')].find((field) => !field.checkValidity());
        if (invalid) {
            invalid.reportValidity();
            return;
        }
        showStep('security', true);
    });

    form.querySelector('[data-previous-step]')?.addEventListener('click', () => showStep('profile', true));

    form.addEventListener('submit', (event) => {
        const password = form.querySelector('#password');
        const confirmation = form.querySelector('#confirm_password');
        confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Passwords do not match.');
        if (!form.reportValidity()) event.preventDefault();
    });

    form.querySelector('#confirm_password')?.addEventListener('input', (event) => event.currentTarget.setCustomValidity(''));
    showStep(form.dataset.startStep === 'security' ? 'security' : 'profile');
})();
