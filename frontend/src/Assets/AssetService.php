<?php
declare(strict_types=1);

namespace App\Assets;

use App\Projects\ProjectRepository;
use App\Render\ProjectMaterializer;
use App\Support\Env;
use App\Templates\TemplateRegistry;
use RuntimeException;

/**
 * Uploads an asset for a given project/role. R.8 v1 limits:
 *   - Images only (png, jpeg, webp, svg — svg only where the template allows).
 *   - Max 5 MB per file.
 *   - Role must be declared by the project's template meta.
 *
 * Files are written to:
 *   <PROJECTS_DIR>/<project_id>/assets/<role>.<ext>
 * with atomic rename. A DB row per (project_id, role) is kept in sync via
 * {@see AssetRepository::upsert()}.
 */
final class AssetService
{
    public const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /** MIME → safe extension. Keep whitelist tight. */
    private const MIME_MAP = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];

    /**
     * Handle a PHP $_FILES-style upload entry. Returns the API projection
     * of the stored asset.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array<string,mixed>
     *
     * @throws AssetUploadException on any validation/IO failure.
     */
    public static function store(int $userId, int $projectId, string $role, array $file): array
    {
        $project = ProjectRepository::findByIdForUser($projectId, $userId);
        if (!$project) {
            throw new AssetUploadException('project_not_found', 404);
        }

        $template = TemplateRegistry::get((string) $project['template_id']);
        if (!$template) {
            throw new AssetUploadException('template_not_found', 500);
        }

        $assetDef = self::findAssetDef($template, $role);
        if (!$assetDef) {
            throw new AssetUploadException('unknown_role', 422);
        }

        // --- Validate upload status -------------------------------------------
        $err = (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE);
        if ($err !== \UPLOAD_ERR_OK) {
            throw new AssetUploadException(self::phpUploadErrMsg($err), 400);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new AssetUploadException('no_upload', 400);
        }

        $size = (int) ($file['size'] ?? filesize($tmp) ?: 0);
        if ($size <= 0) {
            throw new AssetUploadException('empty_file', 400);
        }
        if ($size > self::MAX_BYTES) {
            throw new AssetUploadException('file_too_large', 413);
        }

        // --- Detect real MIME (never trust client) -----------------------------
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp) ?: '';
        if (!isset(self::MIME_MAP[$mime])) {
            throw new AssetUploadException('unsupported_mime', 415, ['mime' => $mime]);
        }

        // Honour per-asset accept list declared by the template.
        $accept = array_filter(array_map('trim', explode(',', (string) ($assetDef['accept'] ?? ''))));
        if ($accept && !in_array($mime, $accept, true)) {
            throw new AssetUploadException('mime_not_allowed_for_role', 415, [
                'mime'  => $mime,
                'allow' => array_values($accept),
            ]);
        }

        $ext = self::MIME_MAP[$mime];

        // --- Read dimensions when possible -------------------------------------
        $width = null;
        $height = null;
        if ($mime !== 'image/svg+xml') {
            $info = @getimagesize($tmp);
            if (is_array($info)) {
                $width  = (int) $info[0];
                $height = (int) $info[1];
            }
        }

        // --- Paths -------------------------------------------------------------
        $projectDir = ProjectMaterializer::projectDir($projectId);
        $assetsDir  = $projectDir . '/assets';
        self::mkdirp($assetsDir);

        // Remove any previous files for this role (e.g. old logo.jpg when
        // replacing with a png). Safe: limited to the project's own dir.
        foreach (glob($assetsDir . '/' . $role . '.*') ?: [] as $old) {
            if (is_file($old)) @unlink($old);
        }

        $destBasename = $role . '.' . $ext;
        $destPath     = $assetsDir . '/' . $destBasename;

        // Compute sha256 before moving (tmp is readable, dest might not be after
        // move depending on server; doing it here is simplest).
        $sha256 = hash_file('sha256', $tmp) ?: '';

        // Atomic-ish: copy to .tmp then rename.
        $tmpDest = $destPath . '.tmp';
        if (!@move_uploaded_file($tmp, $tmpDest)) {
            throw new AssetUploadException('move_failed', 500);
        }
        if (!@rename($tmpDest, $destPath)) {
            @unlink($tmpDest);
            throw new AssetUploadException('rename_failed', 500);
        }
        @chmod($destPath, 0644);

        // --- Persist DB row -----------------------------------------------------
        $dataDir = rtrim((string) Env::get('DATA_DIR', '/home/dvdgp/data/videos'), '/');
        $relPath = ltrim(str_replace($dataDir, '', $destPath), '/');

        AssetRepository::upsert($userId, $projectId, $role, [
            'kind'          => 'image',
            'original_name' => self::sanitiseFilename((string) ($file['name'] ?? $destBasename)),
            'storage_path'  => $relPath,
            'mime'          => $mime,
            'size_bytes'    => $size,
            'sha256'        => $sha256,
            'width'         => $width,
            'height'        => $height,
        ]);

        $row = AssetRepository::findByRole($projectId, $userId, $role);
        return $row ? AssetRepository::toApi($row) : [];
    }

    /**
     * Delete the asset for (project, role). Returns true if something was
     * deleted (either file, DB row, or both).
     */
    public static function delete(int $userId, int $projectId, string $role): bool
    {
        $project = ProjectRepository::findByIdForUser($projectId, $userId);
        if (!$project) {
            throw new AssetUploadException('project_not_found', 404);
        }

        $dbDeleted = AssetRepository::deleteByRole($projectId, $userId, $role);

        $assetsDir = ProjectMaterializer::projectDir($projectId) . '/assets';
        $fileDeleted = false;
        foreach (glob($assetsDir . '/' . $role . '.*') ?: [] as $old) {
            if (is_file($old) && @unlink($old)) {
                $fileDeleted = true;
            }
        }

        return $dbDeleted || $fileDeleted;
    }

    /** @return array<string,mixed>|null */
    private static function findAssetDef(array $template, string $role): ?array
    {
        foreach ((array) ($template['assets'] ?? []) as $def) {
            $k = (string) ($def['role'] ?? ($def['key'] ?? ''));
            if ($k === $role) return $def;
        }
        return null;
    }

    private static function mkdirp(string $dir): void
    {
        if (is_dir($dir)) return;
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: $dir");
        }
    }

    private static function sanitiseFilename(string $name): string
    {
        $name = basename($name);
        // Strip control chars and collapse long filenames.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if (mb_strlen($name) > 240) {
            $name = mb_substr($name, 0, 240);
        }
        return $name !== '' ? $name : 'upload';
    }

    private static function phpUploadErrMsg(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'file_too_large',
            \UPLOAD_ERR_PARTIAL                         => 'partial_upload',
            \UPLOAD_ERR_NO_FILE                         => 'no_file',
            \UPLOAD_ERR_NO_TMP_DIR                      => 'server_no_tmp',
            \UPLOAD_ERR_CANT_WRITE                      => 'server_cant_write',
            \UPLOAD_ERR_EXTENSION                       => 'server_extension_blocked',
            default                                     => 'upload_error',
        };
    }
}
