// assets/js/app.js — Global JS helpers

// Auto-dismiss alerts after 5s
document.querySelectorAll('.alert-dismissible').forEach(el => {
    setTimeout(() => el.remove(), 5000);
});

// Confirm dangerous actions
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
        if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
});
