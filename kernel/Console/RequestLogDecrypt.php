<?php
declare(strict_types=1);

/**
 * 请求日志解密工具。
 *
 * 日志按行 AES-256-GCM 加密后落盘，没有这个入口就没人看得懂——加密的前提是解密路径同时存在。
 *
 * 用法：
 *   php kernel/Console/RequestLogDecrypt.php runtime/request/<目录>/2026-08-31.log
 *   php kernel/Console/RequestLogDecrypt.php <文件> --key=<base64密钥>     # 用旧密钥读历史日志（不连库）
 *   php kernel/Console/RequestLogDecrypt.php <文件> --grep=admin           # 只输出含关键字的行
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
require $root . '/vendor/autoload.php';
require $root . '/kernel/Helper.php';

use App\Util\RequestLogCrypto;
use Illuminate\Database\Capsule\Manager;

//密钥存在数据库里，不带 --key 就得连库去取
$capsule = new Manager();
$capsule->addConnection(config('database'));
$capsule->setAsGlobal();
$capsule->bootEloquent();

$args = array_slice($argv, 1);
$file = null;
$key = null;
$grep = null;

foreach ($args as $a) {
    if (str_starts_with($a, '--key=')) {
        $key = base64_decode(substr($a, 6), true);
        if ($key === false || strlen($key) !== 32) {
            exit("--key 必须是 base64 编码的 32 字节密钥\n");
        }
    } elseif (str_starts_with($a, '--grep=')) {
        $grep = substr($a, 7);
    } elseif ($file === null) {
        $file = $a;
    }
}

if ($file === null) {
    exit("用法: php kernel/Console/RequestLogDecrypt.php <日志文件> [--key=<base64>] [--grep=<关键字>]\n");
}

if (!is_file($file)) {
    exit("文件不存在: {$file}\n");
}

$fh = fopen($file, 'r');
if ($fh === false) {
    exit("无法读取: {$file}\n");
}

$total = 0;
$ok = 0;
$fail = 0;
$plain = 0;

while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        continue;
    }
    $total++;

    if (!str_starts_with($line, RequestLogCrypto::PREFIX)) {
        // 加密上线之前写入的旧日志，本来就是明文
        $plain++;
        $out = $line;
    } else {
        $decoded = RequestLogCrypto::decrypt($line, $key);
        if ($decoded === null) {
            $fail++;
            continue;
        }
        $ok++;
        $out = $decoded;
    }

    if ($grep !== null && !str_contains($out, $grep)) {
        continue;
    }
    echo $out, PHP_EOL;
}

fclose($fh);

fwrite(STDERR, sprintf(
    "\n共 %d 行：解密 %d，明文旧日志 %d，解不开 %d%s\n",
    $total,
    $ok,
    $plain,
    $fail,
    $fail > 0 ? '（密钥不匹配？用 --key= 指定当时的密钥）' : ''
));
