<?php

namespace App\Http\Controllers;

use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\Request;

class MapaFleetController extends Controller
{
    public function index(Request $request)
    {
        $token = (string) config('mapa.token', '');
        $company = (string) config('mapa.company_uuid', '');
        if (strlen($token) < 40 || !preg_match('/^[0-9a-f-]{36}$/i', $company)) {
            return response()->json(['error' => 'MAPA adapter is not configured.'], 503);
        }
        if (!hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Invalid adapter credential.'], 401);
        }
        $request->validate(['page' => 'sometimes|integer|min:1|max:11']);
        $legacy = $request->is('asr/v1/fleet');
        $page = (int) $request->input('page', 1);
        // Company scope is server configured. Neither a request nor a session
        // can widen it. Fetch 101 only to determine whether another page exists.
        $vehicles = Vehicle::where('company_uuid', $company)
            // Older consumers cannot distinguish training fixtures from real GPS.
            ->when($legacy, fn ($query) => $query->where(function ($query) {
                $query->whereNull('meta->mapa_demo')->orWhere('meta->mapa_demo', false);
            }))
            ->orderBy('uuid')->offset(($page - 1) * 100)->limit(101)->get();
        $rows = $vehicles->take(100)->map(function ($vehicle) use ($company, $legacy) {
            $position = $vehicle->positions()->where('company_uuid', $company)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            $fix = null;
            if ($position && $position->created_at && $position->coordinates) {
                $fix = [
                    'lat' => (float) $position->latitude,
                    'lng' => (float) $position->longitude,
                    // This is when Fleetbase STORED a position, not a claimed
                    // device fix time. Do not substitute vehicle.updated_at.
                    'recorded_at' => $position->created_at->getTimestamp() * 1000,
                    'time_source' => $legacy ? 'fleetbase_position_created_at' : 'mapa_position_created_at',
                    'speed_kph' => null,
                    // Upstream does not document a consistent speed unit for
                    // every tracker; leave speed unknown instead of converting.
                    'heading' => $position->heading === null ? null : (float) $position->heading,
                ];
            }
            return [
                'id' => $vehicle->public_id,
                'name' => $vehicle->name,
                'registration' => $vehicle->plate_number,
                'simulated' => (bool) data_get($vehicle->meta, 'mapa_demo', false),
                'position' => $fix,
            ];
        })->values();
        return response()->json([
            'contract' => $legacy ? 'asr-fleetbase-v1' : 'mapa-v1',
            'vehicles' => $rows,
            'has_more' => $vehicles->count() > 100,
        ])->header('Cache-Control', 'no-store');
    }
}


