<?php

namespace App\Core\FlowShop\database\seeders;

use App\Core\RBAC\Support\SeedsPermissions;
use Illuminate\Database\Seeder;

class FlowShopPermissionSeeder extends Seeder
{
    use SeedsPermissions;

    public function run(): void
    {
        $this->seedPermissions([
            'flow_shop_edit',
            'flow_shop_read',
        ]);
    }
}
