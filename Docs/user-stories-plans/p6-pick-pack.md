# Phase 6 — Pick & Pack Transitions

**Covers:** US-5.1, US-5.2
**Actors:** Warehouse Operator
**Prerequisite phases:** p3 (Orders + Reserve), p4 (Reservation Lifecycle)

---

## Phase 2 — Blueprint & Validation

### Step 0 — Codebase Analysis

| Check | Finding |
|---|---|
| Services | `ReservationService` exists (release/expire/partialCancel) — add `pick()` and `pack()` methods |
| Controllers | `ReservationController` exists — add `pick()` and `pack()` actions |
| Models | `Reservation` has `quantity_picked` + `quantity_packed` columns ✅; `ReservationStatus` has `Picked` + `Packed` cases ✅ |
| Inventory | `Inventory` has `quantity_reserved`, `quantity_picked`, `quantity_packed` columns ✅ |
| MovementType | `MovementType::Pick` and `MovementType::Pack` cases ✅ already exist |
| History | `ReservationHistory` + `ReservationService` write pattern already established in p4 |

No AGENTS.md violations — same locking + history pattern as release.

---

### B. API & Data Structure

#### `POST /api/v1/reservations/{id}/pick`

**Request body:**
```json
{ "quantity": 3 }
```
*(optional — defaults to full remaining reserved quantity)*

**Response 200 OK:**
```json
{
  "ok": true,
  "code": 200,
  "message": "Stock marked as picked",
  "direct": null,
  "data": {
    "reservation_id": 12,
    "quantity_picked": 3,
    "quantity_reserved": 2,
    "status": "open"
  }
}
```
*(status = `picked` when fully picked; stays `open` while partial)*

**Response 422 — quantity exceeds remaining pickable:**
```json
{
  "ok": false, "code": 422,
  "message": "Quantity exceeds pickable amount",
  "direct": null, "data": null
}
```

**Response 409 — wrong reservation state:**
```json
{
  "ok": false, "code": 409,
  "message": "Reservation cannot be picked in its current state",
  "direct": null, "data": null
}
```

---

#### `POST /api/v1/reservations/{id}/pack`

**Request body:**
```json
{ "quantity": 3 }
```

**Response 200 OK:**
```json
{
  "ok": true,
  "code": 200,
  "message": "Stock marked as packed",
  "direct": null,
  "data": {
    "reservation_id": 12,
    "quantity_packed": 3,
    "quantity_picked": 0,
    "status": "packed"
  }
}
```
*(status = `packed` when fully packed; stays `picked` while partial)*

**Response 422 — quantity exceeds available picked:**
```json
{
  "ok": false, "code": 422,
  "message": "Quantity exceeds packed amount",
  "direct": null, "data": null
}
```

---

### C. Database & Schema Verification

No new tables or migrations required — all columns exist on `inventory` and `reservations`; `MovementType::Pick` and `MovementType::Pack` already defined.

---

## Phase 3 — Implementation Plan

### Phase 4 — Pick & Pack Transitions

#### User story

**As a** Warehouse Operator
**I want to** mark reserved stock as picked, then mark picked stock as packed
**So that** the system accurately reflects physical warehouse progress and only packed stock enters the shipment queue

**Acceptance Criteria:**

- [x] AC-P4-1: `POST /api/v1/reservations/{id}/pick` with optional `quantity` (default = full remaining reserved): decrements `quantity_reserved`, increments `quantity_picked` on both `inventory` and `reservation`; updates reservation status
- [x] AC-P4-2: Status → `picked` when `quantity_picked == quantity` (fully picked); remains `open` while partially picked
- [x] AC-P4-3: Cannot pick more than `reservation.quantity - reservation.quantity_picked` (remaining pickable quantity) — `422`
- [x] AC-P4-4: `POST /api/v1/reservations/{id}/pack` with `quantity`: decrements `quantity_picked`, increments `quantity_packed` on both rows
- [x] AC-P4-5: Status → `packed` when `quantity_packed == quantity` (fully packed); remains `picked` while partial
- [x] AC-P4-6: Cannot pack more than `reservation.quantity_picked` — `422`
- [x] AC-P4-7: Pick/pack on `released`, `expired`, or `fulfilled` reservation → `409`
- [x] AC-P4-8: Each operation creates a `reservation_history` row and an `InventoryMovement` row; wrapped in `DB::transaction()` with `lockForUpdate()`

