(function () {
    const DEFAULT_TIMEOUT = 4200;

    function normalizeType(type) {
        const value = String(type || 'info').toLowerCase();
        return ['success', 'error', 'warning', 'info'].includes(value) ? value : 'info';
    }

    function ensureToastContainer() {
        let container = document.querySelector('.ud-toast-container');
        if (container) {
            return container;
        }

        container = document.createElement('div');
        container.className = 'ud-toast-container';
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-atomic', 'true');
        document.body.appendChild(container);
        return container;
    }

    function showToast(type, title, message, options = {}) {
        const normalizedType = normalizeType(type);
        const toastContainer = ensureToastContainer();
        const toast = document.createElement('div');
        toast.className = 'ud-toast ud-toast-' + normalizedType;
        toast.setAttribute('role', normalizedType === 'error' ? 'alert' : 'status');

        const icon = document.createElement('div');
        icon.className = 'ud-toast-icon';
        const iconMarkup = {
            success: 'bi-check-circle-fill',
            error: 'bi-x-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            info: 'bi-info-circle-fill',
        }[normalizedType];
        icon.innerHTML = '<i class="bi ' + iconMarkup + '"></i>';

        const text = document.createElement('div');
        text.className = 'ud-toast-copy';
        text.innerHTML = '<strong>' + (title || 'Notification') + '</strong><span>' + (message || '') + '</span>';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'ud-toast-close';
        close.innerHTML = '<i class="bi bi-x-lg"></i>';
        close.setAttribute('aria-label', 'Close');
        close.addEventListener('click', function () {
            toast.classList.remove('show');
            setTimeout(function () {
                toast.remove();
            }, 180);
        });

        toast.appendChild(icon);
        toast.appendChild(text);
        toast.appendChild(close);
        toastContainer.appendChild(toast);

        requestAnimationFrame(function () {
            toast.classList.add('show');
        });

        const timeout = options.timeout || DEFAULT_TIMEOUT;
        window.clearTimeout(toast.dataset.timerId);
        toast.dataset.timerId = String(window.setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () {
                toast.remove();
            }, 180);
        }, timeout));
    }

    window.udToast = showToast;
    window.udToastSuccess = function (title, message, options) {
        showToast('success', title, message, options || {});
    };
    window.udToastError = function (title, message, options) {
        showToast('error', title, message, options || {});
    };
    window.udToastWarning = function (title, message, options) {
        showToast('warning', title, message, options || {});
    };
    window.udToastInfo = function (title, message, options) {
        showToast('info', title, message, options || {});
    };

    function autoConvertLegacyAlerts() {
        document.querySelectorAll('.alert').forEach(function (alert) {
            const classes = alert.className || '';
            const match = String(classes).match(/alert-(success|danger|warning|info)/);
            if (!match) {
                return;
            }

            const rawType = match[1];
            const typeMap = {
                success: 'success',
                danger: 'error',
                warning: 'warning',
                info: 'info',
            };
            const titleMap = {
                success: 'Success',
                danger: 'Error',
                warning: 'Warning',
                info: 'Notice',
            };

            const text = (alert.textContent || '').replace(/\s+/g, ' ').trim();
            if (!text) {
                return;
            }

            showToast(typeMap[rawType] || 'info', titleMap[rawType] || 'Notice', text, {
                timeout: DEFAULT_TIMEOUT,
            });
            alert.remove();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        autoConvertLegacyAlerts();

        if (window.__UD_TOAST__ && typeof window.__UD_TOAST__ === 'object') {
            const payload = window.__UD_TOAST__;
            if (payload.title || payload.message) {
                showToast(payload.type || 'info', payload.title || 'Notification', payload.message || '', {
                    timeout: payload.duration || DEFAULT_TIMEOUT,
                });
            }
        }
    });
})();
