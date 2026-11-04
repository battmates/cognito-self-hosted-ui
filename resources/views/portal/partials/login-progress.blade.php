<dialog class="portal-dialog login-progress-dialog" id="login-progress-dialog" aria-labelledby="login-progress-title" aria-describedby="login-progress-message">
    <div class="portal-dialog-panel login-progress-panel">
        <div class="login-progress-icon" aria-hidden="true">
            <svg class="login-progress-spinner" viewBox="0 0 48 48" fill="none"><circle cx="24" cy="24" r="19" stroke="currentColor" stroke-width="3" opacity=".2"/><path d="M24 5a19 19 0 0 1 19 19" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <span class="login-progress-result" hidden></span>
        </div>
        <p class="login-progress-eyebrow">ONE ACCOUNT. ALL YOUR RSL PLATFORMS.</p>
        <h2 class="portal-heading login-progress-title" id="login-progress-title" tabindex="-1">Signing you in</h2>
        <p class="portal-copy login-progress-message" id="login-progress-message" role="status" aria-live="polite">Connecting securely to RSL single sign-on.</p>
        <div class="login-progress-track" role="progressbar" aria-label="Sign-in steps" aria-valuemin="0" aria-valuemax="100" aria-valuenow="4" aria-valuetext="Connecting securely">
            <div class="login-progress-fill" style="width: 4%"></div>
        </div>
        <ol class="login-progress-steps" aria-label="Sign-in activity"></ol>
        <p class="portal-copy login-progress-reassurance">Please keep this window open. You only need to click sign in once.</p>
        <button type="button" class="login-progress-dismiss portal-secondary-button" hidden>Back to sign in</button>
    </div>
</dialog>
