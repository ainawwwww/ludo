<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * GET /api/v1/countries
     * GET /api/v1/countries?search=pak
     * 
     * Returns list of all countries with phone patterns.
     * Supports search by country name, code, or dial code.
     * 
     * Query Parameters:
     *   search (optional) - Search term to filter countries by name, code, or dial code
     * 
     * Success Response (200 OK):
     * {
     *   "status": "success",
     *   "data": [
     *     {
     *       "name": "Pakistan",
     *       "code": "PK",
     *       "dial_code": "+92",
     *       "phone_pattern": "^3[0-9]{9}$",
     *       "phone_length": 10,
     *       "phone_placeholder": "3XX XXXXXXX",
     *       "flag": "🇵🇰"
     *     }
     *   ],
     *   "total": 1
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $countries = config('countries');
        $search = $request->query('search');

        if (!empty($search)) {
            $search = strtolower(trim($search));

            $countries = array_values(array_filter($countries, function ($country) use ($search) {
                return str_contains(strtolower($country['name']), $search)
                    || str_contains(strtolower($country['code']), $search)
                    || str_contains($country['dial_code'], $search);
            }));
        }

        return response()->json([
            'status' => 'success',
            'data' => $countries,
            'total' => count($countries),
        ]);
    }
}
