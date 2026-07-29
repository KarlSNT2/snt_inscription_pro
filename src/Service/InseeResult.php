<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class InseeResult
{
    public const STATUS_FOUND        = 'found';
    public const STATUS_NOT_FOUND    = 'not_found';
    public const STATUS_INVALID_KEY  = 'invalid_key';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_BAD_REQUEST  = 'bad_request';
    public const STATUS_UNAVAILABLE  = 'unavailable';

    public string $status;
    public ?string $siren   = null;
    public ?string $company = null;
    public bool $active     = false;
    public bool $closed     = false;
    public ?string $reason  = null;

    private function __construct(string $status)
    {
        $this->status = $status;
    }

    /**
     * @param array{siren:?string, company:?string, active:bool, closed:bool} $data
     */
    public static function found(array $data): self
    {
        $r = new self(self::STATUS_FOUND);
        $r->siren   = $data['siren']   ?? null;
        $r->company = $data['company'] ?? null;
        $r->active  = (bool) ($data['active'] ?? false);
        $r->closed  = (bool) ($data['closed'] ?? false);
        return $r;
    }

    public static function notFound(): self
    {
        return new self(self::STATUS_NOT_FOUND);
    }

    public static function invalidKey(): self
    {
        return new self(self::STATUS_INVALID_KEY);
    }

    public static function rateLimited(): self
    {
        return new self(self::STATUS_RATE_LIMITED);
    }

    public static function badRequest(): self
    {
        return new self(self::STATUS_BAD_REQUEST);
    }

    public static function unavailable(string $reason): self
    {
        $r = new self(self::STATUS_UNAVAILABLE);
        $r->reason = $reason;
        return $r;
    }

    public function isFound(): bool
    {
        return $this->status === self::STATUS_FOUND;
    }

    public function isTransientError(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_RATE_LIMITED, self::STATUS_UNAVAILABLE],
            true
        );
    }
}
