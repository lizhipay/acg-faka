<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Service\ManageSSO;
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
     * @return array
     */
    public function login(#[Post] string $username, #[Post] string $password): array
    {
        return $this->json(200, "success", $this->sso->login($username, $password));
    }
}