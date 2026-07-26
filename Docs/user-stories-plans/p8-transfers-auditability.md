# Phase 8 — Inventory Transfers + Full Reservation History

**Covers:** US-8.1, US-9.1
**Actors:** Warehouse Operator (transfers), Admin (history)
**Prerequisite phases:** p4 (Reservation Lifecycle — `reservation_history` table), p6 (Pick & Pack)

---

## Phase 2 — Blueprint & Validation

### Step 0 — Codebase Analysis

| Check | Finding |
|---|---|
| Models | `Inventory` model has all 5 qty columns + locking pattern ✅; `ReservationHistory` model exists (p4) ✅ |
| MovementType | `MovementType::TransferIn` + `MovementType::TransferOut` ✅ already exist in the enum |
| Services | No `TransferService` yet — create; `ReservationService` exists for history read |
| Controllers | `InventoryController` exists — add `transfer()`; `ReservationController` exists — add `history()` |
| Routes | `warehouse_operator.php` gets transfer route; `admin.php` gets history route |
| Deadlock prevention | Lock both inventory rows in ascending `id` order — same approach as documented in AGENTS.md |
| History table | `reservation_history` ✅ created in p4 with `actor_id`, `from_status`, `to_status`, `created_at` |

No AGENTS.md violations — transfer uses `DB::transaction()` + deterministic lock ordering; history is append-only read.

---

### B. API & Data Structure

#### `POST /api/v1/inventory/transfer`

**Request body:**
```json
{
  "product_id": 1,
  "from_warehouse_id": 2,
  "to_warehouse_id": 3,
  "quantity": 10
}
```

**Response 200 OK:**
```json
{
  "ok": true,
  "code": 200,
  "message": "Stock transferred",
  "direct": null,
  "data": {
    "product_id": 1,
    "from_warehouse_id": 2,
    "to_warehouse_id": 3,
    "quantity": 10,
    "movement_ids": [45, 46]
  }
}
```

**Response 422 — insufficient available stock:**
```json
{
  "ok": false,
  "code": 422,
  "message": "Insufficient available stock for transfer",
  "direct": null,
  "data": null
}
```

**Response 422 — same warehouse:**
```json
{
  "ok": false,
  "code": 422,
  "message": "Source and destination warehouse must differ",
  "direct": null,
  "data": null
}
```

---

#### `GET /api/v1/reservations/{id}/history`

**Response 200:**
```json
{
  "ok": true,
  "code": 200,
  "message": null,
  "direct": null,
  "data": {
    "reservation_id": 12,
    "history": [
      {
        "from_status": null,
        "to_status": "open",
        "quantity_affected": 5,
        "actor": "System",
        "timestamp": "2026-07-26T17:00:00Z"
      },
      {
        "from_status": "open",
        "to_status": "picked",
        "quantity_affected": 3,
        "actor": "John Doe",
        "timestamp": "2026-07-26T17:10:00Z"
      },
      {
        "from_status": "picked",
        "to_status": "packed",
        "quantity_affected": 3,
        "actor": "John Doe",
        "timestamp": "2026-07-26T17:20:00Z"
      }
    ]
  }
}
```

---

### C. Database & Schema Verification

No new tables required — all needed tables and columns already exist from prior phases:
- `inventory` ✅ (p1 — original)
- `inventory_movements` ✅ (p1 — original; `TransferIn`/`TransferOut` types exist in `MovementType` enum)
- `reservation_history` ✅ (p4)

---

## Phase 3 — Implementation Plan

### Phase 6 — Inventory Transfers + Full Reservation History

#### User story

**As a** Warehouse Operator (transfers) and Admin (history)
**I want to** transfer available stock between warehouses atomically and view the complete state-transition trail of any reservation
**So that** warehouse-to-warehouse moves never break open reservations, and any inventory discrepancy can be fully explained during a dispute or audit

**Acceptance Criteria:**

- [x] AC-P6-1: `POST /api/v1/inventory/transfer` with `product_id`, `from_warehouse_id`, `to_warehouse_id`, `quantity` transfers only `quantity_available` stock — reserved/picked/packed are never eligible
- [x] AC-P6-2: If source `quantity_available < quantity` (re-checked after lock) → `422`; inventory unchanged
- [x] AC-P6-3: Both inventory rows are locked in ascending `id` order within a single DB transaction; two linked movement rows (`transfer_out` with negative delta, `transfer_in` with positive delta) are created with the same `created_at`
- [x] AC-P6-4: Transfer between same warehouse (`from === to`) → `422` at FormRequest validation (before hitting DB)
- [x] AC-P6-5: Transfer to an inactive destination warehouse → `422` at FormRequest validation
- [x] AC-P6-6: `GET /api/v1/reservations/{id}/history` returns immutable ordered entries from `reservation_history`, newest-last, with `actor` name (`"System"` if `actor_id = null`)
- [x] AC-P6-7: History endpoint is accessible to `admin` role only

**Expected Result:** Transfers move only free stock and never invalidate open reservations; any reservation's full lifecycle is reconstructable after the fact.

