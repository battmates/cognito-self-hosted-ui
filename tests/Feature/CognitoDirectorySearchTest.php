<?php

namespace Tests\Feature;

use App\Services\CognitoDirectory;
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Mockery;
use Tests\TestCase;

class CognitoDirectorySearchTest extends TestCase
{
    private function user(string $email): array
    {
        return ['Username' => $email, 'Attributes' => [['Name' => 'email', 'Value' => $email]]];
    }

    public function test_email_substrings_match_case_insensitively_across_directory_batches(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => ! isset($input['Filter'], $input['PaginationToken']) && $input['Limit'] === 30))
            ->andReturn(new Result(['Users' => [$this->user('elsewhere@example.org'), ['Username' => 'no-email']], 'PaginationToken' => 'second']));
        $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => ! isset($input['Filter']) && $input['PaginationToken'] === 'second'))
            ->andReturn(new Result(['Users' => [$this->user('someone@DOMAIN.com'), $this->user('other@domain.com'), $this->user('unrelated@example.net')]]));
        $result = (new CognitoDirectory($client))->search('email', '@domain.com');
        $this->assertSame(['someone@DOMAIN.com', 'other@domain.com'], array_column($result['Users'], 'Username'));
        $this->assertNull($result['PaginationToken']);
    }

    public function test_email_fragment_can_match_in_the_middle_of_the_local_part(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('listUsers')->once()->andReturn(new Result(['Users' => [$this->user('first.middle.last@example.com'), $this->user('other@example.com')]]));
        $this->assertCount(1, (new CognitoDirectory($client))->search('email', 'middle')['Users']);
    }

    public function test_sparse_search_is_bounded_and_resumes_at_the_returned_cursor(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        for ($batch = 0; $batch < 10; $batch++) {
            $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => ($input['PaginationToken'] ?? null) === ($batch === 0 ? null : "batch-$batch")))
                ->andReturn(new Result(['Users' => [$this->user('elsewhere@example.org')], 'PaginationToken' => 'batch-'.($batch + 1)]));
        }
        $directory = new CognitoDirectory($client);
        $result = $directory->search('email', '@domain.com');
        $this->assertSame([], $result['Users']);
        $this->assertSame('batch-10', $result['PaginationToken']);
        $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => $input['PaginationToken'] === 'batch-10'))
            ->andReturn(new Result(['Users' => [$this->user('later@domain.com')]]));
        $this->assertCount(1, $directory->search('email', '@domain.com', $result['PaginationToken'])['Users']);
    }

    public function test_matching_batches_fill_thirty_results_without_discarding_overflow(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => $input['Limit'] === 30 && ! isset($input['PaginationToken'])))
            ->andReturn(new Result(['Users' => array_map(fn ($i) => $this->user("first-$i@domain.com"), range(1, 20)), 'PaginationToken' => 'second']));
        $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => $input['Limit'] === 10 && $input['PaginationToken'] === 'second'))
            ->andReturn(new Result(['Users' => array_map(fn ($i) => $this->user("second-$i@domain.com"), range(1, 10)), 'PaginationToken' => 'third']));
        $result = (new CognitoDirectory($client))->search('email', '@domain.com');
        $this->assertCount(30, $result['Users']);
        $this->assertSame('third', $result['PaginationToken']);
    }

    public function test_exact_email_identity_checks_and_username_prefix_search_keep_native_filters(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => $input['Filter'] === 'email = "user@example.com"'))->andReturn(new Result(['Users' => []]));
        $client->shouldReceive('listUsers')->once()->with(Mockery::on(fn ($input) => $input['Filter'] === 'username ^= "user"'))->andReturn(new Result(['Users' => []]));
        $directory = new CognitoDirectory($client);
        $this->assertSame([], $directory->search('email', 'user@example.com', null, true)['Users']);
        $this->assertSame([], $directory->search('username', 'user')['Users']);
    }

    public function test_scan_failure_does_not_return_a_misleading_partial_result(): void
    {
        $client = $this->mock(CognitoIdentityProviderClient::class);
        $client->shouldReceive('listUsers')->once()->andThrow(new AwsException('Unavailable', new Command('ListUsers')));
        $this->expectExceptionMessage('The user search could not be completed');
        (new CognitoDirectory($client))->search('email', '@domain.com');
    }
}
