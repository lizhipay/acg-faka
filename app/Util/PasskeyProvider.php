<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 依 AAGUID 识别通行密钥的提供方（iCloud 钥匙串、Google 密码管理工具、Windows Hello…），
 * 用于未命名时的默认名称与列表展示。AAGUID 在 attestation=none 下未经签名、可伪造，只能拿来显示，不能做安全判断。
 *
 * 数据是社区清单 passkeydeveloper/passkey-authenticator-aaguids 的固定快照（aaguid.json 与
 * combined_aaguid.json，2026-09-28 版），只收常见的几家。实测行为：同步型密码管理器与 Mac 版 Chrome/Edge
 * 在 none 下会给真实 AAGUID；Windows Hello、实体安全密钥、旧版 iOS、Firefox 153 之前多半给全零，
 * 认不出时回传 null，由调用方按传输方式与浏览器推断。
 */
final class PasskeyProvider
{
    /** AAGUID => [名称, 类别]。类别：sync=可同步的密码管理器，platform=系统/浏览器内置，security_key=实体安全密钥 */
    private const MAP = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => ['iCloud 钥匙串', 'sync'],
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => ['iCloud 钥匙串', 'sync'],
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => ['Google 密码管理工具', 'sync'],
        'adce0002-35bc-c60a-648b-0b25f1f05503' => ['Mac 版 Chrome', 'platform'],
        'b5397666-4885-aa6b-cebf-e52262a439a2' => ['Chromium 浏览器', 'platform'],
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => ['Windows Hello', 'platform'],
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => ['Windows Hello', 'platform'],
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => ['Windows Hello', 'platform'],
        'd3452668-01fd-4c12-926c-83a4204853aa' => ['Microsoft 密码管理器', 'sync'],
        '771b48fd-d3d4-4f74-9232-fc157ab0507a' => ['Mac 版 Edge', 'platform'],
        '53414d53-554e-4700-0000-000000000000' => ['Samsung Pass', 'sync'],
        'bada5566-a7aa-401f-bd96-45619a55120d' => ['1Password', 'sync'],
        'd548826e-79b4-db40-a3d8-11116f7e8349' => ['Bitwarden', 'sync'],
        '531126d6-e717-415c-9320-3d9aa6981239' => ['Dashlane', 'sync'],
        'b78a0a55-6ef8-d246-a042-ba0f6d55050c' => ['LastPass', 'sync'],
        '50726f74-6f6e-5061-7373-50726f746f6e' => ['Proton Pass', 'sync'],
        'b84e4048-15dc-4dd0-8640-f4f60813c8af' => ['NordPass', 'sync'],
        '0ea242b4-43c4-4a1b-8b17-dd6d0b6baec6' => ['Keeper', 'sync'],
        'f3809540-7f14-49c1-a8b3-8f813b225541' => ['Enpass', 'sync'],
        'fdb141b2-5d84-443e-8a35-4698c205a502' => ['KeePassXC', 'sync'],
        'eaecdef2-1c31-5634-8639-f1cbd9c00a08' => ['KeePassDX', 'sync'],
        'a10c6dd9-465e-4226-8198-c7c44b91c555' => ['卡巴斯基密码管理器', 'sync'],
        'fa37f553-f9b6-4adb-ac53-8bbb57ebdf0d' => ['Norton 密码管理器', 'sync'],
        'cb69481e-8ff7-4039-93ec-0a2729a154a8' => ['YubiKey 5 系列', 'security_key'],
        'ee882879-721c-4913-9775-3dfcce97072a' => ['YubiKey 5 系列', 'security_key'],
        '19083c3d-8383-4b18-bc03-8f1c9ab2fd1b' => ['YubiKey 5 系列', 'security_key'],
        'ff4dac45-ede8-4ec2-aced-cf66103f4335' => ['YubiKey 5 系列', 'security_key'],
        'fa2b99dc-9e39-4257-8f92-4a30d23c4118' => ['YubiKey 5 NFC 系列', 'security_key'],
        '2fc0579f-8113-47ea-b116-bb5a8db9202a' => ['YubiKey 5 NFC 系列', 'security_key'],
        'd7781e5d-e353-46aa-afe2-3ca49f13332a' => ['YubiKey 5 NFC 系列', 'security_key'],
        'a25342c0-3cdc-4414-8e46-f4807fca511c' => ['YubiKey 5 NFC 系列', 'security_key'],
        'f8a011f3-8c0a-4d15-8006-17111f9edc7d' => ['Yubico 安全密钥', 'security_key'],
        'b92c3f9a-c014-4056-887f-140a2501163b' => ['Yubico 安全密钥', 'security_key'],
        'b7d3f68e-88a6-471e-9ecf-2df26d041ede' => ['Yubico NFC 安全密钥', 'security_key'],
        'a4e9fc6d-4cbe-4758-b8ba-37598bb5bbaa' => ['Yubico NFC 安全密钥', 'security_key'],
        'e77e3c64-05e3-428b-8824-0cbeb04b829d' => ['Yubico NFC 安全密钥', 'security_key'],
        '42b4fb4a-2866-43b2-9bf7-6c6669c2e5d3' => ['Google Titan 安全密钥', 'security_key'],
        '833b721a-ff5f-4d00-bb2e-bdda3ec01e29' => ['飞天 ePass FIDO2', 'security_key'],
        'ee041bce-25e5-4cdb-8f86-897fd6418464' => ['飞天 ePass FIDO2-NFC', 'security_key'],
    ];

    /**
     * @return array{name:string, kind:string}|null
     */
    public static function describe(string $aaguid): ?array
    {
        $key = strtolower(trim($aaguid));
        if (preg_match('/^[0-9a-f]{32}$/', $key)) {
            $key = substr($key, 0, 8) . '-' . substr($key, 8, 4) . '-' . substr($key, 12, 4) . '-' . substr($key, 16, 4) . '-' . substr($key, 20);
        }
        $hit = self::MAP[$key] ?? null;
        return $hit ? ['name' => $hit[0], 'kind' => $hit[1]] : null;
    }
}
