<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\Waf;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor(Waf::class, Interceptor::TYPE_API)]
class RechargeNotification extends User
{
    #[Inject]
    private \App\Service\Recharge $recharge;

    /**
     * @return string
     */
    public function callback(): string
    {
        $handle = $_GET['_PARAMETER'][0];
        $data = $_POST;
        if (empty($data)) {
            $data = $_REQUEST;
            unset($data['s']);
        }
        if (empty($data) && isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json') {
            $json_string = file_get_contents('php://input');
            $data = json_decode($json_string, true);
        }
        return $this->recharge->callback($handle, $data);
    }
}