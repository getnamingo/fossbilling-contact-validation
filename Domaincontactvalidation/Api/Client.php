<?php
/**
 * FOSSBilling Contact Validation (https://fossbilling.org/)
 *
 * Displays ICANN / NIS2-style registrant contact validation tracking
 * Written in 2026 by Taras Kondratyuk (https://namingo.org)
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Box\Mod\Domaincontactvalidation\Api;

class Client extends \FOSSBilling\Api\AbstractApi
{
    private const TABLE = 'domain_contact_validation';

    public function get_status(): array
    {
        // Never accept client_id from the request.
        $clientId = (int) $this->getIdentity()->id;

        $row = $this->getDi()['db']->getRow(
            'SELECT
                is_validated,
                validation_checked_at,
                validation_method,
                CASE
                    WHEN validation_token IS NOT NULL
                     AND validation_token <> ""
                    THEN 1
                    ELSE 0
                END AS has_open_token
             FROM `' . self::TABLE . '`
             WHERE client_id = :client_id',
            [':client_id' => $clientId]
        );

        if (!$row) {
            return [
                'status' => 'not_started',
                'is_validated' => false,
                'verified_by' => null,
                'verified_at' => null,
                'verification_method' => null,
                'action_required' => true,
            ];
        }

        $isValidated = (int) $row['is_validated'] === 1;
        $hasOpenToken = (int) $row['has_open_token'] === 1;

        return [
            'status' => $isValidated
                ? 'validated'
                : ($hasOpenToken ? 'pending' : 'unvalidated'),
            'is_validated' => $isValidated,
            'verified_by' => $isValidated
                ? ($row['validated_by'] ?: 'Staff')
                : null,
            'verified_at' => $isValidated
                ? $row['validation_checked_at']
                : null,
            'verification_method' => $isValidated
                ? $row['validation_method']
                : null,
            'action_required' => !$isValidated,
        ];
    }
}