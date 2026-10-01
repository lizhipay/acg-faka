<?php
declare(strict_types=1);

namespace App\Util;

use Firebase\JWT\JWT as FirebaseJWT;

/**
 * WebAuthn / FIDO2 服务端校验，原生实现，仅依赖内建 openssl，无第三方库。
 *
 * 支援演算法：ES256（COSE alg -7，ECDSA P-256）与 RS256（-257，RSASSA-PKCS1-v1_5）。
 * attestation 一律以 none 处理——注册在「已认证的会话」（管理员 / 会员）内完成，来源本身可信，
 * 因此只解析出凭证公钥，不校验 attestation 声明（符合 passkey 场景的常规做法）。
 *
 * 登入/解锁的断言（assertion）校验完全不需要 CBOR；注册（attestation）需要极小的
 * CBOR 解析取出 authData 与 COSE 公钥，再转成 PEM 交给 openssl。
 */
class WebAuthn
{
    /* ---------------------------------------------------------------- base64url */

    public static function b64uEncode(string $bin): string
    {
        return FirebaseJWT::urlsafeB64Encode($bin);
    }

    public static function b64uDecode(string $s): string
    {
        return FirebaseJWT::urlsafeB64Decode($s);
    }

    /**
     * 32 字节随机挑战，回传 base64url（无填充）。
     */
    public static function challenge(): string
    {
        return self::b64uEncode(random_bytes(32));
    }

