<?php
declare(strict_types=1);

namespace App\Util;

/**
 * Class Theme
 * @package App\Util
 */
class Theme
{

    /**
     * @param string $name
     * @return array|null
     * @throws \ReflectionException
     */
    public static function getConfig(string $name): ?array
    {
        $data = Context::get("theme_" . $name);
        if ($data) {
            return $data;
        }

        $interface = "\\App\\View\\User\\Theme\\{$name}\\Config";
        if (!interface_exists($interface)) {
            return null;
        }

        $info = $interface::INFO;
        $info['KEY'] = $name;


        $ref = new \ReflectionClass($interface);
        $submit = $ref->getConstant("SUBMIT");

        if (!$submit) {
            $submit = [];
        }

        //获取配置
        $setting = [];
        $settingPath = BASE_PATH . "/app/View/User/Theme/{$name}/Setting.php";
        Opcache::invalidate($settingPath);

        if (file_exists($settingPath)) {
            $setting = (array)require($settingPath);
            foreach ($submit as $index => $item) {
                if (isset($setting[$item['name']])) {
                    $submit[$index]['default'] = $setting[$item['name']];
                }
            }
        }

        $data = ["info" => $info, "theme" => $interface::THEME, "submit" => $submit, "setting" => $setting];
        Context::set("theme_" . $name, $data);
        return $data;
    }

    /**
     * @return array
     */
    public static function getThemes(): array
    {
        $path = BASE_PATH . '/app/View/User/Theme/';
        $list = scandir($path);
        $dir = [];
        foreach ($list as $item) {
            if ($item != '.' && $item != '..' && is_dir($path . $item)) {
                $dir[] = $item;
            }
        }
        $plug = [];
        foreach ($dir as $value) {
            $platformInfo = self::getConfig($value);
            if (!empty($platformInfo)) {
                $plug[] = $platformInfo;
            }
        }
        return $plug;
    }
}