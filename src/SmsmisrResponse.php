<?php

namespace Ghanem\LaravelSmsmisr;

class SmsmisrResponse
{
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly bool $success,
        public readonly array $raw,
    ) {
    }

    public static function fromArray(array $data): static
    {
        $code = $data['code'] ?? $data['Code'] ?? 0;

        $successCodes = [
            Smsmisr::SMSMISR_SUCCESS_CODE,
            Smsmisr::SMSMISR_VERIFY_SUCCESS_CODE,
            Smsmisr::SMSMISR_BALANCE_SUCCESS_CODE,
        ];

        return new static(
            code: $code,
            message: $data['message'] ?? '',
            success: in_array($code, $successCodes),
            raw: $data,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function isFailed(): bool
    {
        return ! $this->success;
    }

    public function toArray(): array
    {
        return $this->raw;
    }
}
