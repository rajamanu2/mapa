<?php
namespace App\MapaMaintenance;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Models\CompanyUser;
use Illuminate\Support\Facades\DB;

class Access
{
    public static function staff(?AiTask $task = null): array
    {
        abort_unless(config('mapa_maintenance.enabled', false), 503, 'MAPA maintenance is disabled.');
        $user = request()->user();
        $company = session('company');
        abort_unless($user && $user->status === 'active' && is_string($company) && $company !== '', 403, 'Active MAPA access is required.');
        abort_unless(CompanyUser::where('company_uuid', $company)->where('user_uuid', $user->uuid)->where('status', 'active')->exists(), 403, 'Active MAPA membership is required.');
        $binding = DB::table('mapa_mobile_bindings')->where('company_uuid', $company)->where('user_uuid', $user->uuid)->where('enabled', true)->first();
        abort_unless($binding && in_array($binding->role, ['admin', 'superadmin'], true), 403, 'MAPA administrator access is required.');
        if ($task) abort_unless($task->company_uuid === $company && $task->created_by_uuid === $user->uuid, 403, 'Task ownership changed.');
        return ['company' => $company, 'user' => $user->uuid];
    }

    public static function platform(): array
    {
        $actor = self::staff();
        abort_unless(request()->user()->type === 'admin' && in_array($actor['user'], config('mapa_mobile.superadmin_user_uuids', []), true), 403, 'MAPA platform administrator access is required.');
        return $actor;
    }
}
