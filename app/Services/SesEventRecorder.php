<?php

namespace App\Services;

use App\Models\SesEmailEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SesEventRecorder
{
    public function record(string $snsMessageId, string $body): void
    {
        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $mail = $event['mail'] ?? [];
        $messageId = $mail['messageId'] ?? null;
        $recipients = $mail['destination'] ?? null;
        $type = $event['eventType'] ?? null;
        if (! is_string($messageId) || ! is_array($recipients) || $recipients === [] || ! is_string($type)) {
            throw new RuntimeException('Malformed SES event.');
        }
        $occurredAt = CarbonImmutable::parse($this->timestamp($event, $mail))->utc();
        $detail = $this->detail($event, $type);
        $subject = data_get($mail, 'commonHeaders.subject');
        $subject = is_string($subject) ? $subject : null;
        DB::transaction(function () use ($snsMessageId, $messageId, $recipients, $mail, $type, $occurredAt, $detail, $subject) {
            foreach ($recipients as $recipient) {
                if (! is_string($recipient) || $recipient === '') {
                    continue;
                }
                SesEmailEvent::query()->firstOrCreate([
                    'sns_message_id' => $snsMessageId, 'recipient' => strtolower($recipient),
                ], ['ses_message_id' => $messageId, 'subject' => $subject, 'source' => $mail['source'] ?? null, 'event_type' => $type,
                    'occurred_at' => $occurredAt, 'detail' => $detail]);
            }
        });
    }

    private function timestamp(array $event, array $mail): string
    {
        foreach ([$event['delivery']['timestamp'] ?? null, $event['bounce']['timestamp'] ?? null, $event['complaint']['timestamp'] ?? null,
            $event['deliveryDelay']['timestamp'] ?? null, $event['open']['timestamp'] ?? null, $event['click']['timestamp'] ?? null, $mail['timestamp'] ?? null] as $timestamp) {
            if (is_string($timestamp)) {
                return $timestamp;
            }
        }
        throw new RuntimeException('SES event is missing a timestamp.');
    }

    private function detail(array $event, string $type): ?string
    {
        return match ($type) {
            'Bounce' => collect($event['bounce']['bouncedRecipients'] ?? [])->pluck('diagnosticCode')->filter()->implode('; ') ?: ($event['bounce']['bounceType'] ?? null),
            'Delivery' => $event['delivery']['smtpResponse'] ?? null,
            'DeliveryDelay' => $event['deliveryDelay']['delayType'] ?? null,
            'Complaint' => collect($event['complaint']['complainedRecipients'] ?? [])->pluck('complaintFeedbackType')->filter()->implode(', ') ?: null,
            'Reject' => $event['reject']['reason'] ?? null,
            'Rendering Failure' => $event['failure']['errorMessage'] ?? null,
            default => null,
        };
    }
}
