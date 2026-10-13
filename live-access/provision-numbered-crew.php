<?php
/** MAPA: explicitly requested organization-issued accounts; no fleet/GPS fixtures. */
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Role;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Loaded only by the local CLI wrapper after Laravel bootstrap. Secrets arrive on stdin.
if (PHP_SAPI !== 'cli' || !isset($input) || !is_array($input)) throw new RuntimeException('Local provisioning input required.');
try {
    if (($input['marker'] ?? '') !== 'mapa-asr-numbered-crew-v1' || !is_bool($input['apply'] ?? null) || !is_bool($input['verify'] ?? null)
        || ($input['apply'] && $input['verify']) || count($input['accounts'] ?? []) !== 25) throw new RuntimeException('Unexpected provisioning request.');
    $companyId = (string) config('mapa.company_uuid');
    if ($companyId !== ($input['company'] ?? '') || $companyId !== '29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d')
        throw new RuntimeException('Company scope mismatch.');
    $out = DB::transaction(function () use ($input, $companyId) {
        $company = Company::where('uuid', $companyId)->lockForUpdate()->firstOrFail();
        if ($company->name !== 'ASR Health') throw new RuntimeException('Unexpected organization.');
        $role = Role::where('id', 'e6453a5c-d27e-405a-b1fb-0ce9bfb6ab48')->firstOrFail();
        if ($role->name !== 'Driver' || $role->guard_name !== 'sanctum' || $role->company_uuid !== null
            || $role->policies()->where('name', 'AdministratorAccess')->exists()) throw new RuntimeException('Unexpected crew role.');
        $passwords = []; $identifiers = []; $created = 0; $unchanged = 0; $rejections = 0;
        foreach ($input['accounts'] as $offset => $a) {
            $n = $offset + 1;
            if (($a['number'] ?? null) !== $n || ($a['name'] ?? '') !== 'User '.$n
                || ($a['email'] ?? '') !== 'user'.$n.'@asrhealth.in'
                || !is_string($a['password'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{24}$/D', $a['password']))
                throw new RuntimeException('Invalid numbered account input.');
            $passwords[] = $a['password'];
            foreach (['user', 'membership', 'binding'] as $key) {
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $a[$key] ?? ''))
                    throw new RuntimeException('Invalid dedicated identifier.');
                $identifiers[] = $a[$key];
            }
            $publicId = 'user_mapa_asr_crew_'.$n; $username = 'asr-health-user-'.$n;
            if (User::withTrashed()->where(function ($q) use ($a, $publicId, $username) {
                $q->whereRaw('LOWER(TRIM(email)) = ?', [$a['email']])->orWhere('public_id', $publicId)->orWhere('username', $username);
            })->where('uuid', '<>', $a['user'])->exists()) throw new RuntimeException('Login identifier collision; no existing account was changed.');
            $u = User::withTrashed()->where('uuid', $a['user'])->lockForUpdate()->first();
            if ($u) {
                if ($u->deleted_at || $u->company_uuid !== $companyId || $u->_key !== $input['marker']
                    || $u->email !== $a['email'] || $u->name !== $a['name'] || $u->public_id !== $publicId
                    || $u->username !== $username || $u->type !== 'driver' || $u->status !== 'active'
                    || !$u->email_verified_at || data_get($u->meta, 'provisioning_marker') !== $input['marker']
                    || data_get($u->meta, 'crew_number') !== $n || data_get($u->meta, 'mapa_demo') !== false
                    || data_get($u->meta, 'enrollment_status') !== 'awaiting_native_crew_assignment'
                    || data_get($u->meta, 'assigned_person_verified') !== false || !Hash::check($a['password'], $u->password))
                    throw new RuntimeException('Dedicated identity changed; no password reset was performed.');
                $unchanged++;
            } elseif ($input['apply']) {
                $u = new User(); $u->uuid = $a['user']; $u->public_id = $publicId; $u->_key = $input['marker'];
                $u->company_uuid = $companyId; $u->name = $a['name']; $u->email = $a['email'];
                $u->username = $username; $u->slug = $username; $u->type = 'driver'; $u->status = 'active';
                $u->country = 'IN'; $u->timezone = 'Asia/Kolkata';
                $u->meta = ['mapa_demo' => false, 'provisioning_marker' => $input['marker'], 'crew_number' => $n,
                    'purpose' => 'User-authorized organization-issued numbered crew login',
                    'enrollment_status' => 'awaiting_native_crew_assignment', 'assigned_person_verified' => false,
                    'verification' => 'Administrator-issued local login identifier; no mailbox verification or email sent',
                    'mailbox_created' => false];
                // Native password mutator hashes once. Suppress invitations and all model observers.
                $u->password = $a['password']; $u->email_verified_at = now(); $u->saveQuietly(); $created++;
            } else {
                if ($input['verify']) throw new RuntimeException('Dedicated account is missing; verification never creates accounts.');
                $created++;
            }
            if (CompanyUser::withTrashed()->where('user_uuid', $a['user'])->where('uuid', '<>', $a['membership'])->exists())
                throw new RuntimeException('Unexpected existing membership.');
            $m = CompanyUser::withTrashed()->where('uuid', $a['membership'])->lockForUpdate()->first();
            if ($m) {
                if ($m->deleted_at || $m->company_uuid !== $companyId || $m->user_uuid !== $a['user']
                    || $m->_key !== $input['marker'] || $m->status !== 'active' || $m->external
                    || $m->roles()->count() !== 1 || !$m->roles()->where('roles.id', $role->id)->exists()
                    || $m->policies()->count() !== 0 || $m->permissions()->count() !== 0)
                    throw new RuntimeException('Dedicated membership or role changed.');
            } elseif ($input['apply']) {
                if ($u && $u->wasRecentlyCreated !== true) throw new RuntimeException('Dedicated membership is missing.');
                $m = new CompanyUser(); $m->uuid = $a['membership']; $m->_key = $input['marker'];
                $m->company_uuid = $companyId; $m->user_uuid = $a['user']; $m->status = 'active'; $m->external = false;
                $m->saveQuietly(); CompanyUser::withoutEvents(fn() => $m->assignSingleRole($role));
            } elseif ($input['verify']) { throw new RuntimeException('Dedicated membership is missing.'); }
            if (DB::table('mapa_mobile_bindings')->where('user_uuid', $a['user'])->where('id', '<>', $a['binding'])->exists())
                throw new RuntimeException('Unexpected existing mobile binding.');
            $expected = ['id' => $a['binding'], 'company_uuid' => $companyId, 'user_uuid' => $a['user'],
                'role' => 'crew', 'driver_uuid' => null, 'place_uuid' => null, 'enabled' => 0, 'revision' => 1];
            $binding = DB::table('mapa_mobile_bindings')->where('id', $a['binding'])->lockForUpdate()->first();
            if ($binding) {
                foreach ($expected as $key => $value) if (($value === null && $binding->$key !== null)
                    || ($value !== null && (string) $binding->$key !== (string) $value)) throw new RuntimeException('Dedicated mobile enrollment changed.');
            } elseif ($input['apply']) {
                if ($u && $u->wasRecentlyCreated !== true) throw new RuntimeException('Dedicated mobile enrollment is missing.');
                DB::table('mapa_mobile_bindings')->insert([...$expected, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($input['verify']) { throw new RuntimeException('Dedicated mobile enrollment is missing.'); }
            if ($input['apply'] || $input['verify']) {
                $u->setRelation('companyUser', $m);
                if ($u->isAdmin() || $u->role?->id !== $role->id || $u->session_status !== 'active'
                    || !Hash::check($a['password'], $u->password)) throw new RuntimeException('Native crew account verification failed.');
                if (DB::table('drivers')->where('user_uuid', $u->uuid)->exists()
                    || DB::table('mapa_mobile_sessions')->where('user_uuid', $u->uuid)->exists())
                    throw new RuntimeException('Pending account already has a driver or session; review required.');
                if ($input['verify']) {
                    $request = Illuminate\Http\Request::create('/mapa/mobile/v1/login', 'POST', [], [], [],
                        ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => $a['email'], 'password' => $a['password']], JSON_THROW_ON_ERROR));
                    try {
                        (new App\MapaMobile\MobileService())->login($request);
                        throw new RuntimeException('Pending enrollment unexpectedly accepted login.');
                    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
                        if ($e->getStatusCode() !== 403) throw new RuntimeException('Unexpected staged-login boundary.');
                        $rejections++;
                    }
                    if (DB::table('mapa_mobile_sessions')->where('user_uuid', $u->uuid)->exists())
                        throw new RuntimeException('Pending login created an unexpected session.');
                }
            }
        }
        if (count(array_unique($passwords)) !== 25 || count(array_unique($identifiers)) !== 75)
            throw new RuntimeException('Account credentials and identifiers must be unique.');
        return ['ok' => true, 'mode' => $input['apply'] ? 'apply' : ($input['verify'] ? 'verify' : 'preview'), 'accounts' => 25,
            'created' => $created, 'unchanged' => $unchanged, 'role' => 'Driver', 'mobile_enabled' => false,
            'drivers_created' => 0, 'vehicles_created' => 0, 'gps_written' => 0, 'notifications_sent' => 0,
            'staged_login_rejections' => $rejections, 'sessions_created' => 0];
    });
    echo 'MAPA_RESULT:'.json_encode($out);
} catch (Throwable $e) {
    // Query exceptions can expose input values; emit only fixed, non-secret diagnostics.
    fwrite(STDERR, 'MAPA numbered account operation failed; transaction rolled back and sensitive output suppressed.'); exit(1);
}
