const form = document.querySelector('[data-login-form]');
const dialog = document.getElementById('login-progress-dialog');

if (form && dialog && typeof dialog.showModal === 'function') {
    const submit = form.querySelector('button[type="submit"]');
    const title = dialog.querySelector('#login-progress-title');
    const message = dialog.querySelector('#login-progress-message');
    const track = dialog.querySelector('[role="progressbar"]');
    const fill = dialog.querySelector('.login-progress-fill');
    const list = dialog.querySelector('.login-progress-steps');
    const reassurance = dialog.querySelector('.login-progress-reassurance');
    const dismiss = dialog.querySelector('.login-progress-dismiss');
    const error = form.querySelector('[data-login-error]');
    const passwordDialog = document.getElementById('migration-password-dialog');
    const passwordForm = passwordDialog.querySelector('[data-password-setup]');
    const newPassword = passwordForm.elements.password;
    const confirmation = passwordForm.elements.password_confirmation;
    const passwordSubmit = passwordForm.querySelector('[type="submit"]');
    const passwordError = passwordForm.querySelector('[data-password-setup-error]');
    const requirementsList = passwordForm.querySelector('[data-password-requirements]');
    const matchMessage = passwordForm.querySelector('[data-password-match]');
    let busy = false;
    let awaitingPassword = false;
    let pendingSetup = null;
    let polling;
    let waiting;
    let attempt = 0;
    let stageCount = 0;
    let percent = 4;

    const update = (steps) => {
        if (!steps.length) return;
        const current = steps.at(-1);
        percent = Math.max(percent, current.percent);
        fill.style.width = `${percent}%`;
        track.setAttribute('aria-valuenow', percent);
        track.setAttribute('aria-valuetext', current.message);
        if (steps.length === stageCount) return;
        stageCount = steps.length;
        message.textContent = current.message;
        list.replaceChildren(...steps.map((step, index) => {
            const item = document.createElement('li');
            const warning = step.state === 'unavailable' || step.state === 'rejected';
            item.className = warning ? 'is-unavailable' : index === steps.length - 1 ? 'is-current' : 'is-complete';
            const marker = document.createElement('span');
            marker.className = 'login-progress-step-marker';
            marker.setAttribute('aria-hidden', 'true');
            marker.textContent = warning ? '!' : index === steps.length - 1 ? '•' : '✓';
            const label = document.createElement('span');
            label.textContent = step.message;
            item.append(marker, label);
            return item;
        }));
    };

    const poll = async (run) => {
        try {
            const response = await fetch(form.dataset.progressUrl, {
                headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin',
            });
            if (busy && run === attempt && response.ok) {
                const data = await response.json();
                if (busy && run === attempt) update(data.steps ?? []);
            }
        } catch { /* A status failure must not resubmit credentials or interrupt sign-in. */ }
        if (busy && run === attempt) polling = setTimeout(() => poll(run), 700);
    };

    const stop = () => {
        busy = false;
        clearTimeout(polling);
        clearTimeout(waiting);
        form.removeAttribute('aria-busy');
    };

    const start = (creating = false) => {
        busy = true;
        const run = ++attempt;
        stageCount = 0;
        percent = creating ? 75 : 4;
        error.classList.add('hidden');
        submit.disabled = true;
        submit.textContent = 'Signing in…';
        form.setAttribute('aria-busy', 'true');
        delete dialog.dataset.state;
        title.textContent = creating ? 'Setting up your account' : 'Signing you in';
        message.textContent = creating ? 'Creating your single sign-on account with your new password.' : 'Connecting securely to RSL single sign-on.';
        reassurance.textContent = 'Please keep this window open. You only need to submit once.';
        dismiss.hidden = true;
        dialog.querySelector('.login-progress-result').hidden = true;
        list.replaceChildren();
        fill.style.width = `${percent}%`;
        track.setAttribute('aria-valuenow', percent);
        track.setAttribute('aria-valuetext', message.textContent);
        dialog.showModal();
        title.focus();
        waiting = setTimeout(() => {
            reassurance.textContent = 'This is taking a little longer. We’re still working on your sign-in—please keep this window open.';
        }, 12000);
        polling = setTimeout(() => poll(run), 350);
    };

    const showError = (text) => {
        awaitingPassword = false;
        stop();
        if (!dialog.open) dialog.showModal();
        dialog.dataset.state = 'error';
        title.textContent = 'We couldn’t sign you in';
        message.textContent = text;
        track.setAttribute('aria-valuetext', 'Sign-in could not be completed');
        reassurance.textContent = 'Your email is still in the form. Return to sign in to check your details or get help.';
        dialog.querySelector('.login-progress-result').textContent = '!';
        dialog.querySelector('.login-progress-result').hidden = false;
        dismiss.hidden = false;
        error.textContent = text;
        error.classList.remove('hidden');
        dismiss.focus();
    };

    const checkNewPassword = () => {
        const rules = pendingSetup?.requirements;
        if (!rules) return;
        const value = newPassword.value;
        const met = { minimum_length: Array.from(value).length >= rules.minimum_length,
            uppercase: /[A-Z]/.test(value), lowercase: /[a-z]/.test(value), numbers: /[0-9]/.test(value), symbols: /[^a-zA-Z0-9\s]/u.test(value) };
        requirementsList.querySelectorAll('[data-requirement]').forEach((item) => {
            const passed = met[item.dataset.requirement];
            item.classList.toggle('is-met', passed);
            item.firstElementChild.textContent = passed ? '✓' : '○';
        });
        const matches = confirmation.value !== '' && value === confirmation.value;
        confirmation.setCustomValidity(confirmation.value && !matches ? 'The passwords do not match.' : '');
        matchMessage.textContent = confirmation.value ? (matches ? 'Passwords match.' : 'The passwords do not match yet.') : '';
        const strong = Object.keys(met).every((key) => !rules[key] || met[key]);
        passwordSubmit.disabled = !strong || !matches || busy;
    };

    const showPasswordSetup = (data, detail = '') => {
        pendingSetup = data;
        awaitingPassword = true;
        stop();
        dialog.close();
        form.elements.password.value = '';
        passwordForm.reset();
        passwordForm.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
            document.getElementById(toggle.getAttribute('aria-controls')).type = 'password';
            toggle.setAttribute('aria-label', 'Show password');
            toggle.setAttribute('aria-pressed', 'false');
            toggle.title = 'Show password';
            toggle.querySelector('[data-password-show]').hidden = false;
            toggle.querySelector('[data-password-hide]').hidden = true;
        });
        passwordForm.elements.challenge.value = data.challenge_id;
        passwordForm.elements.login_progress.value = form.elements.login_progress.value;
        newPassword.minLength = data.requirements.minimum_length;
        passwordDialog.querySelector('[data-password-setup-description]').textContent = 'We verified your account. Create a stronger password to finish setting up single sign-on.';
        const labels = { minimum_length: `At least ${data.requirements.minimum_length} characters`, uppercase: 'An uppercase letter', lowercase: 'A lowercase letter', numbers: 'A number', symbols: 'A symbol' };
        requirementsList.replaceChildren(...Object.entries(labels).filter(([key]) => data.requirements[key]).map(([key, label]) => {
            const item = document.createElement('li');
            item.dataset.requirement = key;
            const marker = document.createElement('span');
            marker.setAttribute('aria-hidden', 'true');
            marker.textContent = '○';
            const text = document.createElement('span');
            text.textContent = label;
            item.append(marker, text);
            return item;
        }));
        passwordError.textContent = detail;
        passwordError.classList.toggle('hidden', !detail);
        passwordSubmit.textContent = 'Create password and continue';
        checkNewPassword();
        passwordDialog.showModal();
        newPassword.focus();
    };

    const handleResponse = async (response, setupSubmission = false) => {
        const data = await response.json().catch(() => ({}));
        if (typeof data.csrf_token === 'string') {
            document.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = data.csrf_token; });
        }
        if (!response.ok) {
            const detail = Object.values(data.errors ?? {}).flat()[0];
            const fallback = response.status === 419 ? 'Your sign-in page has expired. Reload the page and try again.'
                : response.status === 429 ? 'There have been too many attempts. Please wait a minute before trying again.'
                    : 'Sign-in could not be completed. Please try again later.';
            if (setupSubmission && response.status === 422 && !data.restart_required && !data.errors?.challenge) {
                showPasswordSetup(pendingSetup, detail ?? fallback);
            } else {
                showError(detail ?? fallback);
            }
            return;
        }
        if (data.password_required === true) {
            showPasswordSetup(data);
            return;
        }
        if (typeof data.redirect !== 'string') throw new Error('Missing redirect');
        stop();
        pendingSetup = null;
        dialog.dataset.state = 'success';
        fill.style.width = '100%';
        track.setAttribute('aria-valuenow', '100');
        track.setAttribute('aria-valuetext', 'Ready for the next step');
        title.textContent = 'You’re ready to continue';
        message.textContent = 'Opening the next step of your secure sign-in.';
        reassurance.textContent = 'You’ll be redirected automatically.';
        dialog.querySelector('.login-progress-result').textContent = '✓';
        dialog.querySelector('.login-progress-result').hidden = false;
        window.location.assign(data.redirect);
    };

    dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
    dismiss.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        if (!busy && !awaitingPassword) {
            submit.disabled = false;
            submit.textContent = 'Sign in';
            form.elements.password.focus();
        }
    });
    const cancelSetup = () => {
        awaitingPassword = false;
        pendingSetup = null;
        passwordForm.reset();
        passwordDialog.close();
        submit.disabled = false;
        submit.textContent = 'Sign in';
        form.elements.password.focus();
    };
    passwordDialog.addEventListener('cancel', (event) => { event.preventDefault(); cancelSetup(); });
    passwordForm.querySelector('[data-password-setup-cancel]').addEventListener('click', cancelSetup);
    newPassword.addEventListener('input', checkNewPassword);
    confirmation.addEventListener('input', checkNewPassword);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            stop();
            awaitingPassword = false;
            pendingSetup = null;
            passwordForm.reset();
            passwordDialog.close();
            dialog.close();
            submit.disabled = false;
            submit.textContent = 'Sign in';
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || awaitingPassword) return;
        start();
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            await handleResponse(response);
        } catch {
            showError('We couldn’t confirm the result of your sign-in. Reload the page to check your session before trying again.');
        }
    });

    passwordForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !passwordForm.reportValidity()) return;
        if (pendingSetup.expires_at <= Date.now() / 1000) {
            cancelSetup();
            showError('Your password setup has expired. Please sign in again.');
            return;
        }
        const body = new FormData(passwordForm);
        awaitingPassword = false;
        passwordDialog.close();
        passwordForm.reset();
        start(true);
        try {
            const response = await fetch(passwordForm.action, { method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            await handleResponse(response, true);
        } catch {
            showError('We couldn’t confirm your account setup. Reload the page to check your session before trying again.');
        }
    });
}
