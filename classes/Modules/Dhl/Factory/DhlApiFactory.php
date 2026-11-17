<?php

namespace Xentral\Modules\Dhl\Factory;

use Xentral\Modules\Dhl\Api\DhlApi;

/**
 * Class DhlApiFactory
 *
 * @package Xentral\Modules\Dhl\Factory
 */
class DhlApiFactory
{
    public static function createProductionInstance(
        $user,
        $pass,
        $accountNumber,
        $senderName,
        $senderStreetName,
        $senderStreetNo,
        $senderZip,
        $senderCity,
        $senderCountry,
        $senderEmail
    ) {
        $cigUser = getenv('DHL_CIG_USER') ?: 'wawision_2';
        $cigPassword = getenv('DHL_CIG_PASSWORD') ?: '';

        return new DhlApi(
            $user,
            $pass,
            $cigUser,
            $cigPassword,
            $accountNumber,
            'https://cig.dhl.de/services/production/soap',
            $senderName,
            $senderStreetName,
            $senderStreetNo,
            $senderZip,
            $senderCity,
            $senderCountry,
            $senderEmail
        );
    }
}
