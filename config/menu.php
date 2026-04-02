<?php

return [
    [
        'placement' => 'group_children',
        'group' => 'settings',
        'item' => [
            'title' => 'admin.marketplace_client.menu.title',
            'icon' => null,
            'route' => 'cms.admin.flow-shop.published-modules',
            'permission' => 'marketplace_client_read',
            'active_routes' => [
                'cms.admin.flow-shop.*',
                'cms.admin.modules.*',
                'cms.admin.integrations.*',
                'cms.admin.flow-shop-management.*',
            ],
            'favorite_enabled' => true,
            'children' => [
                [
                    'title' => 'admin.marketplace_client.menu.module_management',
                    'route' => 'cms.admin.modules.index',
                    'permission' => 'module_management_read',
                    'active_routes' => ['cms.admin.modules.*'],
                ],
                [
                    'title' => 'admin.marketplace_client.menu.integration_management',
                    'route' => 'cms.admin.integrations.index',
                    'permission' => 'integrations_read',
                    'active_routes' => ['cms.admin.integrations.*'],
                ],
                [
                    'title' => 'admin.marketplace_client.menu.market',
                    'route' => 'cms.admin.flow-shop.published-modules',
                    'permission' => 'marketplace_client_read',
                    'active_routes' => [
                        'cms.admin.flow-shop.published-modules',
                        'cms.admin.flow-shop.published-integrations',
                    ],
                ],
                [
                    'title' => 'admin.marketplace_client.menu.flowshop_management',
                    'route' => 'cms.admin.flow-shop-management.index',
                    'permission' => 'flow_shop_management_server_read',
                    'active_routes' => ['cms.admin.flow-shop-management.*'],
                ],
            ],
        ],
    ],
];
