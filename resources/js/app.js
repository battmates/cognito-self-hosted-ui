import './bootstrap';
import './login-progress';

for (const toggle of document.querySelectorAll('[data-password-toggle]')) {
    const input = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!input) continue;
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        const label = visible ? 'Hide password' : 'Show password';
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('aria-pressed', String(visible));
        toggle.title = label;
        toggle.querySelector('[data-password-show]').hidden = visible;
        toggle.querySelector('[data-password-hide]').hidden = !visible;
    });
    input.form?.addEventListener('submit', () => {
        input.type = 'password';
        toggle.setAttribute('aria-label', 'Show password');
        toggle.setAttribute('aria-pressed', 'false');
        toggle.title = 'Show password';
        toggle.querySelector('[data-password-show]').hidden = false;
        toggle.querySelector('[data-password-hide]').hidden = true;
    });
}