#### Assumptions

- A-P6-1: Transfer to an inactive destination warehouse is rejected at FormRequest level (`where('is_active', 1)`)
- A-P6-2: If destination has no `inventory` row for that product → create one (all quantities zero) inside the transaction, then increment `quantity_available`
- A-P6-3: History sorted ascending by `created_at`; ties broken by `id` ascending
- A-P6-4: History endpoint accessible to `admin` only via `can:manage-products` gate (or a dedicated `view-reservation-history` gate — matches `admin` role)

#### Edge cases

- E1-P6: `from_warehouse_id === to_warehouse_id` → `different:from_warehouse_id` validation rule rejects before DB access
- E2-P6: Deadlock prevention — collect `[$fromInventory->id, $toInventory->id]`, sort ascending, fetch + lock in that order using `Inventory::whereIn('id', $sortedIds)->lockForUpdate()->orderBy('id')->get()` — assign from/to by matching IDs after fetch
- E3-P6: Source has `quantity_available = 5`, two concurrent transfers for 5 each → first acquires lock, second re-reads post-lock and sees 0 → fails with 422 (same pattern as reserve)
- E4-P6: Source inventory row doesn't exist → `firstOrFail()` → 404; no orphan movement records created

#### Files map

```
app/Core/Services/Transfer/
  TransferService.php                              [NEW]
app/Http/Requests/Inventory/
  TransferRequest.php                              [NEW]
app/Http/Controllers/API/Inventory/
  InventoryController.php                          [MODIFY — add transfer()]
app/Http/Controllers/API/Reservation/
  ReservationController.php                        [MODIFY — add history()]
app/Http/Resources/
  ReservationHistoryResource.php                   [NEW]
routes/apis/V1/warehouse_operator.php              [MODIFY — add POST inventory/transfer]
routes/apis/V1/admin.php                           [MODIFY — add GET reservations/{id}/history]
lang/en.json                                       [MODIFY]
lang/ar.json                                       [MODIFY]
tests/Feature/Inventory/
  TransferTest.php                                 [NEW]
tests/Feature/Reservation/
  ReservationHistoryTest.php                       [NEW]
```

#### Sub-phase 6.1 — Full-stack

*(No DB migrations needed — all tables and columns exist)*

1. **`TransferService::transfer(array $data, int $actorId): array`**:
   - `DB::transaction()`:
   - `$productId = (int) $data['product_id']`; `$qty = (int) $data['quantity']`
   - Fetch `$fromInv = Inventory::query()->where('product_id', $productId)->where('warehouse_id', $fromWarehouseId)->firstOrFail()`
   - Fetch or create `$toInv = Inventory::query()->where('product_id', $productId)->where('warehouse_id', $toWarehouseId)->firstOrCreate(['product_id' => $productId, 'warehouse_id' => $toWarehouseId], ['quantity_available' => 0, ...])`
   - Sort IDs: `$ids = collect([$fromInv->id, $toInv->id])->sort()->values()->toArray()`
   - Lock both in order: `$locked = Inventory::whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get()`
   - Assign: `$fromLocked = $locked->find($fromInv->id)`; `$toLocked = $locked->find($toInv->id)`
   - Re-check after lock: if `$fromLocked->quantity_available < $qty` → throw `ValidationException::withMessages(['quantity' => __('Insufficient available stock for transfer')])`
   - `$fromLocked->quantity_available -= $qty`; `$toLocked->quantity_available += $qty`
   - Save both
   - `$now = now()`
   - Create `InventoryMovement` for transfer_out: `product_id`, `warehouse_id = from`, `type = MovementType::TransferOut`, `quantity_delta = -$qty`, `actor_id`, `created_at = $now`
   - Create `InventoryMovement` for transfer_in: `product_id`, `warehouse_id = to`, `type = MovementType::TransferIn`, `quantity_delta = +$qty`, `actor_id`, `created_at = $now`
   - Return `['product_id', 'from_warehouse_id', 'to_warehouse_id', 'quantity', 'movement_ids' => [$out->id, $in->id]]`

2. **FormRequest** `TransferRequest`:
   - `rules()`:
     - `product_id` → `['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')]`
     - `from_warehouse_id` → `['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', 1)]`
     - `to_warehouse_id` → `['required', 'integer', 'different:from_warehouse_id', Rule::exists('warehouses', 'id')->where('is_active', 1)]`
     - `quantity` → `['required', 'integer', 'min:1']`
   - `messages()` + `attributes()` using `__()`

3. **`InventoryController::transfer(TransferRequest $request)`**:
   - Call `$this->transferService->transfer($request->validated(), $request->user()->id)`
   - Return `$this->sendSuccessResponse($result, __('Stock transferred'))`

4. **`ReservationController::history(int $id)`**:
   - `$reservation = Reservation::findOrFail($id)`
   - `$history = $reservation->reservationHistory()->with('actor')->orderBy('created_at')->orderBy('id')->get()`
   - Return `$this->sendSuccessResponse(['reservation_id' => $reservation->id, 'history' => ReservationHistoryResource::collection($history)])`

