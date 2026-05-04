<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackupService;
use Illuminate\Http\Request;

class DatabaseBackupController extends Controller
{
    /** @var DatabaseBackupService */
    protected $backupService;

    public function __construct(DatabaseBackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    /**
     * Whether the current user may manage database backups (business Admin only).
     *
     * @return bool
     */
    protected function userCanBackup()
    {
        if (!auth()->check()) {
            return false;
        }
        if (!auth()->user()->can('business_settings.access')) {
            return false;
        }
        $businessId = request()->session()->get('user.business_id');
        if (empty($businessId)) {
            return false;
        }

        return auth()->user()->hasRole('Admin#' . $businessId);
    }

    /**
     * JSON list of backup files for the current business.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        if (!$this->userCanBackup()) {
            abort(403, 'Unauthorized action.');
        }

        $businessId = (int) request()->session()->get('user.business_id');

        return response()->json([
            'success' => true,
            'backups' => $this->backupService->listBackups($businessId),
        ]);
    }

    /**
     * Create a new SQL dump for the configured database.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        if (!$this->userCanBackup()) {
            abort(403, 'Unauthorized action.');
        }

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        $businessId = (int) $request->session()->get('user.business_id');

        $result = $this->backupService->createBackup($businessId);

        if (empty($result['success'])) {
            return response()->json([
                'success' => false,
                'msg' => $result['message'] ?? 'Backup failed.',
            ], 422);
        }

        $downloadUrl = action('DatabaseBackupController@download', ['file' => $result['filename']]);

        return response()->json([
            'success' => true,
            'msg' => __('business.database_backup_created'),
            'filename' => $result['filename'],
            'size' => $result['size'] ?? 0,
            'download_url' => $downloadUrl,
        ]);
    }

    /**
     * Download a backup file created for this business.
     *
     * @param string $file
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
     */
    public function download($file)
    {
        if (!$this->userCanBackup()) {
            abort(403, 'Unauthorized action.');
        }

        $businessId = (int) request()->session()->get('user.business_id');
        $path = $this->backupService->resolveDownloadPath($businessId, $file);

        if ($path === null) {
            abort(404, 'Backup file not found.');
        }

        return response()->download($path, basename($path));
    }
}
