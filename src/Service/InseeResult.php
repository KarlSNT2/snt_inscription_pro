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

    // Adresse de l'établissement (adresseEtablissement), renseignée sur STATUS_FOUND
    // quand l'INSEE fournit les composants. Sert à la création automatique d'une
    // adresse client (siège social) à l'inscription.
    public ?string $address1 = null;
    public ?string $address2 = null;
    public ?string $postcode = null;
    public ?string $city     = null;

    /**
     * Liste des établissements d'un SIREN (recherche multicritère), renseignée
     * uniquement par `searchFound()`. Chaque entrée :
     * {siret:string, siege:bool, active:bool, closed:bool, company:?string,
     *  address1:?string, address2:?string, postcode:?string, city:?string}
     *
     * @var array<int,array<string,mixed>>
     */
    public array $establishments = [];

    private function __construct(string $status)
    {
        $this->status = $status;
    }

    /**
     * @param array{siren:?string, company:?string, active:bool, closed:bool, address1?:?string, address2?:?string, postcode?:?string, city?:?string} $data
     */
    public static function found(array $data): self
    {
        $r = new self(self::STATUS_FOUND);
        $r->siren    = $data['siren']    ?? null;
        $r->company  = $data['company']  ?? null;
        $r->active   = (bool) ($data['active'] ?? false);
        $r->closed   = (bool) ($data['closed'] ?? false);
        $r->address1 = $data['address1'] ?? null;
        $r->address2 = $data['address2'] ?? null;
        $r->postcode = $data['postcode'] ?? null;
        $r->city     = $data['city']     ?? null;
        return $r;
    }

    /**
     * Résultat d'une recherche multicritère par SIREN : liste d'établissements.
     *
     * @param array<int,array<string,mixed>> $establishments
     */
    public static function searchFound(array $establishments, ?string $company, ?string $siren): self
    {
        $r = new self(self::STATUS_FOUND);
        $r->establishments = $establishments;
        $r->company        = $company;
        $r->siren          = $siren;
        return $r;
    }

    /**
     * Vrai si l'établissement expose les composants minimaux d'une adresse
     * postale exploitable par PrestaShop (rue + ville + code postal).
     */
    public function hasUsableAddress(): bool
    {
        return $this->address1 !== null && $this->address1 !== ''
            && $this->city !== null && $this->city !== ''
            && $this->postcode !== null && $this->postcode !== '';
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