    /**
     * 依当前请求推导 rpId 与允许来源。
     * rpId = 主机名（不含端口）；origin = scheme://host[:port]。
     *
     * @return array{0:string,1:array<int,string>} [rpId, origins]
     */
    public static function relyingParty(): array
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $hostNoPort = (string)preg_replace('/:\d+$/', '', $host);
        $lower = $hostNoPort;
        $isLocal = $lower === 'localhost' || $lower === '127.0.0.1' || $lower === '::1' || str_ends_with($lower, '.localhost');
        // 非 localhost 一律按 https：WebAuthn 浏览器层强制安全上下文，能走到仪式就必然是 https。
        // 站点若在反向代理/内网穿透之后，服务端看到的可能是内网的 http，不能据此把来源判成 http。
        $secure = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $scheme = $isLocal ? ($secure ? 'https' : 'http') : 'https';
        return [$hostNoPort, [$scheme . '://' . $host]];
    }

    /* ------------------------------------------------------------- 注册 (create) */

    /**
     * 校验 navigator.credentials.create 的回传。
     *
     * @param string   $attestationObjectB64u attestationObject（base64url）
     * @param string   $clientDataJSONB64u    clientDataJSON（base64url）
     * @param string   $expectedChallengeB64u 发起时下发、存于会话的挑战
     * @param string   $rpId                  依赖方 ID（主机名）
     * @param string[] $allowedOrigins        允许的来源清单
     * @return array{credentialId:string,publicKeyPem:string,signCount:int,aaguid:string}
     * @throws \Exception 校验失败
     */
    public static function verifyRegistration(
        string $attestationObjectB64u,
        string $clientDataJSONB64u,
        string $expectedChallengeB64u,
        string $rpId,
        array $allowedOrigins
    ): array {
        $clientDataRaw = self::b64uDecode($clientDataJSONB64u);
        self::verifyClientData($clientDataRaw, 'webauthn.create', $expectedChallengeB64u, $rpId, $allowedOrigins);

        $attestation = self::cborDecode(self::b64uDecode($attestationObjectB64u));
        if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
            throw new \Exception('attestation 格式错误');
        }

        $parsed = self::parseAuthData($attestation['authData']);
        if (!hash_equals(hash('sha256', $rpId, true), $parsed['rpIdHash'])) {
            throw new \Exception('rpId 不匹配');
        }
        if (!($parsed['flags'] & 0x01)) {
            throw new \Exception('用户未在场（UP 标志未设置）');
        }
        if (!$parsed['hasCredential']) {
            throw new \Exception('缺少凭证公钥数据');
        }

        return [
            'credentialId' => self::b64uEncode($parsed['credentialId']),
            'publicKeyPem' => self::coseToPem($parsed['cose']),
            'signCount'    => $parsed['signCount'],
            'aaguid'       => bin2hex($parsed['aaguid']),
        ];
    }

    /* ---------------------------------------------------------------- 断言 (get) */

    /**
     * 校验 navigator.credentials.get 的回传（登入 / 解锁）。
     *
     * @return int 新的 signCount，呼叫端应存回。
     * @throws \Exception 校验失败
     */
    public static function verifyAssertion(
        string $authenticatorDataB64u,
        string $clientDataJSONB64u,
        string $signatureB64u,
        string $publicKeyPem,
        string $expectedChallengeB64u,
        string $rpId,
        array $allowedOrigins,
        int $storedSignCount
    ): int {
        return self::verifyAssertionResult(
            $authenticatorDataB64u,
            $clientDataJSONB64u,
            $signatureB64u,
            $publicKeyPem,
            $expectedChallengeB64u,
            $rpId,
            $allowedOrigins,
            $storedSignCount
        )['signCount'];
    }

    /**
     * 同 verifyAssertion，另外回传本次断言是否做了用户验证（UV 标志 0x04：指纹 / 面容 / PIN）。
     * UV 位在 authData 里、受签名保护，中间人无法伪造。
     *
     * @return array{signCount:int, userVerified:bool}
     * @throws \Exception 校验失败
     */
    public static function verifyAssertionResult(
        string $authenticatorDataB64u,
        string $clientDataJSONB64u,
        string $signatureB64u,
        string $publicKeyPem,
        string $expectedChallengeB64u,
        string $rpId,
        array $allowedOrigins,
        int $storedSignCount
    ): array {
        $clientDataRaw = self::b64uDecode($clientDataJSONB64u);
        self::verifyClientData($clientDataRaw, 'webauthn.get', $expectedChallengeB64u, $rpId, $allowedOrigins);

        $authData = self::b64uDecode($authenticatorDataB64u);
        // 断言本不该携带「已认证凭证数据」(AT flag)，因此不解析 COSE、根本不进 CBOR——
        // 既符合规范，也把自研 CBOR 解析器彻底移出「验签之前、攻击者可控」的路径。
        $parsed = self::parseAuthData($authData, false);
        if (!hash_equals(hash('sha256', $rpId, true), $parsed['rpIdHash'])) {
            throw new \Exception('rpId 不匹配');
        }
        if (!($parsed['flags'] & 0x01)) {
            throw new \Exception('用户未在场（UP 标志未设置）');
        }

        $signedData = $authData . hash('sha256', $clientDataRaw, true);
        $signature = self::b64uDecode($signatureB64u);
        // WebAuthn 的 ES256 断言签名已是 ASN.1/DER，可直接交给 openssl_verify。
        $result = openssl_verify($signedData, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new \Exception('签名校验失败');
        }

        // signCount 递增检查：两者皆 0 视为认证器不支援计数，直接放行。
        $newCount = $parsed['signCount'];
        if (($newCount !== 0 || $storedSignCount !== 0) && $newCount <= $storedSignCount) {
            throw new \Exception('签名计数未递增（疑似凭证被复制）');
        }
        return [
            'signCount' => $newCount,
            'userVerified' => ($parsed['flags'] & 0x04) === 0x04,
        ];
    }

    /* ----------------------------------------------------------------- 内部工具 */

    /**
     * 校验 clientDataJSON 的 type / challenge / origin。
     */
    private static function verifyClientData(string $raw, string $expectedType, string $expectedChallengeB64u, string $rpId, array $allowedOrigins): void
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \Exception('clientDataJSON 解析失败');
        }
        if (($data['type'] ?? '') !== $expectedType) {
            throw new \Exception('ceremony 类型不符');
        }
        $gotChallenge = (string)($data['challenge'] ?? '');
        // 空挑战会让 hash_equals('','') 误判为通过——直接拒绝。注册（none attestation）没有签名，
        // challenge 是它唯一的密码学/CSRF 闸门，绝不能因会话缺失而放空比对。
        if ($expectedChallengeB64u === '' || $gotChallenge === '') {
            throw new \Exception('挑战为空');
        }
        // 两边都去掉可能的 base64url 填充再比对。
        if (!hash_equals(rtrim($expectedChallengeB64u, '='), rtrim($gotChallenge, '='))) {
            throw new \Exception('挑战不匹配');
        }
        if (!self::originAcceptable((string)($data['origin'] ?? ''), $rpId, $allowedOrigins)) {
            throw new \Exception('来源不匹配');
        }
    }

    /**
     * 来源校验。安全边界主要靠 rpId（浏览器只对匹配 rpId 的域名产生断言，服务端也校验了
     * authData 里的 rpIdHash），故来源只需满足「主机名 == rpId 且为 https」即可，
     * 不死抠协定与端口——这样才容得下反向代理 / 内网穿透（服务端看到内网 http:IP:port，
     * 浏览器实际在 https:域名 上）这类部署。localhost 允许 http。
     */
    private static function originAcceptable(string $origin, string $rpId, array $allowedOrigins): bool
    {
        // 1) 精确命中预期来源（常规同源部署走这条）
        foreach ($allowedOrigins as $allowed) {
            if ($allowed !== '' && hash_equals((string)$allowed, $origin)) {
                return true;
            }
        }
        // 2) 结构校验：来源主机名对上 rpId、协定为 https（或本地 http）即可
        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);
        $rp = strtolower($rpId);
        if ($host !== $rp) {
            return false;
        }
        $isLocal = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost');
        return $scheme === 'https' || ($scheme === 'http' && $isLocal);
    }

    /**
     * 解析 authenticatorData。
     *
     * @return array{rpIdHash:string,flags:int,signCount:int,hasCredential:bool,aaguid?:string,credentialId?:string,cose?:array}
     */
    private static function parseAuthData(string $authData, bool $parseCredential = true): array
    {
        if (strlen($authData) < 37) {
            throw new \Exception('authData 过短');
        }
        $rpIdHash = substr($authData, 0, 32);
        $flags = ord($authData[32]);
        $signCount = (int)unpack('N', substr($authData, 33, 4))[1];

        $result = [
            'rpIdHash'      => $rpIdHash,
            'flags'         => $flags,
            'signCount'     => $signCount,
            'hasCredential' => false,
        ];

        // AT 标志（0x40）：带有已认证的凭证数据（注册时）。断言路径传 false，直接跳过、不进 CBOR。
        if (($flags & 0x40) && $parseCredential) {
            $offset = 37;
            if (strlen($authData) < $offset + 18) {
                throw new \Exception('凭证数据过短');
            }
            $aaguid = substr($authData, $offset, 16);
            $offset += 16;
            $credLen = (int)unpack('n', substr($authData, $offset, 2))[1];
            $offset += 2;
            if ($credLen <= 0 || strlen($authData) < $offset + $credLen) {
                throw new \Exception('凭证 ID 长度非法');
            }
            $credentialId = substr($authData, $offset, $credLen);
            $offset += $credLen;
            // 其后是 CBOR 编码的 COSE 公钥。
            $cose = self::cborDecode(substr($authData, $offset));
            if (!is_array($cose)) {
                throw new \Exception('COSE 公钥解析失败');
            }
            $result['aaguid'] = $aaguid;
            $result['credentialId'] = $credentialId;
            $result['cose'] = $cose;
            $result['hasCredential'] = true;
        }

        return $result;
    }

    /**
     * COSE 公钥 → PEM（SubjectPublicKeyInfo）。支援 EC2 P-256 与 RSA。
     */
    private static function coseToPem(array $cose): string
    {
        $kty = $cose[1] ?? null;

        if ($kty === 2) { // EC2
            if (($cose[-1] ?? null) !== 1) {
                throw new \Exception('仅支援 P-256（COSE crv=1）曲线');
            }
            $x = (string)($cose[-2] ?? '');
            $y = (string)($cose[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \Exception('EC 公钥坐标长度错误');
            }
            // P-256 SubjectPublicKeyInfo 固定前缀 + 未压缩点(0x04 || X || Y)。
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return self::derToPem($der);
        }

        if ($kty === 3) { // RSA
            $n = (string)($cose[-1] ?? '');
            $e = (string)($cose[-2] ?? '');
            if ($n === '' || $e === '') {
                throw new \Exception('RSA 公钥参数缺失');
            }
            return self::derToPem(self::rsaSpkiDer($n, $e));
        }

        throw new \Exception('不支援的公钥类型（COSE kty=' . var_export($kty, true) . '）');
    }

    private static function derToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /* --------------------------------------------------------- 极简 DER 编码(RSA) */

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xff) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes; // 确保为正数
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function rsaSpkiDer(string $n, string $e): string
    {
        $rsaPublicKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $bitString = "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $algId = self::derSequence(hex2bin('06092a864886f70d0101010500')); // OID rsaEncryption + NULL
        return self::derSequence($algId . $bitString);
    }

    /* --------------------------------------------------------------- 极简 CBOR 解码 */

    /**
     * 解码 CBOR。仅支援 WebAuthn 会用到的子集：无号整数、负整数、
     * 字节串、文本串、阵列、映射（键可为整数或字串）。
     *
     * @return mixed
     * @throws \Exception 遇到不支援的编码
     */
    public static function cborDecode(string $data)
    {
        $pos = 0;
        $value = self::cborRead($data, $pos);
        return $value;
    }

    /**
     * @return mixed
     */
    /** 递归深度上限：WebAuthn 的 attestationObject / COSE key 结构很浅，32 层绰绰有余，可挡巢状爆栈。 */
    private const CBOR_MAX_DEPTH = 32;

    private static function cborRead(string $data, int &$pos, int $depth = 0)
    {
        if ($depth > self::CBOR_MAX_DEPTH) {
            throw new \Exception('CBOR 巢状过深');
        }
        if ($pos >= strlen($data)) {
            throw new \Exception('CBOR 读取越界');
        }
        $initial = ord($data[$pos++]);
        $major = $initial >> 5;
        $ai = $initial & 0x1f;
        $len = self::cborArgument($data, $pos, $ai); // 保证 >= 0

        switch ($major) {
            case 0: // 无号整数
                return $len;
            case 1: // 负整数
                return -1 - $len;
            case 2: // 字节串
            case 3: // 文本串
                if ($len > strlen($data) - $pos) {
                    throw new \Exception('CBOR 字串越界');
                }
                $str = substr($data, $pos, $len);
                $pos += $len;
                return $str;
            case 4: // 阵列：每个元素至少 1 字节，count 不可能超过剩余长度——借此挡住超大 count 撑爆循环
                if ($len > strlen($data) - $pos) {
                    throw new \Exception('CBOR 阵列长度非法');
                }
                $arr = [];
                for ($i = 0; $i < $len; $i++) {
                    $arr[] = self::cborRead($data, $pos, $depth + 1);
                }
                return $arr;
            case 5: // 映射：同理，键值对至少 2 字节，count 不可能超过剩余长度
                if ($len > strlen($data) - $pos) {
                    throw new \Exception('CBOR 映射长度非法');
                }
                $map = [];
                for ($i = 0; $i < $len; $i++) {
                    $key = self::cborRead($data, $pos, $depth + 1);
                    $map[$key] = self::cborRead($data, $pos, $depth + 1);
                }
                return $map;
            default:
                throw new \Exception('不支援的 CBOR 主类型 ' . $major);
        }
    }

    private static function cborArgument(string $data, int &$pos, int $ai): int
    {
        if ($ai < 24) {
            return $ai;
        }
        // ai 28-30 保留、31 为不定长——WebAuthn 用不到，一律拒绝。
        $need = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai] ?? 0;
        if ($need === 0) {
            throw new \Exception('不支援的 CBOR 长度编码');
        }
        if ($pos + $need > strlen($data)) {
            throw new \Exception('CBOR 长度字段越界');
        }
        if ($ai === 24) {
            $v = ord($data[$pos]);
        } elseif ($ai === 25) {
            $v = (int)unpack('n', substr($data, $pos, 2))[1];
        } elseif ($ai === 26) {
            $v = (int)unpack('N', substr($data, $pos, 4))[1];
        } else { // 27：8 字节长度
            $hi = (int)unpack('N', substr($data, $pos, 4))[1];
            $lo = (int)unpack('N', substr($data, $pos + 4, 4))[1];
            $v = ($hi << 32) | $lo;
        }
        $pos += $need;
        // 8 字节长度左移 32 位可能溢位成负数；负长度会让 substr 游标倒退→无限循环，直接拒绝。
        if ($v < 0) {
            throw new \Exception('CBOR 长度溢位');
        }
        return $v;
    }
}
