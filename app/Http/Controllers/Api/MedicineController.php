<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Services\ClinicPharmacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class MedicineController extends Controller
{
    public function __construct(private ClinicPharmacyService $pharmacy)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($this->pharmacy->isConfigured()) {
            return $this->indexFromClinic($request);
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));
        $page = max(1, (int) $request->input('page', 1));

        $query = Medicine::query()
            ->where('is_active', true)
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('generic_name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('manufacturer', 'like', $term);
                });
            })
            ->orderBy('name');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'source' => 'local',
        ]);
    }

    /**
     * Persist a clinic catalog hit into local medicines so prescriptions can use medicine_id.
     */
    public function resolve(Request $request): JsonResponse
    {
        if (! $this->pharmacy->isConfigured()) {
            $data = $request->validate([
                'id' => ['required', 'exists:medicines,id'],
            ]);

            return response()->json(Medicine::findOrFail($data['id']));
        }

        $data = $request->validate([
            'clinic_product_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'strength' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'clinic_external_id' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $medicine = $this->pharmacy->upsertMedicine([
            'id' => $data['clinic_product_id'],
            'clinic_product_id' => $data['clinic_product_id'],
            'external_id' => $data['clinic_external_id'] ?? null,
            'name' => $data['name'],
            'generic_name' => $data['generic_name'] ?? null,
            'sku' => $data['sku'] ?? null,
            'strength' => $data['strength'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'unit' => $data['unit'] ?? 'tablet',
            'stock' => $data['stock_quantity'] ?? 0,
            'sale_price' => $data['unit_price'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($medicine);
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->pharmacy->isConfigured()) {
            return response()->json([
                'message' => 'Medicines are managed by the linked pharmacy catalog. Search and select from that list instead.',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $medicine = Medicine::create($data);

        return response()->json($medicine, 201);
    }

    public function update(Request $request, Medicine $medicine): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $medicine->update($data);

        return response()->json($medicine);
    }

    private function indexFromClinic(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 40)));
        $q = $request->filled('q') ? trim((string) $request->input('q')) : null;
        $inStock = null;
        if ($request->has('in_stock')) {
            $inStock = filter_var($request->input('in_stock'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        try {
            $remote = $this->pharmacy->listProducts($q, $page, $perPage, $inStock);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => [],
                'source' => 'clinic',
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 502);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Could not load pharmacy catalog.',
                'data' => [],
                'source' => 'clinic',
            ], 502);
        }

        // Browse-only mapping — no DB write on every page (thousands of products).
        $medicines = collect($remote['data'])
            ->map(fn (array $product) => $this->pharmacy->mapProduct($product))
            ->values();

        return response()->json([
            'data' => $medicines,
            'meta' => $remote['meta'],
            'links' => $remote['links'] ?? [],
            'source' => 'clinic',
        ]);
    }
}
