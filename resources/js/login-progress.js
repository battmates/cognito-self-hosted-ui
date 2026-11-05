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
    let busy = false;
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
        } catch { /* A status update failure must not resubmit or interrupt sign-in. */ }
        if (busy && run === attempt) polling = setTimeout(() => poll(run), 700);
    };

    const stop = () => {
        busy = false;
        clearTimeout(polling);
        clearTimeout(waiting);
        form.removeAttribute('aria-busy');
    };

    const showError = (text) => {
        stop();
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

    // Escape/backdrop dismissal would imply the server operation was cancelled.
    dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
    dismiss.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        if (!busy) {
            submit.disabled = false;
            submit.textContent = 'Sign in';
            form.querySelector('[name="password"]').focus();
        }
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            stop();
            dialog.close();
            submit.disabled = false;
            submit.textContent = 'Sign in';
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        const run = ++attempt;
        stageCount = 0;
        percent = 4;
        error.classList.add('hidden');
        submit.disabled = true;
        submit.textContent = 'Signing in…';
        form.setAttribute('aria-busy', 'true');
        delete dialog.dataset.state;
        title.textContent = 'Signing you in';
        message.textContent = 'Connecting securely to RSL single sign-on.';
        reassurance.textContent = 'Please keep this window open. You only need to click sign in once.';
        dismiss.hidden = true;
        dialog.querySelector('.login-progress-result').hidden = true;
        list.replaceChildren();
        fill.style.width = '4%';
        track.setAttribute('aria-valuenow', '4');
        track.setAttribute('aria-valuetext', message.textContent);
        dialog.showModal();
        title.focus();
        waiting = setTimeout(() => {
            reassurance.textContent = 'This is taking a little longer. We’re still checking your account—please keep this window open.';
        }, 12000);
        // Allow the POST to publish its first stage before reading status.
        polling = setTimeout(() => poll(run), 350);
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const detail = Object.values(data.errors ?? {}).flat()[0];
                const fallback = response.status === 419
                    ? 'Your sign-in page has expired. Reload the page and try again.'
                    : response.status === 429
                        ? 'There have been too many sign-in attempts. Please wait a minute before trying again.'
                        : 'Sign-in could not be completed. Please try again later.';
                showError(detail ?? fallback);
                return;
            }
            if (typeof data.redirect !== 'string') throw new Error('Missing redirect');
            stop();
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
        } catch {
            showError('We couldn’t confirm the result of your sign-in. Reload the page to check your session before trying again.');
        }
    });
}
