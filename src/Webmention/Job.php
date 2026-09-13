<?php

declare(strict_types=1);

namespace Webmention\Webmention;

/**
 * One webmention to verify.
 */
final class Job
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $source,
        public readonly string $target,
        public readonly string $token,
        public readonly ?string $code = null,
        public readonly string $endpointType = 'account',
        public readonly string $protocol = 'webmention',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            accountId:    (int) ($data['account_id'] ?? 0),
            source:       (string) ($data['source'] ?? ''),
            target:       (string) ($data['target'] ?? ''),
            token:        (string) ($data['token'] ?? ''),
            code:         isset($data['code']) && is_string($data['code']) && $data['code'] !== '' ? $data['code'] : null,
            endpointType: ($data['endpoint_type'] ?? '') === 'site' ? 'site' : 'account',
            protocol:     (string) ($data['protocol'] ?? 'webmention'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id'    => $this->accountId,
            'source'        => $this->source,
            'target'        => $this->target,
            'token'         => $this->token,
            'code'          => $this->code,
            'endpoint_type' => $this->endpointType,
            'protocol'      => $this->protocol,
        ];
    }

    public function isPrivate(): bool
    {
        return $this->code !== null;
    }
}
