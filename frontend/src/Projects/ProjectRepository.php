<?php
declare(strict_types=1);

namespace App\Projects;

use App\Database\Db;
use App\Templates\Format;

final class ProjectRepository
{
    /**
     * @param array{name:string,template_id:string,format:string,content:array<string,string>,style:array<string,string>} $data
     */
    public static function create(int $userId, array $data): int
    {
        $dims = Format::dimensions($data['format']);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO projects
                (user_id, name, template_id, format, content_json, style_json, width, height, status)
             VALUES
                (:uid, :n, :tid, :fmt, :c, :s, :w, :h, :st)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':n'   => $data['name'],
            ':tid' => $data['template_id'],
            ':fmt' => $data['format'],
            ':c'   => json_encode($data['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':s'   => json_encode($data['style'],   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':w'   => $dims['w'],
            ':h'   => $dims['h'],
            ':st'  => 'draft',
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function findByIdForUser(int $projectId, int $userId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM projects WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $projectId, ':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 200));
        $offset = max(0, $offset);
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, template_id, format, status, width, height,
                    render_progress, render_message, last_render_id,
                    created_at, updated_at
               FROM projects
              WHERE user_id = :uid
           ORDER BY updated_at DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Apply a partial update. $changes may include: name, content (array),
     * style (array). Returns true if at least one row was updated.
     *
     * @param array<string,mixed> $changes
     */
    public static function update(int $projectId, int $userId, array $changes): bool
    {
        if (!$changes) return false;

        $sets = [];
        $params = [':id' => $projectId, ':uid' => $userId];
        if (array_key_exists('name', $changes)) {
            $sets[] = 'name = :n';
            $params[':n'] = (string) $changes['name'];
        }
        if (array_key_exists('content', $changes)) {
            $sets[] = 'content_json = :c';
            $params[':c'] = json_encode($changes['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('style', $changes)) {
            $sets[] = 'style_json = :s';
            $params[':s'] = json_encode($changes['style'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (!$sets) return false;

        $sql = 'UPDATE projects SET ' . implode(', ', $sets)
             . ' WHERE id = :id AND user_id = :uid';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public static function delete(int $projectId, int $userId): bool
    {
        $stmt = Db::pdo()->prepare(
            'DELETE FROM projects WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $projectId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hydrate a full row (including JSON columns) for the detail endpoint.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toApi(array $row): array
    {
        $base = self::toApiSummary($row);
        $base['content'] = self::decodeJsonObject($row['content_json'] ?? null);
        $base['style']   = self::decodeJsonObject($row['style_json']   ?? null);
        return $base;
    }

    /**
     * Summary projection used in list responses — omits content/style to
     * keep payloads small.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toApiSummary(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'name'            => (string) $row['name'],
            'template_id'     => (string) ($row['template_id'] ?? ''),
            'format'          => (string) ($row['format'] ?? ''),
            'status'          => (string) ($row['status'] ?? 'draft'),
            'width'           => (int) ($row['width']  ?? 0),
            'height'          => (int) ($row['height'] ?? 0),
            'render_progress' => (int)    ($row['render_progress'] ?? 0),
            'render_message'  => (string) ($row['render_message']  ?? ''),
            'last_render_id'  => isset($row['last_render_id']) && $row['last_render_id'] !== null
                                   ? (int) $row['last_render_id']
                                   : null,
            'created_at'      => (string) ($row['created_at'] ?? ''),
            'updated_at'      => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Decode a JSON column as an object (never a plain `[]` for empty).
     *
     * @return \stdClass|array<string,mixed>
     */
    private static function decodeJsonObject(mixed $raw): \stdClass|array
    {
        if (!is_string($raw) || $raw === '') return new \stdClass();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) return new \stdClass();
        return $decoded;
    }
}
