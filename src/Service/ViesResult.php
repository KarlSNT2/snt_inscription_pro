<?php
declare(strict_types=1);

namespace SNT\InscriptionPro\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * DTO du résultat d'une vérification VIES (checkVat). Miroir de `InseeResult`
 * pour rester homogène avec le flux INSEE (mêmes conventions de statuts /
 * fabriques statiques / dégradation gracieuse).
 */
final class ViesResult
{
    public const STATUS_VALID        = 'valid';
    public const STATUS_INVALID      = 'invalid';
    public const STATUS_UNAVAILABLE  = 'unavailable';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_BAD_REQUEST  = 'bad_request';

    public string $status;
    public ?string $countryCode       = null; // code pays VIES (EL pour la Grèce)
    public ?string $vatNumber         = null; // partie nationale (sans le code pays)
    public ?string $name              = null; // raison sociale (souvent absente : DE, ES…)
    public ?string $address           = null; // adresse brute non structurée (si fournie)
    public ?string $requestDate       = null;
    public ?string $requestIdentifier = null; // preuve de consultation (mode « approuvé »)
    public ?string $reason            = null; // détail d'incident (transport, http_x, vies_ms_unavailable…)

    private function __construct(string $status)
    {
        $this->status = $status;
    }

    /**
     * @param array{countryCode:?string, vatNumber:?string, name?:?string, address?:?string, requestDate?:?string, requestIdentifier?:?string} $data
     */
    public static function valid(array $data): self
    {
        $r = new self(self::STATUS_VALID);
        $r->countryCode       = $data['countryCode'] ?? null;
        $r->vatNumber         = $data['vatNumber'] ?? null;
        $r->name              = $data['name'] ?? null;
        $r->address           = $data['address'] ?? null;
        $r->requestDate       = $data['requestDate'] ?? null;
        $r->requestIdentifier = $data['requestIdentifier'] ?? null;
        return $r;
    }

    public static function invalid(?string $countryCode = null, ?string $vatNumber = null): self
    {
        $r = new self(self::STATUS_INVALID);
        $r->countryCode = $countryCode;
        $r->vatNumber   = $vatNumber;
        return $r;
    }

    public static function unavailable(string $reason): self
    {
        $r = new self(self::STATUS_UNAVAILABLE);
        $r->reason = $reason;
        return $r;
    }

    public static function rateLimited(): self
    {
        return new self(self::STATUS_RATE_LIMITED);
    }

    public static function badRequest(): self
    {
        return new self(self::STATUS_BAD_REQUEST);
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /**
     * Vrai si un incident transitoire empêche de conclure (à traiter en mode
     * dégradé / needs_review, jamais comme un refus).
     */
    public function isTransientError(): bool
    {
        return in_array($this->status, [self::STATUS_RATE_LIMITED, self::STATUS_UNAVAILABLE], true);
    }

    /**
     * Vrai si VIES a renvoyé une raison sociale exploitable (nombre de pays ne
     * la fournissent pas : la valeur peut être vide ou « --- »).
     */
    public function hasName(): bool
    {
        $n = trim((string) $this->name);
        return $n !== '' && $n !== '---';
    }

    /** Numéro de TVA complet (code pays + partie nationale). */
    public function fullNumber(): string
    {
        return (string) $this->countryCode . (string) $this->vatNumber;
    }
}
