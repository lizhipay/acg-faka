<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Model\Pay as PayModel;
use App\Model\PayConfig as PayConfigModel;
use App\Util\Date;
use App\Util\Opcache;
use App\Util\PayProfile;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\JSONException;

/**
 * Class PayService
 * @package App\Service\Impl
 */
class Pay implements \App\Service\Pay
{
    /**
     * 单个插件最多允许存多少套配置，防手滑批量建
     */
    private const MAX_CONFIG_PER_PLUGIN = 50;


    /**
     * 只剩日志打码在用了：插件 runtime.log 里可能被网关回显出密钥，展示前按字段名糊掉。
     * 配置表单不再做任何脱敏——后台本来就是管理员才能进的地方，把值藏起来反而看不出配没配、配对没配对。
     */
    private function isSensitiveConfigField(string $name): bool
    {
        return preg_match('/(?:key|secret|token|password|passwd|private|credential|signature|sign|cert|pem|salt)/i', $name) === 1;
    }

    private function isRequiredConfigField(mixed $required): bool
    {
        return $required === true || $required === 1 || $required === '1' || $required === 'true';
    }

    private function redactLogSecrets(string $handle, string $contents): string
    {
        $configDirectory = $this->pluginConfigDirectory($handle);
        $configPath = $configDirectory === null ? null : $this->pluginFile($configDirectory, 'Config.php');
        $config = $configPath !== null ? require($configPath) : [];

        //一个插件可以有多套配置，每一套的密钥都要打码，
        //否则2号配置档的商户密钥会从日志查看器里漏出去
        $candidates = is_array($config) ? [$config] : [];
        foreach (PayProfile::list($handle) as $profile) {
            $values = PayProfile::raw($handle, (int)$profile['id']);
            if (is_array($values)) {
                $candidates[] = $values;
            }
        }

        foreach ($candidates as $config) {
            foreach ($config as $key => $value) {
                if (!$this->isSensitiveConfigField((string)$key) || !is_scalar($value)) {
                    continue;
                }
                $secret = (string)$value;
                if (strlen($secret) >= 4) {
                    $contents = str_replace($secret, '[REDACTED]', $contents);
                }
            }
        }

        return (string)preg_replace(
            '/((?:key|secret|token|password|passwd|private[_-]?key|credential|signature|sign|cert|pem|salt)[A-Za-z0-9_.-]*\s*(?:=|:)\s*)([^\s&,;]+)/i',
            '$1[REDACTED]',
            $contents
        );
    }