**Expected Result:** Picked and packed quantities are tracked distinctly from reserved stock; only fully packed reservations are eligible to enter the shipment queue.

#### Assumptions

- A-P4-1: `quantity` param for pick is optional (defaults to remaining pickable); for pack it is required (no sensible default without knowing intent)
- A-P4-2: Partial pick is allowed; subsequent picks consume the remaining pickable quantity until fully picked
- A-P4-3: `quantity = 0` rejected by FormRequest (`min:1`)

#### Edge cases

- E1-P4: Pick on `released`, `expired`, or `fulfilled` reservation → `409` (wrong state)
- E2-P4: Pack on an `open` reservation (nothing picked yet) → `422` because `quantity_picked = 0`
- E3-P4: Pick default quantity = `reservation.quantity - reservation.quantity_picked` — computed inside the transaction after `lockForUpdate()`

#### Files map

```
app/Core/Services/Reservation/
  ReservationService.php                      [MODIFY — add pick() and pack()]
app/Http/Requests/Reservation/
  PickRequest.php                             [NEW]
  PackRequest.php                             [NEW]
app/Http/Controllers/API/Reservation/
  ReservationController.php                   [MODIFY — add pick() and pack()]
routes/apis/V1/warehouse_operator.php         [MODIFY — add pick/pack routes]
lang/en.json                                  [MODIFY]
lang/ar.json                                  [MODIFY]
tests/Feature/Reservation/
  PickReservationTest.php                     [NEW]
  PackReservationTest.php                     [NEW]
```

#### Sub-phase 4.1 — Full-stack

*(No DB migrations needed — all columns exist)*

1. **`ReservationService::pick(int $reservationId, ?int $qty, ?int $actorId): Reservation`**:
   - `DB::transaction()`:
   - Re-fetch `$reservation = Reservation::query()->lockForUpdate()->findOrFail($reservationId)`
   - Guard: `$reservation->status` not in `[Released, Expired, Fulfilled]` — else abort 409
   - `$pickable = $reservation->quantity - $reservation->quantity_picked`
   - Default: `$qty = $qty ?? $pickable`
   - Guard: `$qty > $pickable` — else throw `ValidationException::withMessages(['quantity' => __('Quantity exceeds pickable amount')])`
   - Load and lock inventory: `Inventory::query()->where('product_id', ...)->where('warehouse_id', ...)->lockForUpdate()->firstOrFail()`
   - `inventory->quantity_reserved -= $qty`; `inventory->quantity_picked += $qty`; save inventory
   - `reservation->quantity_picked += $qty`
   - Update status: if `reservation->quantity_picked === reservation->quantity` → `Picked`; else stays `Open`
   - Save reservation
   - Create `InventoryMovement` (type `MovementType::Pick`, `quantity_delta = $qty`, `related_reservation_id`, `actor_id`)
   - Create `ReservationHistory` (`from_status`, `to_status = new status`, `quantity_affected = $qty`, `actor_id`)
   - Return updated reservation

2. **`ReservationService::pack(int $reservationId, int $qty, ?int $actorId): Reservation`**:
   - Same pattern; guard `$qty <= reservation->quantity_picked` else 422
   - `inventory->quantity_picked -= $qty`; `inventory->quantity_packed += $qty`; save
   - `reservation->quantity_packed += $qty`
   - Status: if `reservation->quantity_packed === reservation->quantity` → `Packed`; else stays `Picked`
   - Write `InventoryMovement` (type `MovementType::Pack`) + `ReservationHistory`

3. **FormRequest** `PickRequest`:
   - `rules()`: `quantity` → `['nullable', 'integer', 'min:1']`
   - `messages()` + `attributes()` using `__()`

