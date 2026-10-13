<?php
namespace App\MapaMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Fleetbase\Models\Setting;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Controller
{
    private function respond(callable $fn) {
        try { return response()->json($fn())->header('Cache-Control', 'no-store'); }
        catch (\Illuminate\Validation\ValidationException $e) { return response()->json(['error' => 'Invalid maintenance request.'], 422)->header('Cache-Control', 'no-store'); }
        catch (HttpExceptionInterface $e) { return response()->json(['error' => $e->getMessage()], $e->getStatusCode())->header('Cache-Control', 'no-store'); }
        catch (\Throwable $e) { return response()->json(['error' => 'MAPA maintenance unavailable. Check deployment health.'], 503)->header('Cache-Control', 'no-store'); }
    }
    public function diagnostics() { return $this->respond(function () { $a = Access::staff(); return app(Diagnostics::class)->collect($a['company']); }); }
    public function discover() { return $this->respond(function () { Access::platform(); return app(ModelCatalog::class)->discover(Setting::system('ai', [])); }); }
    public function history() { return $this->respond(function () { $a = Access::staff(); return ['runs' => DB::table('mapa_maintenance_runs')->where('company_uuid', $a['company'])->orderByDesc('created_at')->limit(50)->get(['id','action','status','result','created_at','updated_at'])]; }); }
    public function preview(Request $r) { return $this->respond(function () use ($r) { $a = Access::staff(); $v = $r->validate(['action' => 'required|string|max:64']); return app(Repairs::class)->preview($a, $v['action']); }); }
    public function apply(Request $r, string $id) { return $this->respond(function () use ($r, $id) {
        $a = Access::staff(); abort_unless($r->input('confirm') === true, 422, 'Explicit repair confirmation is required.'); return app(Repairs::class)->apply($a, $id);
    }); }
}
