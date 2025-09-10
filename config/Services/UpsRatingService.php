<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\UpsTokenService;

class UpsRatingService
{
    private UpsTokenService $tokenService;
    private string $baseUrl;
    private string $version;
    private string $account;

    public function __construct(UpsTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
        $this->baseUrl = env('UPS_ENV') === 'prod'
            ? env('UPS_BASE_URL_PROD')
            : env('UPS_BASE_URL_SANDBOX');
        $this->version = env('UPS_RATING_VERSION', 'v2403');
        $this->account = env('UPS_ACCOUNT_NUMBER');
    }

    public function getRates(array $data): array
    {
        try {
            $token = $this->tokenService->getAccessToken();

            $url = rtrim($this->baseUrl, '/') . "/api/rating/{$this->version}/" . ($data['request_option'] ?? 'shop');

            $payload = [
                "RateRequest" => [
                    "Request" => [
                        "RequestOption" => $data['request_option'] ?? "shop",
                        "TransactionReference" => [
                            "CustomerContext" => "Rating and Service",
                        ],
                    ],
                    "Shipment" => [
                        "Shipper" => [
                            "Name" => "AgenciaRapi",
                            "ShipperNumber" => $this->account,
                            "Address" => [
                                "AddressLine"       => [$data['origen']],
                                "PostalCode"        => $data['origen_postal'] ?? "",
                                "CountryCode"       => $data['origen_pais'] ?? "CO",
                            ],
                        ],
                        "ShipTo" => [
                            "Name" => "Cliente destino",
                            "Address" => [
                                "AddressLine"       => [$data['destino']],
                                "PostalCode"        => $data['destino_postal'] ?? "",
                                "CountryCode"       => $data['destino_pais'] ?? "US",
                            ],
                        ],
                        "Package" => [
                            "PackagingType" => ["Code" => "02", "Description" => "Customer Supplied"],
                            "Dimensions"    => [
                                "UnitOfMeasurement" => ["Code" => env('UPS_UOM_DIM', 'CM')],
                                "Length"            => (string) $data['largo'],
                                "Width"             => (string) $data['ancho'],
                                "Height"            => (string) $data['altura'],
                            ],
                            "PackageWeight" => [
                                "UnitOfMeasurement" => ["Code" => env('UPS_UOM_WEIGHT', 'KGS')],
                                "Weight"            => (string) $data['peso'],
                            ],
                        ],
                    ],
                ],
            ];

            // Si es "rate", añadir Service Code
            if (($data['request_option'] ?? 'shop') === 'rate' && !empty($data['service_code'])) {
                $payload["RateRequest"]["Shipment"]["Service"] = [
                    "Code" => $data['service_code'],
                ];
            }

            $resp = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->post($url, $payload);

            if (!$resp->successful()) {
                return [
                    'ok'   => false,
                    'error'=> $resp->body(),
                    'corr_id' => $resp->header('transId') ?? null,
                ];
            }

            $json = $resp->json();

            // Procesar respuesta en formato más limpio
            $rates = [];
            foreach ($json['RateResponse']['RatedShipment'] ?? [] as $shipment) {
                $rates[] = [
                    'service'    => $shipment['Service']['Description'] ?? 'N/A',
                    'published'  => $shipment['TotalCharges']['MonetaryValue'] ?? null,
                    'negotiated' => $shipment['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'] ?? null,
                    'currency'   => $shipment['TotalCharges']['CurrencyCode'] ?? 'USD',
                    'eta'        => $shipment['GuaranteedDelivery']['BusinessDaysInTransit'] ?? null,
                ];
            }

            return ['ok' => true, 'rates' => $rates];

        } catch (\Throwable $e) {
            return [
                'ok'   => false,
                'error'=> $e->getMessage(),
            ];
        }
    }
}
