<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Interceptor\ManageSession;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class App extends Manage
{
    #[Inject]
    private \App\Service\App $app;

    /**
     * @return array
     */
    public function versions(): array
    {
        return $this->json(200, "ok", $this->app->getVersions());
    }

    /**
     * @return array
     */
    public function latest(): array
    {
        $versions = $this->app->getVersions();
        $latestVersion = $versions[0]['version'];
        $local = config("app")['version'];
        $latest = $latestVersion == $local;
        return $this->json(200, 'ok', ["local" => $local, "latest" => $latest, "version" => $latestVersion]);
    }

    /**
     * @return array
     */
    public function update(): array
    {
        $this->app->update();
        return $this->json(200, "升级完成");
    }

    /**
     * @return array
     */
    public function ad(): array
    {
        return $this->json(200, "ok", $this->app->ad());
    }
}