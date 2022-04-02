<?php
declare (strict_types=1);

namespace App\Controller\Admin;

use App\Interceptor\ManageSession;
use App\Util\Client;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Patch extends \App\Controller\Base\View\Manage
{

    /**
     * 0.8.0->0.8.1
     * @return void
     */
    public function update080TO081(): void
    {
        $config = config("app");
        if ($config['version'] == "0.8.1-patch") {
            $obj = Manager::schema()->hasColumn("order", "premium");
            if ($obj) {
                Manager::schema()->table("order", function (Blueprint $blueprint) {
                    $blueprint->dropColumn("premium");
                });
            }
            $obj = Manager::schema()->hasColumn("commodity", "shared_premium_type");
            if ($obj) {
                Manager::schema()->table("commodity", function (Blueprint $blueprint) {
                    $blueprint->dropColumn("shared_premium_type");
                });
            }
        }

        Client::redirect("/admin/dashboard/index", "补丁安装成功，请回到后台继续更新。", 10);
    }
}