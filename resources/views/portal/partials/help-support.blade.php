@if (config('sso.help_url'))
    <p class="portal-help-support" @if ($helpOnError ?? false) data-login-help hidden @endif>
        For more support please view our <a class="portal-help-link" href="{{ config('sso.help_url') }}" target="_blank" rel="noopener noreferrer">help guide</a>.
    </p>
@endif
