<?php
declare (strict_types=1);

namespace Kernel\Database;


use Illuminate\Contracts\Events\Dispatcher;
use Kernel\Component\Singleton;


class Listener
{

    use Singleton;


    /**
     * @var Dispatcher|null
     */
    private ?Dispatcher $queryEvent = null;


    /**
     * @return Dispatcher
     */
    public function query(): Dispatcher
    {
        if ($this->queryEvent) {
            return $this->queryEvent;
        }

        $provider = new ListenerProvider();
        $provider->on(QueryExecuted::class, function (QueryExecuted $event) {
            Plugin::instance()->hook(App::$mEnv, Point::DB_QUERY_EXECUTED, \Kernel\Plugin\Const\Plugin::HOOK_TYPE_PAGE, $event);
        });
        $this->queryEvent = new EventDispatcher($provider);
        return $this->queryEvent;
    }
}