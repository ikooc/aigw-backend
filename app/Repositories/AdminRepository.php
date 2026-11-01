<?php
/**
 * 管理台账号仓储：登录校验、密码修改、登录痕迹
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class AdminRepository
{
    private const TABLE = 'aigw_admin';

    public function __construct(private Database $db) {}

    public function findByUsername(string $username): ?array
    {
        return $this->db->table(self::TABLE)->where('username', $username)->first();
    }

    public function find(int $id): ?array
    {
        return $this->db->table(self::TABLE)->where('id', $id)->first();
    }

    /**
     * 校验账号密码，成功返回记录，失败返回 null
     */
    public function attempt(string $username, string $password): ?array
    {
        $admin = $this->findByUsername($username);
        if ($admin === null) {
            return null;
        }

        if (!password_verify($password, (string) $admin['password'])) {
            return null;
        }

        return $admin;
    }

    public function updatePassword(int $id, string $plainPassword): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'password' => password_hash($plainPassword, PASSWORD_DEFAULT),
        ]);
    }

    public function touchLogin(int $id, string $ip): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);
    }

    public function updateProfile(int $id, string $nickname): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update(['nickname' => $nickname]);
    }
}