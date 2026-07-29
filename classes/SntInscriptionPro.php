<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class SntInscriptionPro extends ObjectModel
{
    /** @var int */
    public $id_customer;

    /** @var string|null */
    public $vatNumber;

    /** @var string|null */
    public $afe;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table'   => 'snt_inscription_pro',
        'primary' => 'id_snt_inscription_pro',
        'fields'  => [
            'id_customer' => ['type' => self::TYPE_INT,    'validate' => 'isUnsignedId', 'required' => true],
            'vatNumber'   => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 20],
            'afe'         => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 34],
            'date_add'    => ['type' => self::TYPE_DATE,   'validate' => 'isDate'],
            'date_upd'    => ['type' => self::TYPE_DATE,   'validate' => 'isDate'],
        ],
    ];
}