5. **`Reservation` model** (add relationship):
   - `public function reservationHistory(): HasMany { return $this->hasMany(ReservationHistory::class); }`

6. **`ReservationHistoryResource`**:
   - `from_status` → `$this->resource->from_status?->value`
   - `to_status` → `$this->resource->to_status->value`
   - `quantity_affected` → `$this->resource->quantity_affected`
   - `actor` → `$this->resource->actor?->name ?? __('System')`
   - `timestamp` → `$this->resource->created_at?->toISOString()`

7. **Routes**:
   - In `warehouse_operator.php`:
     ```php
     Route::post('inventory/transfer', [InventoryController::class, 'transfer'])->name('inventory.transfer');
     ```
   - In `admin.php`:
     ```php
     Route::middleware(['auth:sanctum', 'can:adjust-stock'])->group(function () {
         Route::get('reservations/{reservation}/history', [ReservationController::class, 'history'])->name('reservations.history');
     });
     ```

8. **lang keys** — add to both `lang/en.json` and `lang/ar.json`:
   - `"Stock transferred": "Stock transferred"` / `"تم نقل المخزون"`
   - `"Insufficient available stock for transfer": "Insufficient available stock for transfer"` / `"المخزون المتاح غير كافٍ للنقل"`
   - `"Source and destination warehouse must differ": "Source and destination warehouse must differ"` / `"يجب أن يختلف المستودع المصدر عن المستودع الوجهة"`
   - `"Reservation history": "Reservation history"` / `"سجل الحجز"`
   - `"System": "System"` / `"النظام"`
   - `"From warehouse": "From warehouse"` / `"المستودع المصدر"`
   - `"To warehouse": "To warehouse"` / `"المستودع الوجهة"`

9. Run `vendor/bin/pint --dirty` before commit

#### Tests (Phase 6)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: transfer available stock between warehouses | Feature | tests/Feature/Inventory/TransferTest.php | 200; source `quantity_available -= N`; dest `quantity_available += N`; two movements created (transfer_out + transfer_in) |
| Happy: transfer creates destination inventory row if missing | Feature | tests/Feature/Inventory/TransferTest.php | 200; new inventory row created for destination; quantities correct |
| Sad: insufficient available stock → 422 | Feature | tests/Feature/Inventory/TransferTest.php | 422; inventory unchanged; no movements created |
| Sad: reserved stock not counted as available | Feature | tests/Feature/Inventory/TransferTest.php | 422 when quantity > quantity_available (even if quantity_reserved covers the gap) |
| Sad: same warehouse → 422 validation | Feature | tests/Feature/Inventory/TransferTest.php | 422 at validation before DB access |
| Sad: inactive destination warehouse → 422 | Feature | tests/Feature/Inventory/TransferTest.php | 422 at validation |
| Happy: concurrent transfers (ascending lock order → no deadlock) | Feature | tests/Feature/Inventory/TransferTest.php | both transfers complete; no deadlock exception; final quantities consistent |
| Happy: history returns all transitions in ascending chronological order | Feature | tests/Feature/Reservation/ReservationHistoryTest.php | 200; entries ordered by timestamp; actor names present; System for scheduler |
| Sad: non-admin cannot access history → 403 | Feature | tests/Feature/Reservation/ReservationHistoryTest.php | 403 |
| Sad: unknown reservation_id → 404 | Feature | tests/Feature/Reservation/ReservationHistoryTest.php | 404 |

#### cURL Smoke Tests (Phase 6)

```bash
# Transfer stock between warehouses
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/inventory/transfer \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{\"product_id\":1,\"from_warehouse_id\":1,\"to_warehouse_id\":2,\"quantity\":5}"

# Transfer — insufficient stock (expect 422)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/inventory/transfer \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{\"product_id\":1,\"from_warehouse_id\":1,\"to_warehouse_id\":2,\"quantity\":9999}"

# Transfer — same warehouse (expect 422)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/inventory/transfer \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{\"product_id\":1,\"from_warehouse_id\":1,\"to_warehouse_id\":1,\"quantity\":5}"

# Full reservation history trail
curl.exe -i "http://127.0.0.1:8000/api/v1/reservations/1/history" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>"

# Non-admin accessing history (expect 403)
curl.exe -i "http://127.0.0.1:8000/api/v1/reservations/1/history" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>"
```

#### Complexity tracking

- `ponytail:` Deadlock prevention uses ascending `id` lock ordering — this is the canonical MySQL pattern and adds zero infrastructure overhead. Ceiling: if the system ever runs multi-shard sharding with different ID spaces, a global lock key (e.g., Redis) would be needed. Single-tenant MySQL → ascending-ID order is the right tool.
- `ponytail:` `firstOrCreate` for the destination inventory row runs inside the transaction. If two concurrent transfers both try to create the same destination row, one will insert and the other will find the existing row via the UNIQUE constraint on `(product_id, warehouse_id)`. The `lockForUpdate` on the subsequent re-fetch ensures the final increment is safe.
