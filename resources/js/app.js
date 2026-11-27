import './bootstrap';
import './login-progress';

for (const form of document.querySelectorAll('[data-reset-password]')) {
    const rules = JSON.parse(form.dataset.requirements);
    const password = form.elements.password;
    const confirmation = form.elements.password_confirmation;
    const checkPassword = () => {
        const value = password.value;
        const met = { minimum_length: Array.from(value).length >= rules.minimum_length,
            uppercase: /[A-Z]/.test(value), lowercase: /[a-z]/.test(value), numbers: /[0-9]/.test(value), symbols: /[^a-zA-Z0-9\s]/u.test(value) };
        form.querySelectorAll('[data-requirement]').forEach((item) => {
            const passed = met[item.dataset.requirement];
            item.classList.toggle('is-met', passed);
            item.firstElementChild.textContent = passed ? '✓' : '○';
        });
        const strong = Object.keys(met).every((key) => !rules[key] || met[key]);
        password.setCustomValidity(strong ? '' : 'Please choose a stronger password that meets all the requirements shown below.');
        const matches = confirmation.value !== '' && value === confirmation.value;
        confirmation.setCustomValidity(confirmation.value && !matches ? 'The passwords do not match.' : '');
        form.querySelector('[data-password-match]').textContent = confirmation.value
            ? (matches ? 'Passwords match.' : 'The passwords do not match yet.') : '';
    };
    password.addEventListener('input', checkPassword);
    confirmation.addEventListener('input', checkPassword);
    checkPassword();
}

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
