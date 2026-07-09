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

namespace Box\Mod\Domaincontactvalidation;

use FOSSBilling\InformationException;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Optional staff permissions exposed in FOSSBilling admin group settings.
     * The module is still admin-only by design; these permissions are available
     * if you want to restrict management to a smaller staff group later.
     */
    public function getModulePermissions(): array
    {
        return [
            'can_always_access' => true,
            'manage_validation' => [
                'type' => 'bool',
                'display_name' => 'Manage contact validation',
                'description' => 'Allows staff to manually validate/unvalidate registrant contacts, generate validation tokens, and add audit notes.',
            ],
            'manage_settings' => [],
        ];
    }

    /**
     * Install creates the validation table if it does not already exist.
     * Existing records are preserved.
     */
    public function install(): bool
    {
        if (!$this->di) {
            throw new InformationException('Dependency injector is not available.');
        }

        $sql = "CREATE TABLE IF NOT EXISTS `domain_contact_validation` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `is_validated` tinyint(1) NOT NULL DEFAULT 0,
            `validation_checked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `validation_method` varchar(100) DEFAULT NULL,
            `validation_token` varchar(255) DEFAULT NULL,
            `validation_log` text DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `client_id` (`client_id`),
            KEY `is_validated` (`is_validated`),
            KEY `validation_checked_at` (`validation_checked_at`),
            KEY `validation_token` (`validation_token`),
            CONSTRAINT `domain_contact_validation_client_fk`
                FOREIGN KEY (`client_id`) REFERENCES `client`(`id`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1";

        $this->di['db']->exec($sql);

        return true;
    }

    /**
     * Keep validation records on uninstall for audit/compliance history.
     */
    public function uninstall(): bool
    {
        return true;
    }

    public function update(array $manifest): bool
    {
        return $this->install();
    }
}
