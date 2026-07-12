<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Manage;
use App\Model\User;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Message::class)]
interface Message
{
    public function ready(): bool;

    public function emailAvailable(): bool;

    /**
     * @param array{source?: string, send_email?: bool|int|string} $options
     */
    public function sendToUser(
        int $userId,
        string $title,
        string $content,
        ?string $jumpUrl = null,
        array $options = []
    ): array;

    public function adminData(array $filter): array;

    public function adminDetail(int $id): array;

    public function save(Manage $manage, array $map): array;

    public function adminDelete(Manage $manage, array $ids): array;

    public function users(array $filter): array;

    public function audienceCount(int $audienceType, int $audienceId = 0): array;

    public function upload(Manage $manage, array $file): array;

    public function recent(User $user): array;

    public function userData(User $user, array $filter): array;

    public function userDetail(User $user, int $id): array;

    public function userDelete(User $user, array $ids): array;

    public function userClear(User $user): array;
}
