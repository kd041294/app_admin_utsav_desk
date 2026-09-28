(() => {
    'use strict';
    const method = document.getElementById('paymentMethod');
    const reference = document.getElementById('paymentReference');
    const requireReference = () => { if (reference) reference.required = method.value !== 'CASH'; };
    if (method) { method.addEventListener('change', requireReference); requireReference(); }
    const recordModal = document.getElementById('recordPaymentModal');
    if (recordModal?.dataset.reopen === '1' && window.bootstrap) bootstrap.Modal.getOrCreateInstance(recordModal).show();
    const voidModal = document.getElementById('voidPaymentModal');
    voidModal?.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        document.getElementById('voidPaymentId').value = button.dataset.paymentId;
        document.getElementById('voidReceiptLabel').textContent = button.dataset.receipt;
    });
    document.querySelectorAll('.billing-payment-form').forEach(form => form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"], .modal-footer button:not([type])');
        if (button) { button.disabled = true; button.textContent = 'Saving…'; }
    }));
    document.getElementById('printReceipt')?.addEventListener('click', () => window.print());
    // Avoid losing partially entered filters or payments when refreshing shared data.
    let dirty = false;
    document.querySelectorAll('form').forEach(form => form.addEventListener('input', () => { dirty = true; }));
    const isReceipt = new URL(location.href).searchParams.has('receipt');
    setInterval(() => {
        if (!isReceipt && !dirty && !document.hidden && !document.querySelector('.modal.show') && !document.querySelector('.alert-danger')) location.reload();
    }, 30000);
    window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
})();
