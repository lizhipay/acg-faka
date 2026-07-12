<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Manage;
use App\Model\User;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Ticket::class)]
interface Ticket
{
    public function ready(): bool;

    public function userData(User $user, array $filter): array;

    public function adminData(array $filter): array;

    public function userDetail(User $user, int $id, int $limit = 30): array;

    public function adminDetail(Manage $manage, int $id, int $limit = 30): array;

    public function userMessages(User $user, int $id, int $afterId = 0, int $beforeId = 0, int $limit = 50): array;

    public function adminMessages(Manage $manage, int $id, int $afterId = 0, int $beforeId = 0, int $limit = 50): array;

    public function commodityOptions(User $user, array $filter): array;

    public function orderOptions(User $user, array $filter): array;

    public function create(User $user, array $map): array;

    public function userReply(User $user, int $id, string $content): array;

    public function adminReply(Manage $manage, int $id, string $content, string $mode): array;

    public function close(Manage $manage, int $id): array;

    public function userBadge(User $user): array;

    public function adminBadge(): array;

    public function upload(?User $user, ?Manage $manage, array $file): array;
}
