(() => {
    'use strict';
    const form = document.getElementById('settingsForm');
    const sections = [...document.querySelectorAll('[data-setting-section]')];
    let dirty = false;
    form.noValidate = true;
    function showSection(key) {
        if (!sections.some(section => section.dataset.settingSection === key)) key = 'general';
        sections.forEach(section => section.classList.toggle('active', section.dataset.settingSection === key));
        document.querySelectorAll('[data-setting-target]').forEach(link => {
            const active = link.dataset.settingTarget === key;
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
    }
    showSection(location.hash.slice(1));
    window.addEventListener('hashchange', () => showSection(location.hash.slice(1)));
    form.addEventListener('input', () => { dirty = true; document.getElementById('settingsDirty').textContent = 'You have unsaved changes.'; });
    form.addEventListener('reset', event => { event.preventDefault(); dirty = false; location.href = location.pathname + location.hash; });
    form.addEventListener('invalid', event => {
        const section = event.target.closest('[data-setting-section]');
        if (section) showSection(section.dataset.settingSection);
    }, true);
    form.addEventListener('submit', event => {
        const invalid = [...form.elements].find(field => field.willValidate && !field.validity.valid);
        if (invalid) {
            event.preventDefault();
            const section = invalid.closest('[data-setting-section]');
            if (section) showSection(section.dataset.settingSection);
            invalid.focus(); invalid.reportValidity(); return;
        }
        dirty = false;
        const button = document.getElementById('saveAllSettings'); button.disabled = true; button.textContent = 'Saving…';
    });
    document.getElementById('smtpTestForm').addEventListener('submit', event => {
        if (dirty) { event.preventDefault(); document.getElementById('settingsDirty').textContent = 'Save or reset changes before testing the saved SMTP configuration.'; }
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    document.getElementById('refreshSystem').addEventListener('click', event => { event.preventDefault(); location.reload(); });
})();
