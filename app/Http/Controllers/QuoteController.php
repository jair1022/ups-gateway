<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\UpsRatingService;

class QuoteController extends Controller
{
    private UpsRatingService $ups;

    public function __construct(UpsRatingService $ups)
    {
        $this->ups = $ups;
    }

    /**
     * Maneja las solicitudes de cotización desde el frontend Vue.
     */
    public function quote(Request $request)
    {
        // Validar datos del request
        $validated = $request->validate([
            'origen'         => 'required|string|min:2|max:80',
            'destino'        => 'required|string|min:2|max:80',
            'peso'           => 'required|numeric|gt:0|lt:1000',
            'altura'         => 'required|numeric|gt:0|lt:500',
            'ancho'          => 'required|numeric|gt:0|lt:500',
            'largo'          => 'required|numeric|gt:0|lt:500',
            'origen_postal'  => 'nullable|string|max:16',
            'origen_pais'    => 'nullable|string|size:2',
            'destino_postal' => 'nullable|string|max:16',
            'destino_pais'   => 'nullable|string|size:2',
            'request_option' => 'nullable|in:shop,rate',
            'service_code'   => 'required_if:request_option,rate|string|min:1|max:3',
        ]);

        // Llamar al servicio de UPS Rating
        $result = $this->ups->getRates($validated);

        if (!$result['ok']) {
            return response()->json([
                'message' => 'No fue posible obtener tarifas de UPS',
                'error'   => $result['error'] ?? null,
                'corr_id' => $result['corr_id'] ?? null,
            ], 502);
        }

        return response()->json([
            'message' => 'Tarifas obtenidas',
            'rates'   => $result['rates'],
        ]);
    }
}
