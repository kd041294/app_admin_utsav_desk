document.querySelectorAll('.copy-access-code').forEach(button => button.addEventListener('click', async () => {
    const element = document.getElementById(button.dataset.codeId);
    const status = document.getElementById('copyStatus');
    try { await navigator.clipboard.writeText(element.textContent.trim()); status.textContent = 'Access code copied. Share it only with the approved person.'; }
    catch { const range = document.createRange(); range.selectNodeContents(element); const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); status.textContent = 'Code selected. Press Ctrl+C or copy using your device menu.'; }
}));
document.querySelectorAll('.revoke-approval').forEach(form => form.addEventListener('submit', event => {
    if (!confirm('Revoke this approval? It will also block an unfinished registration using this code.')) event.preventDefault();
}));
document.getElementById('approvalForm').addEventListener('submit', event => {
    const button = event.currentTarget.querySelector('button'); button.disabled = true; button.textContent = 'Creating approval…';
});