4. **FormRequest** `PackRequest`:
   - `rules()`: `quantity` → `['required', 'integer', 'min:1']`
   - `messages()` + `attributes()` using `__()`

5. **Controller** `ReservationController`:
   - `pick(PickRequest $request, int $id)`:
     - `$reservation = $this->reservationService->pick($id, $request->validated()['quantity'] ?? null, auth()->id())`
     - Return `$this->sendSuccessResponse(['reservation_id' => $reservation->id, 'quantity_picked' => $reservation->quantity_picked, 'quantity_reserved' => $reservation->quantity - $reservation->quantity_picked, 'status' => $reservation->status->value], __('Stock marked as picked'))`
   - `pack(PackRequest $request, int $id)`:
     - Same pattern calling `$this->reservationService->pack($id, $request->validated()['quantity'], auth()->id())`

6. **Routes** in `warehouse_operator.php`:
   ```php
   Route::post('reservations/{reservation}/pick', [ReservationController::class, 'pick'])->name('reservations.pick');
   Route::post('reservations/{reservation}/pack', [ReservationController::class, 'pack'])->name('reservations.pack');
   ```

7. **lang keys** — add to both `lang/en.json` and `lang/ar.json`:
   - `"Stock marked as picked": "Stock marked as picked"` / `"تم تحديد المخزون كمُلتقَط"`
   - `"Stock marked as packed": "Stock marked as packed"` / `"تم تحديد المخزون كمُعبَّأ"`
   - `"Quantity exceeds pickable amount": "Quantity exceeds pickable amount"` / `"الكمية تتجاوز الكمية القابلة للالتقاط"`
   - `"Quantity exceeds packed amount": "Quantity exceeds packed amount"` / `"الكمية تتجاوز الكمية المُعبَّأة"`
   - `"Reservation cannot be picked in its current state": "Reservation cannot be picked in its current state"` / `"لا يمكن التقاط الحجز في حالته الحالية"`

8. Run `vendor/bin/pint --dirty` before commit

#### Tests (Phase 4)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: full pick (no quantity param) | Feature | tests/Feature/Reservation/PickReservationTest.php | 200; status=picked; inventory.quantity_reserved=0; quantity_picked=full; movement + history created |
| Happy: partial pick (quantity < reserved) | Feature | tests/Feature/Reservation/PickReservationTest.php | 200; status still open; quantity_picked updated; inventory updated |
| Happy: second pick consumes remaining | Feature | tests/Feature/Reservation/PickReservationTest.php | 200; status=picked after second call |
| Sad: pick more than remaining reserved → 422 | Feature | tests/Feature/Reservation/PickReservationTest.php | 422; inventory unchanged |
| Sad: pick on released reservation → 409 | Feature | tests/Feature/Reservation/PickReservationTest.php | 409 |
| Sad: pick on expired reservation → 409 | Feature | tests/Feature/Reservation/PickReservationTest.php | 409 |
| Happy: full pack | Feature | tests/Feature/Reservation/PackReservationTest.php | 200; status=packed; inventory.quantity_picked=0; quantity_packed=full; movement + history created |
| Happy: partial pack | Feature | tests/Feature/Reservation/PackReservationTest.php | 200; status stays picked; partial packed |
| Sad: pack more than picked → 422 | Feature | tests/Feature/Reservation/PackReservationTest.php | 422; inventory unchanged |
| Sad: pack on open (nothing picked) → 422 | Feature | tests/Feature/Reservation/PackReservationTest.php | 422 |

#### cURL Smoke Tests (Phase 4)

```bash
# Full pick (no quantity body = defaults to all remaining)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/reservations/1/pick \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{}"

# Partial pick
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/reservations/1/pick \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{\"quantity\": 2}"

# Pack
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/reservations/1/pack \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{\"quantity\": 2}"

# Pack on open reservation (expect 422 — nothing picked yet)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/reservations/2/pack \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{\"quantity\": 1}"
```
