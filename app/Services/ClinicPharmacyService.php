<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\Prescription;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClinicPharmacyService
{
    public function isConfigured(): bool
    {
        if (! config('pharmacy.enabled', true)) {
            return false;
        }

        return filled(config('pharmacy.api_key')) && filled(config('pharmacy.store_code'));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, links?: array<string, mixed>}
     */
    public function listProducts(?string $q = null, int $page = 1, int $perPage = 50, ?bool $inStock = null): array
    {
        $this->assertConfigured();

        $query = [
            'page' => max(1, $page),
            'per_page' => min(100, max(1, $perPage)),
            'is_active' => 1,
        ];

        if (filled($q)) {
            $query['q'] = $q;
        }
        if ($inStock !== null) {
            $query['in_stock'] = $inStock ? 1 : 0;
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders($this->headers())
                ->get($this->url('/api/clinic/products'), $query)
                ->throw();
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $message = $e->response?->json('message') ?: $e->getMessage();
            throw new RuntimeException("Pharmacy catalog request failed ({$status}): {$message}", (int) $status);
        }

        $json = $response->json();

        return [
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : [],
            'links' => is_array($json['links'] ?? null) ? $json['links'] : [],
        ];
    }

    /**
     * Push a local prescription to Lamstone pharmacy (pending Rx for billing).
     *
     * @return array<string, mixed>
     */
    public function createPrescription(Prescription $prescription): array
    {
        $this->assertConfigured();

        $prescription->loadMissing([
            'patient',
            'doctor.clinic',
            'worksheet',
            'items.medicine',
        ]);

        $patient = $prescription->patient;
        $doctor = $prescription->doctor;
        $worksheet = $prescription->worksheet;

        $items = [];
        foreach ($prescription->items as $item) {
            $medicine = $item->medicine;
            if (! $medicine) {
                throw new RuntimeException('Prescription item is missing medicine.');
            }

            $line = [
                'product_name' => (string) $medicine->name,
                'quantity' => max(1, (int) $item->quantity),
                'dosage' => (string) $item->dosage,
                'frequency' => (string) $item->frequency,
                'duration' => ((int) $item->duration_days).' days',
            ];

            if ($medicine->clinic_product_id) {
                $line['product_id'] = (int) $medicine->clinic_product_id;
            }
            if (filled($medicine->clinic_external_id)) {
                $line['product_external_id'] = (string) $medicine->clinic_external_id;
            }
            if (filled($medicine->generic_name)) {
                $line['generic_name'] = (string) $medicine->generic_name;
            }
            if (filled($medicine->strength)) {
                $line['strength'] = (string) $medicine->strength;
            }
            if (filled($medicine->unit)) {
                $line['unit'] = (string) $medicine->unit;
            }

            $items[] = $line;
        }

        if ($items === []) {
            throw new RuntimeException('Prescription has no items to send to pharmacy.');
        }

        $prescriptionNumber = 'RX-'.$prescription->id;
        $payload = [
            'external_id' => 'clinic-rx-'.$prescription->id,
            'prescription_number' => $prescriptionNumber,
            'prescribed_at' => optional($prescription->created_at)?->toIso8601String() ?? now()->toIso8601String(),
            'patient_name' => $patient?->name,
            'patient_age' => $this->patientAge($patient),
            'patient_gender' => $patient?->gender,
            'patient_phone' => $patient?->phone,
            'doctor_name' => $doctor?->name,
            'doctor_phone' => $doctor?->phone,
            'doctor_clinic' => $doctor?->clinic?->name ?? 'Alverstone Medcity',
            'diagnosis' => $worksheet?->diagnosis,
            'notes' => $prescription->notes,
            'items' => $items,
        ];

        // Drop empty optional fields so Lamstone validation stays clean.
        $payload = array_filter(
            $payload,
            static fn ($value) => $value !== null && $value !== ''
        );

        try {
            $response = Http::timeout(25)
                ->acceptJson()
                ->withHeaders($this->headers())
                ->post($this->url('/api/clinic/prescriptions'), $payload)
                ->throw();
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $message = $e->response?->json('message')
                ?: collect($e->response?->json('errors') ?? [])->flatten()->first()
                ?: $e->getMessage();
            throw new RuntimeException("Pharmacy prescription push failed ({$status}): {$message}", (int) ($status ?: 0));
        }

        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);

        return [
            'id' => isset($data['id']) ? (int) $data['id'] : null,
            'prescription_number' => $data['prescription_number'] ?? $prescriptionNumber,
            'status' => $data['status'] ?? 'pending',
            'raw' => $data,
        ];
    }

    private function patientAge(?\App\Models\Patient $patient): ?string
    {
        if (! $patient?->dob) {
            return null;
        }

        return (string) $patient->dob->age;
    }

    /**
     * Map a clinic product to the medicine JSON shape without writing to the database.
     * Use clinic_product_id as a temporary id for catalog browsing; call upsertMedicine when prescribing.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public function mapProduct(array $product): array
    {
        $clinicId = (int) ($product['id'] ?? 0);
        $unit = trim((string) ($product['unit'] ?? '')) ?: 'tablet';
        $stock = (int) max(0, floor((float) ($product['stock'] ?? 0)));
        $price = (float) ($product['sale_price'] ?? $product['mrp'] ?? 0);

        return [
            'id' => $clinicId,
            'clinic_product_id' => $clinicId,
            'clinic_external_id' => $product['external_id'] ?? null,
            'name' => (string) ($product['name'] ?? 'Unknown medicine'),
            'generic_name' => filled($product['generic_name'] ?? null) ? (string) $product['generic_name'] : null,
            'sku' => $product['sku'] ?? null,
            'strength' => filled($product['strength'] ?? null) ? (string) $product['strength'] : null,
            'manufacturer' => filled($product['manufacturer'] ?? null) ? (string) $product['manufacturer'] : null,
            'unit' => $unit,
            'stock_quantity' => $stock,
            'unit_price' => $price,
            'is_active' => (bool) ($product['is_active'] ?? true),
            'source' => 'clinic',
        ];
    }

    /**
     * Upsert a clinic product into the local medicines table so prescriptions can reference it.
     *
     * @param  array<string, mixed>  $product
     */
    public function upsertMedicine(array $product): Medicine
    {
        $clinicId = (int) ($product['id'] ?? $product['clinic_product_id'] ?? 0);
        if ($clinicId <= 0) {
            throw new RuntimeException('Clinic product is missing id.');
        }

        $unit = trim((string) ($product['unit'] ?? '')) ?: 'tablet';
        $stock = (int) max(0, floor((float) ($product['stock'] ?? $product['stock_quantity'] ?? 0)));
        $price = (float) ($product['sale_price'] ?? $product['unit_price'] ?? $product['mrp'] ?? 0);

        return Medicine::updateOrCreate(
            ['clinic_product_id' => $clinicId],
            [
                'clinic_external_id' => $product['external_id'] ?? $product['clinic_external_id'] ?? null,
                'name' => (string) ($product['name'] ?? 'Unknown medicine'),
                'generic_name' => filled($product['generic_name'] ?? null) ? (string) $product['generic_name'] : null,
                'sku' => $product['sku'] ?? null,
                'strength' => filled($product['strength'] ?? null) ? (string) $product['strength'] : null,
                'manufacturer' => filled($product['manufacturer'] ?? null) ? (string) $product['manufacturer'] : null,
                'unit' => $unit,
                'stock_quantity' => $stock,
                'unit_price' => $price,
                'is_active' => (bool) ($product['is_active'] ?? true),
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-API-Key' => (string) config('pharmacy.api_key'),
            'X-Store-Code' => (string) config('pharmacy.store_code'),
        ];
    }

    private function url(string $path): string
    {
        return config('pharmacy.base_url').$path;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Pharmacy API is not configured (PHARMACY_API_KEY / PHARMACY_STORE_CODE).');
        }
    }
}
