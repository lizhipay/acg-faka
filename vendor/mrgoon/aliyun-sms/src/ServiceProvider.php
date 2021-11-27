<?php
/**
 * Created by PhpStorm.
 * User: zyxcba
 * Date: 2017/2/17
 * Time: 下午4:12
 */

namespace Mrgoon\AliSms;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class ServiceProvider extends LaravelServiceProvider
{

    public function boot()
    {

        $this->publishes([
            __DIR__.'/config.php' => config_path('aliyunsms.php'),
        ], 'config');

    }

    public function register()
    {

        $this->mergeConfigFrom(__DIR__.'/config.php', 'aliyunsms');

        $this->app->bind(AliSms::class, function() {
            return new AliSms();
        });
    }

    protected function configPath()
    {
        return __DIR__ . '/config.php';
    }

}