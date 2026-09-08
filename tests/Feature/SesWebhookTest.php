<?php

namespace Tests\Feature;

use App\Services\SesEventRecorder;
use App\Services\SnsMessageValidator;
use Tests\TestCase;

class SesWebhookTest extends TestCase
{
    public function test_verified_sns_notification_is_recorded(): void
    {
        config(['ses_reporting.sns_topic_arn' => 'arn:aws:sns:eu-west-2:123456789012:ses-events']);
        $this->mock(SnsMessageValidator::class, function ($mock) {
            $mock->shouldReceive('validate')->once();
        });
        $this->mock(SesEventRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->once()->with('00000000-0000-4000-8000-000000000001', '{"eventType":"Delivery"}');
        });
        $payload = ['Type' => 'Notification', 'MessageId' => '00000000-0000-4000-8000-000000000001',
            'TopicArn' => config('ses_reporting.sns_topic_arn'), 'Message' => '{"eventType":"Delivery"}'];

        $this->postJson('/webhooks/ses-events', $payload, ['x-amz-sns-topic-arn' => config('ses_reporting.sns_topic_arn')])->assertNoContent();
    }

    public function test_webhook_rejects_an_unexpected_topic_before_validation(): void
    {
        config(['ses_reporting.sns_topic_arn' => 'arn:aws:sns:eu-west-2:123456789012:ses-events']);
        $this->mock(SnsMessageValidator::class)->shouldNotReceive('validate');

        $this->postJson('/webhooks/ses-events', ['Type' => 'Notification', 'TopicArn' => 'arn:aws:sns:eu-west-2:123456789012:other'],
            ['x-amz-sns-topic-arn' => 'arn:aws:sns:eu-west-2:123456789012:other'])->assertForbidden();
    }

    public function test_verified_subscription_confirmation_is_confirmed(): void
    {
        config(['ses_reporting.sns_topic_arn' => 'arn:aws:sns:eu-west-2:123456789012:ses-events']);
        $this->mock(SnsMessageValidator::class, function ($mock) {
            $mock->shouldReceive('validate')->once();
            $mock->shouldReceive('subscribe')->once();
        });
        $payload = ['Type' => 'SubscriptionConfirmation', 'MessageId' => '00000000-0000-4000-8000-000000000002',
            'TopicArn' => config('ses_reporting.sns_topic_arn')];

        $this->postJson('/webhooks/ses-events', $payload, ['x-amz-sns-topic-arn' => config('ses_reporting.sns_topic_arn')])->assertNoContent();
    }
}
