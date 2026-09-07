<?php

namespace App\Providers;

use Aws\CloudWatch\CloudWatchClient;
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        foreach ([SesV2Client::class, CloudWatchClient::class] as $clientClass) {
            $this->app->singleton($clientClass, function () use ($clientClass) {
                $options = ['version' => 'latest', 'region' => config('ses_reporting.region'), 'http' => ['connect_timeout' => 5, 'timeout' => 15], 'retries' => 1];
                if (config('services.cognito.aws_key') && config('services.cognito.aws_secret')) {
                    $options['credentials'] = array_filter(['key' => config('services.cognito.aws_key'), 'secret' => config('services.cognito.aws_secret'), 'token' => config('services.cognito.aws_token')]);
                }

                return new $clientClass($options);
            });
        }

        $this->app->singleton(CognitoIdentityProviderClient::class, function () {
            $options = ['version' => '2016-04-18', 'region' => config('services.cognito.region'), 'http' => ['connect_timeout' => 5, 'timeout' => 15], 'retries' => 1];
            if (config('services.cognito.aws_key') && config('services.cognito.aws_secret')) {
                $options['credentials'] = array_filter(['key' => config('services.cognito.aws_key'), 'secret' => config('services.cognito.aws_secret'), 'token' => config('services.cognito.aws_token')]);
            }

            return new CognitoIdentityProviderClient($options);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
