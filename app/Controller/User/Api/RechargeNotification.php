<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\Waf;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;

#[Interceptor(Waf::class, Interceptor::TYPE_API)]
class RechargeNotification extends User
{
    #[Inject]
    private \App\Service\Recharge $recharge;

    /**
     * @param Request $request
     * @return string
     */
    public function callback(Request $request): string
    {
        $handle = $_GET['_PARAMETER'][0];
        foreach (['unsafePost', 'unsafeJson', 'unsafeGet'] as $method) {
            $data = $request->$method();
            if (!empty($data)) {
                break;
            }
        }
        return $this->recharge->callback($handle, $data);
    }
}