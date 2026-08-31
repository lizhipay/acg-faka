<?php
declare(strict_types=1);

namespace App\Controller\Admin;


use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Service\App;
use App\Util\File;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Plugin extends Manage
{

    protected string $terms = <<<HTML
**重要声明：**

在使用本插件（以下简称“插件”）之前，请仔细阅读本免责声明。安装、使用或继续使用本插件即表示您（以下简称“用户”）已经阅读、理解并同意遵守本免责声明的所有条款。若不同意，请勿安装或使用本插件。

**1. 插件开发者的责任**

开发者对本插件的功能、适用性及其带来的后果不做任何形式的保证。本插件按“现状”提供，开发者对其准确性、完整性、可靠性或任何其他方面不作任何明示或暗示的担保。开发者不保证本插件适用于用户的特定需求，也不保证本插件在所有情况下都能正常运行。

**2. 使用风险自负**

用户明确同意，使用本插件的风险由用户自行承担。开发者不对任何由于使用或无法使用本插件所导致的直接、间接、偶然、特殊或后果性损害（包括但不限于数据丢失、利润损失、业务中断或其他经济损失）承担责任，无论此类损害是否基于合同、侵权、疏忽、严格责任或其他法律理论，即使开发者已被告知可能存在此类损害。用户应自行评估并承担使用本插件的所有风险，包括但不限于可能的系统故障、数据丢失或其他不可预见的后果。

**3. 合法使用**

用户承诺不会利用本插件进行任何违反法律、法规或公共道德的行为。用户对使用本插件的行为及其结果负全部责任。若用户使用本插件从事违法活动，用户应自行承担一切法律责任，开发者对此不承担任何责任。用户应确保在其所在司法管辖区内使用本插件的行为是合法的，并遵守所有适用的法律法规。

**4. 用户数据**

开发者不会对用户通过本插件上传、存储或传输的数据进行任何形式的监控或审查。用户对其数据的合法性、准确性和完整性负全部责任。开发者不对用户数据的丢失、篡改或泄露承担任何责任。用户应自行备份其数据，并采取适当的安全措施保护其数据的安全性和保密性。

**5. 知识产权**

本插件及其所有相关的著作权、商标、专利、商业秘密及其他知识产权归开发者所有。用户不得以任何方式侵犯这些权利。未经开发者书面许可，用户不得复制、修改、分发、销售或出租本插件或其部分内容。用户不得逆向工程、反编译或试图以其他方式获取本插件的源代码或底层技术。

**6. 第三方服务**

本插件可能会与第三方服务进行集成或链接。用户使用第三方服务时，应遵守相应的服务条款和隐私政策。开发者对第三方服务的内容、功能或安全性不承担任何责任。用户应自行评估并承担使用第三方服务的所有风险。

**7. 免责条款修改**

开发者保留随时修改本免责声明的权利。修改后的免责声明一经发布即生效，并取代先前的免责声明。用户继续使用本插件即表示接受修改后的免责声明。用户应定期查看本免责声明，以确保了解最新的条款和条件。

**8. 终止条款**

开发者有权在任何时候、无需事先通知的情况下终止用户使用本插件的权利。用户在终止后应立即停止使用本插件，并删除所有相关副本。用户在终止使用本插件后，仍需承担使用本插件期间产生的所有责任和义务。

**9. 不可抗力**

在不可抗力事件发生期间，开发者对因不可抗力导致的本插件无法正常使用或用户因此受到的损失不承担任何责任。不可抗力事件包括但不限于自然灾害、战争、恐怖袭击、政府行为、互联网中断或其他超出开发者合理控制范围的事件。

**10. 其他**

本免责声明的解释权归开发者所有。本免责声明适用`中国大陆`法律。
HTML;


    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     */
    public function index(): string
    {
        return $this->render("通用插件", "Config/Plugin.html");
    }


    /**
     * @param string $plugin
     * @return string
     * @throws JSONException
     * @throws NotFoundException
     * @throws ViewException
     */
    public function wiki(string $plugin): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $plugin)) {
            throw new NotFoundException("错误的插件");
        }

        $appStore = (array)json_decode((string)file_get_contents(BASE_PATH . "/runtime/plugin/store.cache"), true);
        $plg = \Kernel\Util\Plugin::getPlugin($plugin);

        if (!$plg) {
            throw new NotFoundException("未找到该插件");
        }

        $wiki = realpath(BASE_PATH . "/app/Plugin/{$plugin}/Wiki/");

        $staticBasePath = "/app/Plugin/{$plugin}/Wiki/";

        if (!is_dir($wiki)) {
            throw new NotFoundException("此插件没有文档");
        }

        $readme = ['README.md', 'Readme.md']; //主页
        $sidebar = ['SIDEBAR.md', 'Sidebar.md']; //菜单
        $terms = ['TERMS.md', 'Terms.md']; //免责声明

        $readmePath = '';
        $sidebarPath = '';
        $termsPath = '';

        foreach ($readme as $r) {
            if (File::exists($wiki . "/" . $r)) {
                $readmePath = $r;
                break;
            }
        }

        foreach ($sidebar as $r) {
            if (File::exists($wiki . "/" . $r)) {
                $sidebarPath = $r;
                break;
            }
        }

        foreach ($terms as $r) {
            if (File::exists($wiki . "/" . $r)) {
                $termsPath = $r;
                break;
            }
        }

        if ($termsPath === '') {
            $termsPath = "Terms.md";
            file_put_contents($wiki . "/{$termsPath}", $this->terms);
        }

        if ($sidebarPath === '') {
            $sidebarPath = "Sidebar.md";
            file_put_contents($wiki . "/{$sidebarPath}", "* [使用说明]({$readmePath})\n* [免责声明]({$termsPath})");
        }


        $iconPath = $appStore[$plugin]['icon'] ?? null;
        if (!is_string($iconPath) || !preg_match('#^/[A-Za-z0-9._~%/-]+$#D', $iconPath)) {
            $icon = "/favicon.ico";
        } else {
            $icon = App::APP_URL . $iconPath;
        }

        $pluginName = strip_tags((string)$plg['NAME']);
        $pluginNameHtml = htmlspecialchars($pluginName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

        return $this->render("通用插件", "Config/Wiki.html", [
            'pluginName' => $pluginNameHtml,
            'pluginKeyJson' => json_encode($plugin, $jsonFlags),
            // docsify 会把 name 拼进 HTML；这里传入实体编码后的文本，避免插件元数据形成标签或属性。
            'pluginNameHtmlJson' => json_encode($pluginNameHtml, $jsonFlags),
            'basePathJson' => json_encode($staticBasePath, $jsonFlags),
            'iconJson' => json_encode($icon, $jsonFlags),
            'homepageJson' => json_encode($readmePath, $jsonFlags),
            'sidebarJson' => json_encode($sidebarPath, $jsonFlags),
        ]);
    }

    /**
     * 提供 Wiki 目录下的 .md 文档内容 —— 走 PHP 路由(index.php),不依赖 nginx 对
     * /app/Plugin/**\/Wiki/*.md 的静态文件读取。宝塔等安全规则会把 README.md / CHANGELOG.md
     * 之类的文件名直接 `return 404`,导致 docsify 拉不到首页而整体报 404;本路由 URL 形如
     * /admin/plugin/wikiRes?plugin=X&file=README.md,路径部分不以 README.md 结尾,不被该规则命中,
     * 任意服务器都能正常加载文档。
     * @param string $plugin
     * @param string $file
     * @throws NotFoundException
     */
    public function wikiRes(string $plugin, string $file): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $plugin)) {
            throw new NotFoundException("错误的插件");
        }

        $wiki = realpath(BASE_PATH . "/app/Plugin/{$plugin}/Wiki");
        if ($wiki === false || !is_dir($wiki)) {
            throw new NotFoundException("此插件没有文档");
        }

        //规范化相对路径:去查询/井号、统一分隔符、禁止目录穿越
        $file = (string)preg_replace('/[?#].*$/', '', str_replace('\\', '/', $file));
        $file = ltrim($file, '/');
        if ($file === '' || strpos($file, '..') !== false) {
            throw new NotFoundException("非法文件");
        }

        //定位:先精确,再同目录大小写不敏感兜底(兼容 Linux 大小写敏感 + 插件用 readme.md 等命名)
        $target = realpath($wiki . '/' . $file);
        if ($target === false) {
            $dir = realpath($wiki . '/' . dirname($file));
            if ($dir !== false && is_dir($dir) && str_starts_with($dir . '/', $wiki . '/')) {
                $base = basename($file);
                foreach ((array)scandir($dir) as $entry) {
                    if (strcasecmp($entry, $base) === 0) {
                        $target = realpath($dir . '/' . $entry);
                        break;
                    }
                }
            }
        }

        //必须落在 Wiki 目录内、是真实文件、且是 .md(只放行文档,杜绝读取源码/配置)
        if ($target === false || !is_file($target)
            || !str_starts_with($target, $wiki . DIRECTORY_SEPARATOR)
            || strtolower((string)pathinfo($target, PATHINFO_EXTENSION)) !== 'md') {
            throw new NotFoundException("文档不存在");
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo (string)file_get_contents($target);
        exit;
    }
}
