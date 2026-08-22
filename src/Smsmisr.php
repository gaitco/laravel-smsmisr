<?php

namespace Ghanem\LaravelSmsmisr;

use DateTimeInterface;
use Ghanem\LaravelSmsmisr\Events\SmsFailed;
use Ghanem\LaravelSmsmisr\Events\SmsSending;
use Ghanem\LaravelSmsmisr\Events\SmsSent;
use Ghanem\LaravelSmsmisr\Exceptions\SmsmisrApiException;
use Ghanem\LaravelSmsmisr\Exceptions\SmsmisrAuthenticationException;
use Ghanem\LaravelSmsmisr\Exceptions\SmsmisrInsufficientBalanceException;
use Ghanem\LaravelSmsmisr\Exceptions\SmsmisrRateLimitException;
use Ghanem\LaravelSmsmisr\Jobs\SendBulkSmsJob;
use Ghanem\LaravelSmsmisr\Jobs\SendSmsJob;
use Ghanem\LaravelSmsmisr\Jobs\SendVerifySmsJob;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class Smsmisr
{
    public const SMSMISR_SUCCESS_CODE = 1901;
    public const SMSMISR_VERIFY_SUCCESS_CODE = 4901;
    public const SMSMISR_BALANCE_SUCCESS_CODE = 6000;

    protected const AUTH_ERROR_CODES = [1902, 4902];
    protected const BALANCE_ERROR_CODES = [1903, 4903];

    protected ?PendingRequest $client;

    public function __construct(?PendingRequest $client = null)
    {
        $this->client = $client;
    }

    /**
     * Send Normal SMS using SMSMISR API.
     *
     * @throws SmsmisrApiException
     * @throws SmsmisrRateLimitException
     */
    public function send(
        string $message,
        string $to,
        ?string $sender = null,
        int $language = 1,
        ?DateTimeInterface $scheduledAt = null,
    ): SmsmisrResponse {
        $sender = $sender ?? config('smsmisr.sender');
        $to = $this->normalizePhone($to);

        $this->checkRateLimit();

        SmsSending::dispatch($to, $message, $sender, 'sms');

        try {
            $data = $this->request('POST', 'SMS', array_merge(
                $this->credentials(),
                [
                    'sender' => $sender,
                    'language' => $language,
                    'message' => $message,
                    'mobile' => $to,
                    'DelayUntil' => $scheduledAt?->format('Y-m-d H:i:s'),
                ],
            ));

            $response = $this->parseResponse($data);

            SmsSent::dispatch($to, $message, $sender, $response, 'sms');
            $this->log('SMS sent', $to, $message, $response);

            return $response;
        } catch (\Throwable $e) {
            SmsFailed::dispatch($to, $message, $sender, $e, 'sms');
            $this->log('SMS failed', $to, $message, null, $e);

            throw $e;
        }
    }

    /**
     * Send SMS to multiple recipients.
     *
     * @param  string[]  $recipients
     *
     * @throws SmsmisrApiException
     * @throws SmsmisrRateLimitException
     */
    public function sendBulk(
        string $message,
        array $recipients,
        ?string $sender = null,
        int $language = 1,
        ?DateTimeInterface $scheduledAt = null,
    ): SmsmisrResponse {
        $sender = $sender ?? config('smsmisr.sender');
        $recipients = array_map(fn (string $phone) => $this->normalizePhone($phone), $recipients);
        $mobilesJoined = implode(',', $recipients);

        $this->checkRateLimit();

        SmsSending::dispatch($mobilesJoined, $message, $sender, 'bulk');

        try {
            $data = $this->request('POST', 'SMS', array_merge(
                $this->credentials(),
                [
                    'sender' => $sender,
                    'language' => $language,
                    'message' => $message,
                    'mobile' => $mobilesJoined,
                    'DelayUntil' => $scheduledAt?->format('Y-m-d H:i:s'),
                ],
            ));

            $response = $this->parseResponse($data);

            SmsSent::dispatch($mobilesJoined, $message, $sender, $response, 'bulk');
            $this->log('Bulk SMS sent', $mobilesJoined, $message, $response);

            return $response;
        } catch (\Throwable $e) {
            SmsFailed::dispatch($mobilesJoined, $message, $sender, $e, 'bulk');
            $this->log('Bulk SMS failed', $mobilesJoined, $message, null, $e);

            throw $e;
        }
    }

    /**
     * Send Verify/OTP SMS using SMSMISR API.
     *
     * @throws SmsmisrApiException
     * @throws SmsmisrRateLimitException
     */
    public function sendVerify(
        string $code,
        string $to,
        ?string $sender = null,
        ?string $template = null,
    ): SmsmisrResponse {
        $sender = $sender ?? config('smsmisr.sender');
        $to = $this->normalizePhone($to);

        $this->checkRateLimit();

        SmsSending::dispatch($to, $code, $sender, 'otp');

        try {
            $data = $this->request('POST', 'OTP', array_merge(
                $this->credentials(),
                [
                    'sender' => $sender,
                    'mobile' => $to,
                    'template' => $template,
                    'otp' => $code,
                ],
            ));

            $response = $this->parseResponse($data);

            SmsSent::dispatch($to, $code, $sender, $response, 'otp');
            $this->log('OTP sent', $to, '****', $response);

            return $response;
        } catch (\Throwable $e) {
            SmsFailed::dispatch($to, $code, $sender, $e, 'otp');
            $this->log('OTP failed', $to, '****', null, $e);

            throw $e;
        }
    }

    /**
     * Check Normal SMS Balance using SMSMISR API.
     *
     * @throws SmsmisrApiException
     */
    public function balance(): SmsmisrResponse
    {
        $data = $this->request('POST', 'Balance', $this->credentials());

        return $this->parseResponse($data);
    }

    /**
     * Check Verify SMS Balance using SMSMISR API.
     *
     * @throws SmsmisrApiException
     */
    public function balanceVerify(): SmsmisrResponse
    {
        $data = $this->request('POST', 'Balance', array_merge(
            $this->credentials(),
            ['SMSID' => 'verify'],
        ));

        return $this->parseResponse($data);
    }

    /**
     * Check if the API is reachable and credentials are valid.
     */
    public function health(): bool
    {
        try {
            $response = $this->balance();

            return $response->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Queue an SMS for async sending.
     */
    public function queue(
        string $message,
        string $to,
        ?string $sender = null,
        int $language = 1,
        ?DateTimeInterface $scheduledAt = null,
    ): void {
        SendSmsJob::dispatch($message, $to, $sender, $language, $scheduledAt);
    }

    /**
     * Queue a bulk SMS for async sending.
     *
     * @param  string[]  $recipients
     */
    public function queueBulk(
        string $message,
        array $recipients,
        ?string $sender = null,
        int $language = 1,
        ?DateTimeInterface $scheduledAt = null,
    ): void {
        SendBulkSmsJob::dispatch($message, $recipients, $sender, $language, $scheduledAt);
    }

    /**
     * Queue a verification SMS for async sending.
     */
    public function queueVerify(
        string $code,
        string $to,
        ?string $sender = null,
        ?string $template = null,
    ): void {
        SendVerifySmsJob::dispatch($code, $to, $sender, $template);
    }

    /**
     * Determine if the API response was successful.
     */
    public function isSuccessful(?array $response): bool
    {
        if ($response === null || !isset($response['code'])) {
            return false;
        }

        return in_array($response['code'], [
            self::SMSMISR_SUCCESS_CODE,
            self::SMSMISR_VERIFY_SUCCESS_CODE,
            self::SMSMISR_BALANCE_SUCCESS_CODE,
        ]);
    }

    /**
     * Build authentication credentials based on config.
     */
    protected function credentials(): array
    {
        $token = config('smsmisr.token');

        if ($token) {
            return [
                'environment' => config('smsmisr.environment'),
                'token' => $token,
            ];
        }

        return [
            'environment' => config('smsmisr.environment'),
            'username' => config('smsmisr.username'),
            'password' => config('smsmisr.password'),
        ];
    }

    /**
     * Normalize a phone number if auto_normalize is enabled.
     */
    protected function normalizePhone(string $phone): string
    {
        if (config('smsmisr.auto_normalize', true)) {
            return PhoneNumber::normalize($phone);
        }

        return $phone;
    }

    /**
     * Check rate limit before sending.
     *
     * @throws SmsmisrRateLimitException
     */
    protected function checkRateLimit(): void
    {
        $limit = config('smsmisr.rate_limit');

        if (!$limit) {
            return;
        }

        $executed = RateLimiter::attempt(
            'smsmisr',
            (int) $limit,
            fn () => true,
            60,
        );

        if (!$executed) {
            throw new SmsmisrRateLimitException(
                "Rate limit exceeded. Maximum {$limit} messages per minute.",
            );
        }
    }

    /**
     * Log an SMS operation if logging is enabled.
     */
    protected function log(
        string $action,
        string $to,
        string $message,
        ?SmsmisrResponse $response = null,
        ?\Throwable $error = null,
    ): void {
        $channel = config('smsmisr.log_channel');

        if (!$channel) {
            return;
        }

        $maskedTo = $this->maskPhone($to);

        $context = [
            'to' => $maskedTo,
            'action' => $action,
        ];

        if ($response) {
            $context['code'] = $response->code;
        }

        if ($error) {
            $context['error'] = $error->getMessage();
            Log::channel($channel)->error("Smsmisr: {$action} to {$maskedTo}", $context);

            return;
        }

        Log::channel($channel)->info("Smsmisr: {$action} to {$maskedTo}", $context);
    }

    /**
     * Mask a phone number for logging (show first 4 and last 2 digits).
     */
    protected function maskPhone(string $phone): string
    {
        if (str_contains($phone, ',')) {
            $phones = explode(',', $phone);

            return implode(',', array_map(fn ($p) => $this->maskPhone($p), $phones));
        }

        $length = strlen($phone);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return substr($phone, 0, 4) . str_repeat('*', $length - 6) . substr($phone, -2);
    }

    /**
     * Make an HTTP request to the SMS Misr API.
     */
    protected function request(string $method, string $endpoint, array $query): array
    {
        $http = $this->client ?? $this->buildClient();

        $response = $http
            ->withQueryParameters($query)
            ->send($method, $endpoint);

        // The API capitalises keys (`Code`, `Message`) despite documenting
        // lowercase ones; normalise once here so every consumer sees `code`.
        return array_change_key_case($response->json() ?? [], CASE_LOWER);
    }

    /**
     * Build the default HTTP client.
     */
    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(config('smsmisr.endpoint'))
            ->timeout(config('smsmisr.timeout', 30))
            ->retry(
                config('smsmisr.retries', 0),
                config('smsmisr.retry_delay', 100),
            );
    }

    /**
     * Parse the API response and throw exceptions on failure.
     *
     * @throws SmsmisrApiException
     */
    protected function parseResponse(array $data): SmsmisrResponse
    {
        $response = SmsmisrResponse::fromArray($data);

        if ($response->isSuccessful()) {
            return $response;
        }

        if (in_array($response->code, self::AUTH_ERROR_CODES)) {
            throw SmsmisrAuthenticationException::fromResponse($data);
        }

        if (in_array($response->code, self::BALANCE_ERROR_CODES)) {
            throw SmsmisrInsufficientBalanceException::fromResponse($data);
        }

        throw SmsmisrApiException::fromResponse($data);
    }
}
