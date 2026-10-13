<?php
namespace App\MapaMaintenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Repairs
{
    public const ACTIONS = ['recover_stuck_ai_tasks', 'revoke_expired_sessions'];

    private function candidates(string $action, string $company, int $cutoff) {
        if ($action === 'recover_stuck_ai_tasks') return DB::table('ai_tasks')->where('company_uuid', $company)->where('status', 'running')->whereNull('deleted_at')->where('updated_at', '<', date('Y-m-d H:i:s', $cutoff));
        abort_unless($action === 'revoke_expired_sessions', 422, 'Unsupported MAPA repair.');
        return DB::table('mapa_mobile_sessions')->where('company_uuid', $company)->whereNull('revoked_at')->where('expires_at', '<=', $cutoff);
    }

    public function preview(array $actor, string $action): array
    {
        abort_unless(in_array($action, self::ACTIONS, true), 422, 'Unsupported MAPA repair.');
        $cutoff = $action === 'recover_stuck_ai_tasks' ? time()-900 : (int) floor(microtime(true)*1000);
        $key = $action === 'recover_stuck_ai_tasks' ? 'uuid' : 'token_hash';
        $targets = $this->candidates($action, $actor['company'], $cutoff)->orderBy($key)->limit(100)->get([$key, 'updated_at'])->map(fn ($v) => (array) $v)->all();
        $id = (string) Str::uuid(); $expires = time()+300;
        DB::table('mapa_maintenance_runs')->insert(['id' => $id, 'company_uuid' => $actor['company'], 'user_uuid' => $actor['user'],
            'action' => $action, 'status' => 'preview', 'snapshot' => json_encode(compact('targets', 'cutoff', 'key'), JSON_THROW_ON_ERROR),
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        return ['run_id' => $id, 'action' => $action, 'affected_count' => count($targets), 'expires_at' => $expires,
            'requires_confirmation' => true, 'description' => $action === 'recover_stuck_ai_tasks'
                ? 'Mark at most 100 AI tasks idle for over 15 minutes as failed. Preserve their history. No task is rerun.'
                : 'Mark at most 100 already-expired mobile sessions as revoked. Active sessions are unchanged.'];
    }

    public function apply(array $actor, string $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            $run = DB::table('mapa_maintenance_runs')->where('id', $id)->where('company_uuid', $actor['company'])->where('user_uuid', $actor['user'])->lockForUpdate()->first();
            abort_unless($run, 404, 'Repair preview not found.');
            if ($run->status === 'completed') return [...json_decode($run->result, true, 512, JSON_THROW_ON_ERROR), 'replayed' => true];
            abort_unless($run->status === 'preview' && $run->expires_at > time(), 409, 'Repair preview expired. Run diagnostics again.');
            $snapshot = json_decode($run->snapshot, true, 512, JSON_THROW_ON_ERROR); $changed = 0; $skipped = 0;
            foreach ($snapshot['targets'] as $target) {
                $q = $this->candidates($run->action, $actor['company'], $snapshot['cutoff'])->where($snapshot['key'], $target[$snapshot['key']])->where('updated_at', $target['updated_at']);
                $row = $q->lockForUpdate()->first();
                if (!$row) { $skipped++; continue; }
                $values = $run->action === 'recover_stuck_ai_tasks' ? ['status' => 'failed', 'completed_at' => now(), 'updated_at' => now()]
                    : ['revoked_at' => (int) floor(microtime(true)*1000), 'updated_at' => now()];
                $n = $q->update($values); $changed += $n;
                if ($this->candidates($run->action, $actor['company'], $snapshot['cutoff'])->where($snapshot['key'], $target[$snapshot['key']])->exists()) throw new \RuntimeException('Repair verification failed.');
            }
            $result = ['run_id' => $id, 'action' => $run->action, 'changed' => $changed, 'skipped_changed_records' => $skipped, 'verified' => true,
                'message' => "Verified maintenance: $changed records changed; $skipped records skipped because they changed after preview.",
                'note' => 'Only the recorded maintenance targets were processed. This does not certify overall app health.'];
            DB::table('mapa_maintenance_runs')->where('id', $id)->update(['status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            return $result;
        });
    }
}
