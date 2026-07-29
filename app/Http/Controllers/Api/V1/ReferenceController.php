<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\ShowRegionsRequest;
use App\Http\Resources\Api\V1\Reference\CityResource;
use App\Http\Resources\Api\V1\Reference\CountryResource;
use App\Http\Resources\Api\V1\Reference\PublicSettingsResource;
use App\Http\Resources\Api\V1\Reference\RegionResource;
use App\Services\Reference\ReferenceService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class ReferenceController extends Controller
{
    public function __construct(
        private readonly ReferenceService $reference,
    ) {}

    #[OA\Get(
        path: '/api/v1/countries',
        operationId: 'listCountries',
        description: 'Returns the 6 active GCC countries supported by PWT, with bilingual names resolved to the locale carried by the `Accept-Language` header.',
        summary: 'List GCC countries',
        security: [],
        tags: ['Reference'],
        parameters: [
            new OA\Parameter(
                name: 'Accept-Language',
                in: 'header',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CountryCollectionResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Server error',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function countries(): JsonResponse
    {
        $countries = $this->reference->listActiveCountries();

        return ApiResponse::success(
            CountryResource::collection($countries),
            __('reference.countries_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/countries/{code}/regions',
        operationId: 'listRegions',
        description: 'Returns active regions / emirates / governorates for the given country. The `{code}` parameter is the ISO 3166-1 alpha-2 code, case-insensitive (normalised server-side to lowercase).',
        summary: 'List regions for a country',
        security: [],
        tags: ['Reference'],
        parameters: [
            new OA\Parameter(
                name: 'code',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2, example: 'ae'),
            ),
            new OA\Parameter(
                name: 'Accept-Language',
                in: 'header',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/RegionCollectionResponse'),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid country code',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'Country not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Server error',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function regions(ShowRegionsRequest $request, string $code): JsonResponse
    {
        $regions = $this->reference->regionsForCountry(strtolower($code));

        return ApiResponse::success(
            RegionResource::collection($regions),
            __('reference.regions_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/countries/{code}/cities',
        operationId: 'listCities',
        description: 'Returns active cities / emirates / governorates for the given country. Maps to the underlying Region model.',
        summary: 'List cities for a country',
        security: [],
        tags: ['Reference'],
        parameters: [
            new OA\Parameter(
                name: 'code',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2, example: 'ae'),
            ),
            new OA\Parameter(
                name: 'Accept-Language',
                in: 'header',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CityCollectionResponse'),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid country code',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'Country not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Server error',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function cities(ShowRegionsRequest $request, string $code): JsonResponse
    {
        $cities = $this->reference->regionsForCountry(strtolower($code));

        return ApiResponse::success(
            CityResource::collection($cities),
            __('reference.cities_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/settings/public',
        operationId: 'getPublicSettings',
        description: 'Returns the whitelisted public application settings (locales, currencies, VAT rates, contact, social links, support hours). All other settings keys remain server-only.',
        summary: 'Get whitelisted public settings',
        security: [],
        tags: ['Reference'],
        parameters: [
            new OA\Parameter(
                name: 'Accept-Language',
                in: 'header',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicSettingsResponse'),
            ),
            new OA\Response(
                response: 500,
                description: 'Server error',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function publicSettings(): JsonResponse
    {
        $payload = $this->reference->publicSettings();

        return ApiResponse::success(
            new PublicSettingsResource($payload),
            __('reference.public_settings_retrieved'),
        );
    }
}
