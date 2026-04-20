<?php
declare(strict_types=1);

namespace App\Assets;

use App\Database\Db;

final class AssetRepository
{
    /**
     * @return array<int, array<string,mixed>>
     */
    public static function listForProject(int $projectId, int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, role, kind, original_name, storage_path, mime, size_bytes,
                    sha256, width, height, created_at
               FROM assets
              WHERE project_id = :pid AND user_id = :uid
              ORDER BY role ASC'
        );
        $stmt->execute([':pid' => $projectId, ':uid' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public static function findByRole(int $projectId, int $userId, string $role): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM assets
              WHERE project_id = :pid AND user_id = :uid AND role = :r
              LIMIT 1'
        );
        $stmt->execute([':pid' => $projectId, ':uid' => $userId, ':r' => $role]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function deleteByRole(int $projectId, int $userId, string $role): bool
    {
        $stmt = Db::pdo()->prepare(
            'DELETE FROM assets
              WHERE project_id = :pid AND user_id = :uid AND role = :r'
        );
        $stmt->execute([':pid' => $projectId, ':uid' => $userId, ':r' => $role]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Replace (INSERT … ON DUPLICATE KEY UPDATE) the asset for the given role.
     *
     * @param array{kind:string,original_name:string,storage_path:string,mime:string,size_bytes:int,sha256:string,width:?int,height:?int} $data
     */
    public static function upsert(int $userId, int $projectId, string $role, array $data): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO assets
                (user_id, project_id, role, kind, original_name, storage_path,
                 mime, size_bytes, sha256, width, height)
             VALUES
                (:uid, :pid, :r, :k, :on, :sp, :m, :sz, :sha, :w, :h)
             ON DUPLICATE KEY UPDATE
                kind = VALUES(kind),
                original_name = VALUES(original_name),
                storage_path = VALUES(storage_path),
                mime = VALUES(mime),
                size_bytes = VALUES(size_bytes),
                sha256 = VALUES(sha256),
                width = VALUES(width),
                height = VALUES(height)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':pid' => $projectId,
            ':r'   => $role,
            ':k'   => $data['kind'],
            ':on'  => $data['original_name'],
            ':sp'  => $data['storage_path'],
            ':m'   => $data['mime'],
            ':sz'  => $data['size_bytes'],
            ':sha' => $data['sha256'],
            ':w'   => $data['width'],
            ':h'   => $data['height'],
        ]);
        // Prefer the affected row id; if 0 (update path), look it up.
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            $row = self::findByRole($projectId, $userId, $role);
            $id  = $row ? (int) $row['id'] : 0;
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toApi(array $row): array
    {
        $storagePath = (string) ($row['storage_path'] ?? '');
        // Public URL served by nginx (see deploy/nginx/*.conf_storage).
        // storage_path is relative to /home/dvdgp/data/videos (e.g.
        //   "projects/42/assets/logo.png"). We expose it under /storage/…
        return [
            'id'            => (int) $row['id'],
            'role'          => (string) ($row['role'] ?? ''),
            'kind'          => (string) ($row['kind'] ?? ''),
            'original_name' => (string) ($row['original_name'] ?? ''),
            'mime'          => (string) ($row['mime'] ?? ''),
            'size_bytes'    => (int) ($row['size_bytes'] ?? 0),
            'width'         => isset($row['width'])  && $row['width']  !== null ? (int) $row['width']  : null,
            'height'        => isset($row['height']) && $row['height'] !== null ? (int) $row['height'] : null,
            'url'           => '/storage/' . ltrim($storagePath, '/'),
            'created_at'    => (string) ($row['created_at'] ?? ''),
        ];
    }
}