    /**
     * Resolve an installed payment plugin to one direct child of app/Pay.
     * Rejecting links and non-canonical names keeps every later file operation
     * inside that plugin directory.
     */
    private function pluginDirectory(string $name): ?string
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name)) {
            return null;
        }

        $root = realpath(BASE_PATH . '/app/Pay');
        $candidate = $root === false ? false : $root . DIRECTORY_SEPARATOR . $name;
        if ($root === false || $candidate === false || is_link($candidate)) {
            return null;
        }

        $path = realpath($candidate);
        if ($path === false || !is_dir($path) || dirname($path) !== $root || basename($path) !== $name) {
            return null;
        }
        return $path;
    }

    private function pluginConfigDirectory(string $name): ?string
    {
        $plugin = $this->pluginDirectory($name);
        if ($plugin === null) {
            return null;
        }

        $candidate = $plugin . DIRECTORY_SEPARATOR . 'Config';
        if (is_link($candidate)) {
            return null;
        }
        $path = realpath($candidate);
        if ($path === false || !is_dir($path) || dirname($path) !== $plugin || basename($path) !== 'Config') {
            return null;
        }
        return $path;
    }

    private function pluginFile(string $directory, string $file): ?string
    {
        $candidate = $directory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($candidate) || is_link($candidate)) {
            return null;
        }
        $path = realpath($candidate);
        if ($path === false || dirname($path) !== $directory) {
            return null;
        }
        return $path;
    }

    /**
     * @param mixed $schema
     * @param string[] $fields
     */
    private function collectConfigFields(mixed $schema, array &$fields): void
    {
        if (!is_array($schema)) {
            return;
        }
        $editableTypes = ['input', 'textarea', 'switch', 'radio', 'select', 'number', 'image', 'password'];
        if (
            isset($schema['name'], $schema['type'])
            && is_string($schema['name'])
            && is_string($schema['type'])
            && in_array(strtolower(trim($schema['type'])), $editableTypes, true)
        ) {
            $name = trim($schema['name']);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $name)) {
                $fields[] = $name;
            }
        }
        foreach ($schema as $item) {
            if (is_array($item)) {
                $this->collectConfigFields($item, $fields);
            }
        }
    }

    /**
     * Submit.js 是给前端 eval 的源码，服务端只能用正则把字段名扒出来当写入白名单。
     *
     * @param string[] $fields
     */
    private function collectJsConfigFields(string $schema, array &$fields): void
    {
        if (preg_match_all(
            '/\\bname\\s*:\\s*([\'\"])([A-Za-z][A-Za-z0-9_.-]{0,63})\\1\\s*,\\s*type\\s*:\\s*([\'\"])(?:input|textarea|switch|radio|select|number|image|password)\\3/i',
            $schema,
            $matches
        )) {
            foreach ($matches[2] as $field) {
                $fields[] = $field;
            }
        }
    }

    /**
     * @throws JSONException
     */
    private function pluginLogPath(string $handle): string
    {
        $directory = $this->pluginDirectory($handle);
        if ($directory === null) {
            throw new JSONException('支付插件不存在');
        }

        $candidate = $directory . DIRECTORY_SEPARATOR . 'runtime.log';
        if (!file_exists($candidate)) {
            return $candidate;
        }
        if (!is_file($candidate) || is_link($candidate)) {
            throw new JSONException('支付插件日志路径不安全');
        }
        $path = realpath($candidate);
        if ($path === false || dirname($path) !== $directory) {
            throw new JSONException('支付插件日志路径不安全');
        }
        return $path;
    }

    /**
     * @param string $handle
     * @return string
     */
    public function getPluginLog(string $handle): string
    {
        $path = $this->pluginLogPath($handle);
        if (!is_file($path)) {
            return '';
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new JSONException('支付插件日志读取失败');
        }
        return $this->redactLogSecrets($handle, $contents);
    }

    /**
     * @param string $handle
     * @return bool
     */
    public function ClearPluginLog(string $handle): bool
    {
        $path = $this->pluginLogPath($handle);
        return !file_exists($path) || unlink($path);
    }

    /**
     * @return array
     */
    public function getPlugins(): array
    {
        $path = BASE_PATH . '/app/Pay/';
        $list = scandir($path);
        $dir = [];
        foreach ($list as $item) {
            if ($item != '.' && $item != '..' && is_dir($path . $item)) {
                $dir[] = $item;
            }
        }
        //插件列表
        $plug = [];
        foreach ($dir as $value) {
            $platformInfo = $this->getPluginInfo($value);
            if (!empty($platformInfo)) {
                $plug[] = $platformInfo;
            }
        }
        return $plug;
    }

    /**
     * @param string $name
     * @return array
     */
    public function getPluginInfo(string $name): array
    {
        $configDirectory = $this->pluginConfigDirectory($name);
        if ($configDirectory !== null) {
            $infoPath = $this->pluginFile($configDirectory, 'Info.php');
            if ($infoPath === null) {
                return [];
            }
            $submitPath = $this->pluginFile($configDirectory, 'Submit.php');
            $submitJsPath = $this->pluginFile($configDirectory, 'Submit.js');
            $configPath = $this->pluginFile($configDirectory, 'Config.php');

            Opcache::invalidate(...array_values(array_filter([$infoPath, $submitPath, $configPath])));

            //解析信息
            $info = require($infoPath);
            $submit = $submitPath !== null ? require($submitPath) : [];
            $fileConfig = $configPath !== null ? require($configPath) : [];
            $info = is_array($info) ? $info : [];
            $fileConfig = is_array($fileConfig) ? $fileConfig : [];

            //配置值现在存在 pay_config 表里。插件目录里的 Config.php 只剩下 top 这个
            //插件级的排序标记（以及升级前留下的旧值，仅作回滚备份，不再当凭据读）。
            //列表页的「配置」按钮编辑的是默认配置档，所以这里回填的也是它。
            $configs = PayProfile::list($name);
            $defaultConfigId = $this->defaultConfigId($name);
            $config = $defaultConfigId > 0 ? (PayProfile::raw($name, $defaultConfigId) ?? []) : [];
            $config['top'] = (int)($fileConfig['top'] ?? 0);

            if ($submitJsPath !== null) {
                $submitContents = file_get_contents($submitJsPath);
                $submit = $submitContents === false ? '' : $submitContents;
            }

            if (is_array($submit)) {
                foreach ($submit as $index => $item) {
                    if (is_array($item) && isset($item['name']) && array_key_exists($item['name'], $config)) {
                        $submit[$index]['default'] = $config[$item['name']];
                    }
                }
            }

            return [
                'id' => $name,
                'handle' => $name,
                'info' => $info,
                'submit' => $submit,
                'config' => $config,
                //只有配置档的 id 和名字，不含配置值。支付接口表单的下拉框就靠它填充
                'configs' => $configs,
                'default_config_id' => $defaultConfigId
            ];
        }
        return [];
    }

    /**
     * 插件自己声明了哪些配置字段——既是表单定义，也是写入白名单。
     * Submit.php 是数组，Submit.js 是一段前端要 eval 的源码，两边都要认。
     *
     * @param string $configDirectory
     * @return string[]
     * @throws JSONException
     */
    private function configFieldAllowlist(string $configDirectory): array
    {
        $fields = [];

        $submitPath = $this->pluginFile($configDirectory, 'Submit.php');
        if ($submitPath !== null) {
            $this->collectConfigFields(require($submitPath), $fields);
        }

        $submitJsPath = $this->pluginFile($configDirectory, 'Submit.js');
        if ($submitJsPath !== null) {
            $submitJs = file_get_contents($submitJsPath);
            if ($submitJs === false) {
                throw new JSONException('支付插件配置定义读取失败');
            }
            $this->collectJsConfigFields($submitJs, $fields);
        }

        return array_values(array_unique($fields));
    }

    /**
     * 插件目录里 Config.php 的安全路径。它现在只用来存 top。
     *
     * @param string $configDirectory
     * @return string
     * @throws JSONException
     */
    private function pluginConfigFilePath(string $configDirectory): string
    {
        $configPath = $configDirectory . DIRECTORY_SEPARATOR . 'Config.php';
        if (is_link($configPath)) {
            throw new JSONException('支付插件配置路径不安全');
        }
        if (file_exists($configPath)) {
            $resolved = realpath($configPath);
            if ($resolved === false || !is_file($resolved) || dirname($resolved) !== $configDirectory) {
                throw new JSONException('支付插件配置路径不安全');
            }
            $configPath = $resolved;
            Opcache::invalidate($configPath);
        }
        return $configPath;
    }

    /**
     * 该插件的默认配置档（排序最前的那一个）。
     *
     * @param string $handle
     * @param bool $create 没有任何配置档时是否自动建一个「默认配置」
     * @return int 0 表示没有
     * @throws JSONException
     */
    private function defaultConfigId(string $handle, bool $create = false): int
    {
        $list = PayProfile::list($handle);
        if ($list !== []) {
            return (int)$list[0]['id'];
        }
        return $create ? $this->createPluginConfig($handle, '默认配置') : 0;
    }

    /**
     * 保存一套配置。
     *
     * top 是插件级的排序标记，跟商户号无关，仍旧写在插件目录的 Config.php 里；
     * 其余字段写进 pay_config 的某一行。不指定 $configId 时写默认配置档，
     * 这样支付插件列表页那个「配置」按钮的行为跟以前一模一样。
     *
     * @param string $name
     * @param array $config
     * @param int|null $configId
     * @return void
     * @throws JSONException
     */
    public function savePluginConfig(string $name, array $config, ?int $configId = null): void
    {
        $configDirectory = $this->pluginConfigDirectory($name);
        if ($configDirectory === null || $this->pluginFile($configDirectory, 'Info.php') === null) {
            throw new JSONException('支付插件不存在');
        }

        //先把插件级的 top 摘出去
        if (array_key_exists('top', $config)) {
            $raw = $config['top'];
            $top = is_bool($raw) ? ($raw ? 1 : 0) : trim((string)$raw);
            if (!in_array($top, [0, 1, '0', '1'], true)) {
                throw new JSONException('支付插件置顶状态格式错误');
            }
            unset($config['top']);
            setConfig(['top' => (int)$top], $this->pluginConfigFilePath($configDirectory));
        }

        if ($config === []) {
            //只切了置顶开关
            return;
        }

        $allowed = $this->configFieldAllowlist($configDirectory);
        $protected = ['id', 'handle', 'plugin', 'plugin_id', 'plugin_key', 'status', 'name', 'author', 'create_time'];
        $normalized = [];

        $configId = $configId !== null && $configId > 0 ? $configId : $this->defaultConfigId($name, true);
        $storedConfig = PayProfile::raw($name, $configId);
        if ($storedConfig === null) {
            throw new JSONException('支付配置不存在或不属于该插件');
        }

        foreach ($config as $key => $value) {
            $key = trim((string)$key);
            if (in_array(strtolower($key), $protected, true) || !in_array($key, $allowed, true)) {
                throw new JSONException('支付插件配置包含未授权字段');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new JSONException('支付插件配置字段格式错误');
            }
            $value = (string)($value ?? '');
            if (strlen($value) > 262144) {
                throw new JSONException('支付插件配置字段内容过长');
            }
            $normalized[$key] = $value;
        }

        if ($normalized === []) {
            return;
        }

        //表单只提交了这一套配置里的部分字段时（比如 Submit.js 按条件隐藏了另一半），
        //没提交的键要保留原值，所以在已存配置上做合并而不是整体覆盖。
        $this->writeConfigValues($name, $configId, array_replace($storedConfig, $normalized));
    }

    /**
     * 落库并清掉请求内缓存。
     *
     * @param string $handle
     * @param int $configId
     * @param array $values
     * @return void
     * @throws JSONException
     */
    private function writeConfigValues(string $handle, int $configId, array $values): void
    {
        $affected = PayConfigModel::query()
            ->where("id", $configId)
            ->where("handle", $handle)
            ->update([
                'config' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => Date::current()
            ]);

        if ($affected === 0 && !PayProfile::exists($handle, $configId)) {
            throw new JSONException('支付配置不存在或不属于该插件');
        }

        PayProfile::flush($handle, $configId);
    }

    /**
     * 某插件的全部配置档，带脱敏后的值与表单定义，供后台配置档管理界面使用。
     *
     * @param string $handle
     * @return array
     * @throws JSONException
     */
    public function listPluginConfigs(string $handle): array
    {
        $configDirectory = $this->pluginConfigDirectory($handle);
        if ($configDirectory === null || $this->pluginFile($configDirectory, 'Info.php') === null) {
            throw new JSONException('支付插件不存在');
        }

        $submitPath = $this->pluginFile($configDirectory, 'Submit.php');
        $submitJsPath = $this->pluginFile($configDirectory, 'Submit.js');
        $baseSubmit = $submitPath !== null ? require($submitPath) : [];
        $baseSubmit = is_array($baseSubmit) ? $baseSubmit : [];

        //Submit.js 存在就以它为准（前端 eval），服务端无法内省它，原样透传
        $submitJs = null;
        if ($submitJsPath !== null) {
            $contents = file_get_contents($submitJsPath);
            $submitJs = $contents === false ? null : $contents;
        }

        //哪些支付接口在用这套配置。带上图标，前端渲染成徽标；删除前也要靠它拦。
        $inUse = [];
        foreach (PayModel::query()->where("handle", $handle)->get(['id', 'name', 'icon', 'pay_config_id']) as $row) {
            $inUse[(int)$row->pay_config_id][] = [
                'id' => (int)$row->id,
                'name' => (string)$row->name,
                'icon' => (string)($row->icon ?? '')
            ];
        }

        $profiles = [];
        foreach (PayProfile::list($handle) as $index => $profile) {
            $id = (int)$profile['id'];
            $values = PayProfile::raw($handle, $id) ?? [];
            $profiles[] = [
                'id' => $id,
                'name' => $profile['name'],
                'sort' => $profile['sort'],
                'is_default' => $index === 0,
                'in_use' => $inUse[$id] ?? [],
                'config' => $values,
                'submit' => $submitJs ?? $baseSubmit
            ];
        }

        return ['handle' => $handle, 'profiles' => $profiles];
    }

    /**
     * 新建一套空配置。
     *
     * @param string $handle
     * @param string $name
     * @return int 新配置档id
     * @throws JSONException
     */
    public function createPluginConfig(string $handle, string $name): int
    {
        $configDirectory = $this->pluginConfigDirectory($handle);
        if ($configDirectory === null || $this->pluginFile($configDirectory, 'Info.php') === null) {
            throw new JSONException('支付插件不存在');
        }

        $name = $this->normalizeConfigName($name);

        if (PayConfigModel::query()->where("handle", $handle)->count() >= self::MAX_CONFIG_PER_PLUGIN) {
            throw new JSONException('该插件的配置数量已达上限');
        }

        if (PayConfigModel::query()->where("handle", $handle)->where("name", $name)->exists()) {
            throw new JSONException('该配置名称已存在');
        }

        $model = new PayConfigModel();
        $model->handle = $handle;
        $model->name = $name;
        $model->config = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $model->sort = 0;
        $model->create_time = Date::current();
        $model->save();

        PayProfile::flush($handle);

        return (int)$model->id;
    }

    /**
     * @param string $handle
     * @param int $id
     * @param string $name
     * @return void
     * @throws JSONException
     */
    public function renamePluginConfig(string $handle, int $id, string $name): void
    {
        $name = $this->normalizeConfigName($name);

        $model = PayConfigModel::query()->where("id", $id)->where("handle", $handle)->first();
        if (!$model) {
            throw new JSONException('支付配置不存在或不属于该插件');
        }

        $duplicated = PayConfigModel::query()
            ->where("handle", $handle)->where("name", $name)->where("id", "<>", $id)->exists();
        if ($duplicated) {
            throw new JSONException('该配置名称已存在');
        }

        $model->name = $name;
        $model->update_time = Date::current();
        $model->save();

        PayProfile::flush($handle, $id);
    }

    /**
     * 删除一套配置。被支付接口引用着就不许删——删掉那些接口下单会直接失败。
     *
     * @param string $handle
     * @param int $id
     * @return void
     * @throws JSONException
     */
    public function deletePluginConfig(string $handle, int $id): void
    {
        DB::transaction(function () use ($handle, $id) {
            $model = PayConfigModel::query()->where("id", $id)->where("handle", $handle)->lockForUpdate()->first();
            if (!$model) {
                throw new JSONException('支付配置不存在或不属于该插件');
            }

            //默认配置是这个插件的兜底，删了之后新建支付接口就没得选了。界面上已经藏了删除按钮，
            //这里再拦一道——按钮能藏，接口不能不设防。要换默认的，先新建一套并把排序调到最前。
            if ($id === $this->defaultConfigId($handle)) {
                throw new JSONException('默认配置不允许删除，如需更换请先新增一套并调整排序');
            }

            $used = PayModel::query()->where("pay_config_id", $id)->lockForUpdate()->get(['name']);
            if (count($used) > 0) {
                $names = implode('、', array_map(static fn($row) => (string)$row->name, $used->all()));
                throw new JSONException("该配置正在被支付接口使用，请先改用其他配置：{$names}");
            }

            $model->delete();
        });

        PayProfile::flush($handle, $id);
    }

    /**
     * @param string $name
     * @return string
     * @throws JSONException
     */
    private function normalizeConfigName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 16) {
            throw new JSONException('配置名称请控制在1-16个字符');
        }
        if (preg_match('/[\x00-\x1f<>]/', $name)) {
            throw new JSONException('配置名称包含非法字符');
        }
        return $name;
    }
}
