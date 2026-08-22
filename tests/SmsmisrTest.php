<?php

namespace Ghanem\LaravelSmsmisr\Tests;

use Ghanem\LaravelSmsmisr\Exceptions\SmsmisrApiException;
use Ghanem\LaravelSmsmisr\Exceptions\SmsmisrAuthenticationException;
use Ghanem\LaravelSmsmisr\Exceptions\SmsmisrInsufficientBalanceException;
use Ghanem\LaravelSmsmisr\Smsmisr;
use Ghanem\LaravelSmsmisr\SmsmisrResponse;
use Illuminate\Support\Facades\Http;

class SmsmisrTest extends TestCase
{
    protected function fakeSuccessResponse(int $code = 1901, array $extra = []): void
    {
        Http::fake([
            '*' => Http::response(array_merge(['code' => $code, 'message' => 'Success'], $extra)),
        ]);
    }

    protected function fakeErrorResponse(int $code, string $message = 'Error'): void
    {
        Http::fake([
            '*' => Http::response(['code' => $code, 'message' => $message]),
        ]);
    }

    // --- Send SMS ---

    public function test_send_returns_smsmisr_response(): void
    {
        $this->fakeSuccessResponse();

        $response = app('smsmisr')->send('Hello', '201010101010');

        $this->assertInstanceOf(SmsmisrResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals(1901, $response->code);
    }

    public function test_send_with_custom_sender(): void
    {
        $this->fakeSuccessResponse();

        $response = app('smsmisr')->send('Hello', '201010101010', 'CustomSender');

        $this->assertTrue($response->isSuccessful());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sender=CustomSender');
        });
    }

    public function test_send_with_language_parameter(): void
    {
        $this->fakeSuccessResponse();

        $response = app('smsmisr')->send('مرحبا', '201010101010', null, 2);

        $this->assertTrue($response->isSuccessful());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'language=2');
        });
    }

    public function test_send_with_scheduled_at(): void
    {
        $this->fakeSuccessResponse();

        $scheduledAt = new \DateTime('2026-04-01 10:00:00');
        $response = app('smsmisr')->send('Hello', '201010101010', scheduledAt: $scheduledAt);

        $this->assertTrue($response->isSuccessful());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'DelayUntil=') && str_contains($request->url(), '2026-04-01');
        });
    }

    public function test_send_without_schedule_sends_null_delay(): void
    {
        $this->fakeSuccessResponse();

        app('smsmisr')->send('Hello', '201010101010');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'SMS');
        });
    }

    // --- Send Verify ---

    public function test_send_verify_returns_smsmisr_response(): void
    {
        $this->fakeSuccessResponse(4901);

        $response = app('smsmisr')->sendVerify('1234', '201010101010', null, 'template');

        $this->assertInstanceOf(SmsmisrResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals(4901, $response->code);
    }

    // --- Balance ---

    public function test_balance_returns_smsmisr_response(): void
    {
        $this->fakeSuccessResponse(6000, ['balance' => 100]);

        $response = app('smsmisr')->balance();

        $this->assertTrue($response->isSuccessful());
        $this->assertEquals(100, $response->raw['balance']);
    }

    public function test_balance_verify_returns_smsmisr_response(): void
    {
        $this->fakeSuccessResponse(6000, ['balance' => 50]);

        $response = app('smsmisr')->balanceVerify();

        $this->assertTrue($response->isSuccessful());
        $this->assertEquals(50, $response->raw['balance']);
    }

    // --- isSuccessful (backward compat) ---

    public function test_is_successful_with_sms_success_code(): void
    {
        $this->assertTrue(app('smsmisr')->isSuccessful(['code' => 1901]));
    }

    public function test_is_successful_with_verify_success_code(): void
    {
        $this->assertTrue(app('smsmisr')->isSuccessful(['code' => 4901]));
    }

    public function test_is_successful_with_balance_success_code(): void
    {
        $this->assertTrue(app('smsmisr')->isSuccessful(['code' => 6000]));
    }

    public function test_is_successful_returns_false_for_failure_code(): void
    {
        $this->assertFalse(app('smsmisr')->isSuccessful(['code' => 9999]));
    }

    public function test_is_successful_returns_false_for_null(): void
    {
        $this->assertFalse(app('smsmisr')->isSuccessful(null));
    }

    public function test_is_successful_returns_false_for_missing_code_key(): void
    {
        $this->assertFalse(app('smsmisr')->isSuccessful(['status' => 'ok']));
    }

    // --- Exceptions ---

    public function test_send_throws_authentication_exception(): void
    {
        $this->fakeErrorResponse(1902, 'Invalid credentials');

        $this->expectException(SmsmisrAuthenticationException::class);

        app('smsmisr')->send('Hello', '201010101010');
    }

    public function test_send_throws_insufficient_balance_exception(): void
    {
        $this->fakeErrorResponse(1903, 'No balance');

        $this->expectException(SmsmisrInsufficientBalanceException::class);

        app('smsmisr')->send('Hello', '201010101010');
    }

    public function test_send_throws_api_exception_for_unknown_error(): void
    {
        $this->fakeErrorResponse(1906, 'Invalid message');

        $this->expectException(SmsmisrApiException::class);

        app('smsmisr')->send('', '201010101010');
    }

    public function test_verify_throws_authentication_exception(): void
    {
        $this->fakeErrorResponse(4902, 'Invalid credentials');

        $this->expectException(SmsmisrAuthenticationException::class);

        app('smsmisr')->sendVerify('1234', '201010101010');
    }

    public function test_api_exception_contains_response_data(): void
    {
        $this->fakeErrorResponse(1906, 'Invalid message');

        try {
            app('smsmisr')->send('', '201010101010');
            $this->fail('Expected SmsmisrApiException');
        } catch (SmsmisrApiException $e) {
            $this->assertEquals(1906, $e->getCode());
            $this->assertIsArray($e->getResponse());
            $this->assertEquals(1906, $e->getResponse()['code']);
        }
    }

    // --- HTTP Client ---

    public function test_sends_post_request_to_correct_endpoint(): void
    {
        $this->fakeSuccessResponse();

        app('smsmisr')->send('Test', '201010101010');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'smsmisr.com/api/SMS');
        });
    }

    public function test_sends_credentials_in_query(): void
    {
        $this->fakeSuccessResponse();

        app('smsmisr')->send('Test', '201010101010');

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'username=test_user')
                && str_contains($url, 'password=test_pass');
        });
    }

    // --- Key normalisation (issue #8) ---

    public function test_capitalised_response_keys_are_normalised(): void
    {
        Http::fake(['*' => Http::response(['Code' => 1901, 'Message' => 'Success', 'SMSID' => 'abc'])]);

        $response = app('smsmisr')->send('Hello', '01012345678');

        $this->assertEquals(1901, $response->code);
        $this->assertEquals('Success', $response->message);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('abc', $response->toArray()['smsid']);
    }

    public function test_capitalised_error_code_still_maps_to_typed_exception(): void
    {
        Http::fake(['*' => Http::response(['Code' => 1902, 'Message' => 'Bad creds'])]);

        $this->expectException(SmsmisrAuthenticationException::class);
        app('smsmisr')->send('Hello', '01012345678');
    }
}
