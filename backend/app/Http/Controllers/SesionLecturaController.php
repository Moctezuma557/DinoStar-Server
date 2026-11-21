<?php

namespace App\Http\Controllers;

use App\Http\Resources\LecturaResource;
use App\Models\Sesion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SesionLecturaController extends Controller
{
    public function index(string $id): AnonymousResourceCollection
    {
        $session = Sesion::query()->find($id);
        abort_if($session === null, 404, 'Sesión no encontrada.');

        // History remains available for paused and completed sessions as well.
        $readings = $session->lecturas()
            ->orderBy('timestamp_dispositivo')->orderBy('id')->get();

        return LecturaResource::collection($readings);
    }
}
