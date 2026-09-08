<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SnsMessageValidator
{
    public function validate(array $message): void
    {
        $type = $message['Type'] ?? null;
        if (! in_array($type, ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)
            || ! in_array($message['SignatureVersion'] ?? null, ['1', '2'], true)
            || ! is_string($message['Signature'] ?? null) || ! is_string($message['SigningCertURL'] ?? null)) {
            throw new RuntimeException('Invalid SNS message.');
        }
        $url = $message['SigningCertURL'];
        $parts = parse_url($url);
        $region = preg_quote((string) config('ses_reporting.region'), '/');
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? null) !== 'https' || ! preg_match('/^sns(?:\\.'.$region.')?\\.amazonaws\\.com$/', $host)
            || ! str_ends_with($parts['path'] ?? '', '.pem') || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            throw new RuntimeException('Invalid SNS signing certificate URL.');
        }
        $certificate = Cache::remember('sns-certificate:'.hash('sha256', $url), 86400, function () use ($url) {
            $response = Http::timeout(5)->get($url);
            if (! $response->successful() || ! str_contains($response->body(), 'BEGIN CERTIFICATE')) {
                throw new RuntimeException('SNS signing certificate is unavailable.');
            }

            return $response->body();
        });
        $signature = base64_decode($message['Signature'], true);
        $algorithm = $message['SignatureVersion'] === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        if ($signature === false || openssl_verify($this->canonical($message), $signature, $certificate, $algorithm) !== 1) {
            throw new RuntimeException('Invalid SNS message signature.');
        }
    }

    public function subscribe(array $message): void
    {
        $url = $message['SubscribeURL'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        $host = $parts['host'] ?? '';
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! preg_match('/^sns(?:\\.'.preg_quote((string) config('ses_reporting.region'), '/').')?\\.amazonaws\\.com$/', $host)) {
            throw new RuntimeException('Invalid SNS subscription URL.');
        }
        if (! Http::timeout(5)->get($url)->successful()) {
            throw new RuntimeException('SNS subscription confirmation failed.');
        }
    }

    private function canonical(array $message): string
    {
        $fields = match ($message['Type']) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            default => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        };
        $text = '';
        foreach ($fields as $field) {
            if (array_key_exists($field, $message)) {
                $text .= $field."\n".$message[$field]."\n";
            }
        }

        return $text;
    }
}
