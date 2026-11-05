<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LoginProgress
{
    private ?string $attempt = null;

    private array $steps = [];

    public function begin(string $attempt): void
    {
        $this->attempt = $attempt;
        $this->steps = [];
        $this->report('cognito', 'Checking your RSL single sign-on account.', 12);
    }

    public function report(string $stage, string $message, int $percent, string $state = 'checking'): void
    {
        if ($this->attempt === null) {
            return;
        }
        if (($this->steps[array_key_last($this->steps)]['stage'] ?? null) === $stage) {
            return;
        }
        $this->steps[] = ['stage' => $stage, 'message' => $message, 'percent' => $percent, 'state' => $state];
        // Store fixed status messages only. No account identifiers, passwords or tokens.
        Cache::put('portal.login.progress.'.$this->attempt, ['steps' => $this->steps], now()->addMinutes(10));
    }

    public function read(string $attempt): array
    {
        return Cache::get('portal.login.progress.'.$attempt, ['steps' => []]);
    }
}
