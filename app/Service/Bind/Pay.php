<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Util\Opcache;
use Kernel\Exception\JSONException;

/**
 * Class PayService
 * @package App\Service\Impl
 */
class Pay implements \App\Service\Pay
{

    private function isSensitiveConfigField(string $name): bool
    {
        return preg_match('/(?:key|secret|token|password|passwd|private|credential|signature|sign|cert|pem|salt)/i', $name) === 1;
    }

    private function hasStoredSensitiveValue(array $config, string $name): bool
    {
        return array_key_exists($name, $config)
            && is_scalar($config[$name])
            && trim((string)$config[$name]) !== '';
    }

    private function isRequiredConfigField(mixed $required): bool
    {
        return $required === true || $required === 1 || $required === '1' || $required === 'true';
    }

    private function sensitiveConfigState(array $config): array
    {
        $state = [];
        foreach ($config as $name => $value) {
            if ($this->isSensitiveConfigField((string)$name)) {
                $state[(string)$name] = is_scalar($value) && trim((string)$value) !== '';
            }
        }
        return $state;
    }

    /**
     * Secrets are never returned to the admin page. A blank sensitive input is
     * the explicit "keep current value" sentinel handled by savePluginConfig().
     */
    private function publicConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            if ($this->isSensitiveConfigField((string)$key)) {
                $config[$key] = '';
            }
        }
        return $config;
    }

    private function secureSubmitSchema(mixed $schema, array $config): mixed
    {
        if (!is_array($schema)) {
            return $schema;
        }
        if (isset($schema['name']) && $this->isSensitiveConfigField((string)$schema['name'])) {
            $name = (string)$schema['name'];
            $hasStoredValue = $this->hasStoredSensitiveValue($config, $name);
            $isRequired = $this->isRequiredConfigField($schema['required'] ?? false);
            $schema['default'] = '';
            $schema['required'] = $isRequired && !$hasStoredValue;
            if (isset($schema['type']) && in_array((string)$schema['type'], ['input', 'password'], true)) {
                $schema['type'] = 'password';
            }
            $schema['tips'] = $hasStoredValue
                ? '敏感信息不会回显；留空表示保留已保存的值'
                : ($isRequired ? '首次配置必须填写；保存后不会回显' : '敏感信息不会回显');
        }
        foreach ($schema as $key => $item) {
            if (is_array($item)) {
                $schema[$key] = $this->secureSubmitSchema($item, $config);
            }
        }
        return $schema;
    }

    private function redactLogSecrets(string $handle, string $contents): string
    {
        $configDirectory = $this->pluginConfigDirectory($handle);
        $configPath = $configDirectory === null ? null : $this->pluginFile($configDirectory, 'Config.php');
        $config = $configPath !== null ? require($configPath) : [];
        if (is_array($config)) {
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
     * @param array<string, string> $requiredSensitive
     */
    private function collectConfigFields(mixed $schema, array &$fields, array &$requiredSensitive): void
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
                if (
                    $this->isSensitiveConfigField($name)
                    && $this->isRequiredConfigField($schema['required'] ?? false)
                ) {
                    $title = isset($schema['title']) && is_scalar($schema['title'])
                        ? trim((string)$schema['title'])
                        : '';
                    $requiredSensitive[$name] = $title !== '' ? $title : $name;
                }
            }
        }
        foreach ($schema as $item) {
            if (is_array($item)) {
                $this->collectConfigFields($item, $fields, $requiredSensitive);
            }
        }
    }

    /**
     * @param string[] $fields
     * @param array<string, string> $requiredSensitive
     */
    private function collectJsConfigFields(string $schema, array &$fields, array &$requiredSensitive): void
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

        if (!preg_match_all('/\\{[^{}]*\\}/s', $schema, $objects)) {
            return;
        }
        foreach ($objects[0] as $object) {
            if (
                !preg_match('/\\bname\\s*:\\s*([\'\"])([A-Za-z][A-Za-z0-9_.-]{0,63})\\1/i', $object, $nameMatch)
                || !preg_match('/\\btype\\s*:\\s*([\'\"])(?:input|textarea|switch|radio|select|number|image|password)\\1/i', $object)
                || !preg_match('/\\brequired\\s*:\\s*true\\b/i', $object)
            ) {
                continue;
            }
            $name = $nameMatch[2];
            if (!$this->isSensitiveConfigField($name)) {
                continue;
            }
            $title = preg_match('/\\btitle\\s*:\\s*([\'\"])(.*?)\\1/s', $object, $titleMatch)
                ? trim($titleMatch[2])
                : '';
            $requiredSensitive[$name] = $title !== '' ? $title : $name;
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
            $config = $configPath !== null ? require($configPath) : [];
            $info = is_array($info) ? $info : [];
            $config = is_array($config) ? $config : [];
            $publicConfig = $this->publicConfig($config);

            if ($submitJsPath !== null) {
                $submitContents = file_get_contents($submitJsPath);
                $submit = $submitContents === false ? '' : $submitContents;
            }

            if (is_array($submit)) {
                $submit = $this->secureSubmitSchema($submit, $config);
                foreach ($submit as $index => $item) {
                    if (is_array($item) && isset($item['name']) && array_key_exists($item['name'], $publicConfig)) {
                        $submit[$index]['default'] = $publicConfig[$item['name']];
                    }
                }
            }

            return [
                'id' => $name,
                'handle' => $name,
                'info' => $info,
                'submit' => $submit,
                'config' => $publicConfig,
                'sensitive_configured' => $this->sensitiveConfigState($config)
            ];
        }
        return [];
    }

    /**
     * @param string $name
     * @param array $config
     * @return void
     * @throws JSONException
     */
    public function savePluginConfig(string $name, array $config): void
    {
        $configDirectory = $this->pluginConfigDirectory($name);
        if ($configDirectory === null || $this->pluginFile($configDirectory, 'Info.php') === null) {
            throw new JSONException('支付插件不存在');
        }

        $fields = [];
        $requiredSensitive = [];
        $submitPath = $this->pluginFile($configDirectory, 'Submit.php');
        if ($submitPath !== null) {
            $this->collectConfigFields(require($submitPath), $fields, $requiredSensitive);
        }
        $submitJsPath = $this->pluginFile($configDirectory, 'Submit.js');
        if ($submitJsPath !== null) {
            $submitJs = file_get_contents($submitJsPath);
            if ($submitJs === false) {
                throw new JSONException('支付插件配置定义读取失败');
            }
            $this->collectJsConfigFields($submitJs, $fields, $requiredSensitive);
        }
        $fields = array_values(array_unique($fields));
        $allowed = array_merge($fields, ['top']);
        $protected = ['id', 'handle', 'plugin', 'plugin_id', 'plugin_key', 'status', 'name', 'author', 'create_time'];
        $normalized = [];

        $configPath = $configDirectory . DIRECTORY_SEPARATOR . 'Config.php';
        if (is_link($configPath)) {
            throw new JSONException('支付插件配置路径不安全');
        }
        $storedConfig = [];
        if (file_exists($configPath)) {
            $resolved = realpath($configPath);
            if ($resolved === false || !is_file($resolved) || dirname($resolved) !== $configDirectory) {
                throw new JSONException('支付插件配置路径不安全');
            }
            $configPath = $resolved;
            Opcache::invalidate($configPath);
            $loadedConfig = require($configPath);
            $storedConfig = is_array($loadedConfig) ? $loadedConfig : [];
        }

        foreach ($config as $key => $value) {
            $key = trim((string)$key);
            if (in_array(strtolower($key), $protected, true) || !in_array($key, $allowed, true)) {
                throw new JSONException('支付插件配置包含未授权字段');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new JSONException('支付插件配置字段格式错误');
            }
            if ($key === 'top') {
                $top = is_bool($value) ? ($value ? 1 : 0) : trim((string)$value);
                if (!in_array($top, [0, 1, '0', '1'], true)) {
                    throw new JSONException('支付插件置顶状态格式错误');
                }
                $normalized[$key] = (int)$top;
                continue;
            }
            $value = (string)($value ?? '');
            if ($this->isSensitiveConfigField($key) && trim($value) === '') {
                continue;
            }
            if (strlen($value) > 262144) {
                throw new JSONException('支付插件配置字段内容过长');
            }
            $normalized[$key] = $value;
        }

        foreach ($requiredSensitive as $field => $title) {
            $hasSubmittedValue = array_key_exists($field, $config)
                && is_scalar($config[$field])
                && trim((string)$config[$field]) !== '';
            if (!$hasSubmittedValue && !$this->hasStoredSensitiveValue($storedConfig, $field)) {
                throw new JSONException("请填写{$title}");
            }
        }

        if ($normalized === []) {
            // A blank sensitive field means "keep the stored secret". A form
            // containing only such blanks is therefore a successful no-op.
            return;
        }

        setConfig($normalized, $configPath);
    }
}
