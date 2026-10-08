import './bootstrap';
import Alpine from 'alpinejs';

import { posApp } from './pos.js';

window.Alpine = Alpine;
window.posApp = posApp;

/**
 * Shared UI state: toast notifications and the confirmation dialog.
 * Forms opt in with data-confirm="Are you sure?" (and optionally data-confirm-label / data-danger="1").
 */
Alpine.store('ui', {
    toasts: [],
    dialog: { open: false, message: '', label: 'Confirm', danger: false, form: null },

    toast(message, type = 'success', ms = 6000) {
        const id = Date.now() + Math.random();
        this.toasts.push({ id, message, type });
        if (type !== 'error') {
            setTimeout(() => this.dismiss(id), ms);
        }
    },
    dismiss(id) {
        this.toasts = this.toasts.filter((t) => t.id !== id);
    },

    ask(form, message, label, danger) {
        this.dialog = { open: true, message, label: label || 'Confirm', danger: !!danger, form };
    },
    accept() {
        const form = this.dialog.form;
        this.dialog.open = false;
        form.dataset.confirmed = '1';
        form.requestSubmit ? form.requestSubmit() : form.submit();
    },
    cancel() {
        this.dialog.open = false;
    },
});

document.addEventListener(
    'submit',
    (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;

        if (form.dataset.confirm && form.dataset.confirmed !== '1') {
            e.preventDefault();
            Alpine.store('ui').ask(form, form.dataset.confirm, form.dataset.confirmLabel, form.dataset.danger === '1');
            return;
        }

        // Loading state + protection against double submits (a double-clicked button must never post twice).
        const btn = e.submitter || form.querySelector('button[type="submit"]');
        if (btn && !btn.hasAttribute('data-no-loading')) {
            setTimeout(() => {
                btn.disabled = true;
                btn.dataset.originalHtml = btn.innerHTML;
                btn.innerHTML = '<span class="spinner"></span> ' + (btn.dataset.loadingText || 'Please wait…');
            }, 0);
        }
    },
    true,
);

// Coming back with the browser's Back button must not leave buttons stuck in the loading state.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[data-original-html]').forEach((b) => {
        b.disabled = false;
        b.innerHTML = b.dataset.originalHtml;
        delete b.dataset.originalHtml;
    });
});

Alpine.start();
