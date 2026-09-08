<?php

namespace App\Http\Controllers;

use App\Services\SesEventRecorder;
use App\Services\SnsMessageValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SesWebhookController extends Controller
{
    public function handle(Request $request, SnsMessageValidator $validator, SesEventRecorder $recorder)
    {
        $message = json_decode($request->getContent(), true);
        $topic = config('ses_reporting.sns_topic_arn');
        if (! is_array($message) || ! is_string($topic) || $topic === '' || ! hash_equals($topic, (string) ($message['TopicArn'] ?? ''))
            || ! hash_equals($topic, (string) $request->header('x-amz-sns-topic-arn'))) {
            abort(403);
        }
        try {
            $validator->validate($message);
            if (($message['Type'] ?? null) === 'SubscriptionConfirmation') {
                $validator->subscribe($message);
            } elseif (($message['Type'] ?? null) === 'Notification') {
                $recorder->record((string) $message['MessageId'], (string) ($message['Message'] ?? ''));
            }
        } catch (\JsonException|RuntimeException $e) {
            Log::warning('portal.ses_event_webhook_failed', ['type' => $message['Type'] ?? null, 'message_id' => $message['MessageId'] ?? null]);

            return response()->json(['message' => 'Unable to process SES event.'], 500);
        }

        return response()->noContent();
    }
}
