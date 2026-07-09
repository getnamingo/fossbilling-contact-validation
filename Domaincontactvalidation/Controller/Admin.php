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

namespace Box\Mod\Domaincontactvalidation\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'client',
                    'label' => __trans('Contact Validation'),
                    'index' => 110,
                    'uri' => $this->di['url']->adminLink('domaincontactvalidation'),
                    'class' => 'shield-check',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/domaincontactvalidation', 'get_index', [], static::class);
        $app->get('/domaincontactvalidation/', 'get_index', [], static::class);
        $app->get('/domaincontactvalidation/index', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        $request = $app->getRequest();
        $query = $request->query;
        $api = $this->di['api_admin'];
        $config = $this->di['mod_config']('domaincontactvalidation');

        $tab = (string) $query->get('tab', 'unvalidated');
        if (!in_array($tab, ['unvalidated', 'validated', 'details'], true)) {
            $tab = 'unvalidated';
        }

        $q = trim((string) $query->get('q', ''));
        $page = max(1, (int) $query->get('page', 1));
        $clientId = max(0, (int) $query->get('client_id', 0));

        $perPage = (int) ($config['records_per_page'] ?? 25);
        $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 25;

        $defaultMethod = trim((string) ($config['default_validation_method'] ?? 'admin_manual')) ?: 'admin_manual';
        $quickActions = array_key_exists('enable_quick_actions', $config)
            ? (bool) $config['enable_quick_actions']
            : true;

        $params = [
            'tab' => $tab,
            'q' => $q,
            'page' => $page,
            'per_page' => $perPage,
            'default_method' => $defaultMethod,
            'quick_actions' => $quickActions,
            'stats' => $api->domaincontactvalidation_stats([]),
        ];

        if ($tab === 'details') {
            $params['details'] = $api->domaincontactvalidation_get_contact(['client_id' => $clientId]);
        } else {
            $params['contacts'] = $api->domaincontactvalidation_list_contacts([
                'validated' => $tab === 'validated',
                'q' => $q,
                'page' => $page,
                'per_page' => $perPage,
            ]);
        }

        return $app->render('mod_domaincontactvalidation_index', $params);
    }
}
