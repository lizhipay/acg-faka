<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Service\ManageSSO;
use App\Util\Client;
use Kernel\Annotation\Get;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Post;

/**
 * Class Auth
 * @package App\Controller\Admin\Api
 */
class Authentication extends Manage
{

    #[Inject]
    private ManageSSO $sso;

    /**
     * @param string $username
     * @param string $password
     * @param int $mode
     * @return array
     */
    public function login(#[Post] string $username, #[Post] string $password, #[Post] int $mode): array
    {
        return $this->json(200, "success", $this->sso->login($username, $password, $mode));
    }


    /**
     * @return array
     */
    public function getIp(): array
    {
        $address = [];
        for ($i = 0; $i < 9; $i++) {
            $ip = Client::getIp($i);
            if ($ip) {
                $address[] = ["ip" => $ip, "type" => $i, "risk" => $ip == "127.0.0.1"];
            }
        }

        return $this->json(200, "success", $address);
    }
}