# Phase 7 — Shipment Processing, Mock Provider & Webhook Deduplication

**Covers:** US-6.1, US-6.2, US-6.3, US-6.4, US-6.5, US-6.6, US-6.7, US-7.1
**Actors:** System (Queue Worker, Webhook Receiver), Developer/Reviewer
**Prerequisite phases:** p6 (Pick & Pack)

---

## Phase 2 — Blueprint & Validation

### Step 0 — Codebase Analysis

| Check | Finding |
|---|---|
| Queue | `jobs` table ✅ migrated; `database` queue driver confirmed (spec requirement) |
| Models | No `Shipment`, `ShipmentAttempt`, `ProcessedWebhookEvent` yet — all new |
| Enums | `ShipmentStatus` + `ShipmentAttemptStatus` missing — must create |
| Jobs | No `app/Jobs/` directory yet — create |
| Commands | `ExpireReservationsCommand` exists — add `ProcessShipmentsCommand` alongside |
| Webhook route | `routes/apis/V1/public.php` exists (no Sanctum, behind API key only) — add webhook route here |
| AppServiceProvider | Needs binding for `ShippingProviderInterface` |
| Inventory locking | Same `lockForUpdate()` pattern as reserve/pick/pack — reuse |

No AGENTS.md violations — jobs go in `app/Jobs/`; scheduler goes in `routes/console.php`; `AppServiceProvider` binding is the correct place for interface binding.

---

### B. API & Data Structure

#### Artisan Command: `php artisan shipments:process`

- Queries all `Reservation` rows with `status = packed`
- Creates a `Shipment` row for each (if none pending already)
- Dispatches `ProcessShipmentJob::dispatch($shipment)` onto the `database` queue

---

#### `POST /api/v1/webhooks/shipping`

No Sanctum required — protected by `X-API-KEY` header only (outer middleware group).

**Request body:**
```json
{
  "event_id": "evt_abc123",
  "shipment_id": 9,
  "status": "success",
  "quantity_shipped": 3
}
```

**Response 200 — processed:**
```json
{
  "ok": true, "code": 200,
  "message": "Webhook processed",
  "direct": null, "data": null
}
```

**Response 200 — duplicate (idempotent no-op):**
```json
{
  "ok": true, "code": 200,
  "message": "Event already processed",
  "direct": null, "data": null
}
```

**Response 404 — unknown shipment_id:**
```json
{
  "ok": false, "code": 404,
  "message": "Shipment not found",
  "direct": null, "data": null
}
```

---

### C. Database & Schema Verification

New tables required:

| Table | Key Columns | Notes |
|---|---|---|
| `shipments` | `id`, `reservation_id FK`, `quantity` uint, `status` string default `'pending'` + index, timestamps | FK → `reservations.id` restrict |
| `shipment_attempts` | `id`, `shipment_id FK cascade`, `attempt_number` uint, `status` string, `provider_response` json nullable, `created_at` default now | No `updated_at`; one row per job attempt |
| `processed_webhook_events` | `id`, `event_id` string unique, `processed_at` timestamp default now | Deduplication guard; no timestamps() |

New Enums:

| Enum | File | Cases |
|---|---|---|
| `ShipmentStatus` | `app/Core/Enums/ShipmentStatus.php` | `Pending = 'pending'`, `InTransit = 'in_transit'`, `Shipped = 'shipped'`, `Failed = 'failed'`, `Timeout = 'timeout'` |
| `ShipmentAttemptStatus` | `app/Core/Enums/ShipmentAttemptStatus.php` | `Success = 'success'`, `PermanentFailure = 'permanent_failure'`, `Timeout = 'timeout'`, `DelayedSuccess = 'delayed_success'` |

---

## Phase 3 — Implementation Plan

### Phase 5 — Shipment Processing, Mock Provider & Webhook Deduplication

#### User story

**As the** System (Queue Worker, Webhook Receiver, and Developer)
**I want to** process packed shipments asynchronously, handle all provider outcomes safely, and demonstrate every failure mode deterministically
**So that** inventory is deducted exactly once on confirmed shipment, failures are visible and actionable, and the test harness proves correctness

**Acceptance Criteria:**

- [ ] AC-P5-1: `php artisan shipments:process` dispatches one `ProcessShipmentJob` per `packed` reservation; jobs run on the `database` queue driver
- [ ] AC-P5-2: On `success`: `packed -= qty_shipped`, `shipped += qty_shipped` on inventory + reservation; if all quantity shipped → reservation status `fulfilled`; else `partially_fulfilled`; `ShipmentAttempt.status = success`; movement (type `ship`) created
- [ ] AC-P5-3: On `permanent_failure`: shipment status → `failed`; packed stock NOT auto-released; flagged for operator review; `ShipmentAttempt.status = permanent_failure`
- [ ] AC-P5-4: On `timeout`: attempt status → `timeout`; `CheckShipmentStatusJob` dispatched for retry; no inventory change
- [ ] AC-P5-5: `POST /api/v1/webhooks/shipping` — if `event_id` already in `processed_webhook_events` → `200 OK` no-op; else process + insert `event_id` atomically in one transaction
- [ ] AC-P5-6: `MockShippingProvider` returns scenarios deterministically via `MOCK_SHIPPING_SCENARIO` env var or injected `$forceScenario` constructor param; falls back to weighted-random (60% success, 20% failure, 10% timeout, 10% delayed_success)
- [ ] AC-P5-7: Crash mid-job (exception before transaction commit) → rollback; inventory unchanged; clean retry re-checks `shipment.status` before applying — status guard prevents double-deduction
- [ ] AC-P5-8: Partial shipment: `quantity_shipped < quantity_packed` — only that portion moves `packed → shipped`; remainder stays `packed`; reservation stays `partially_fulfilled` until all shipped

**Expected Result:** Shipments are processed asynchronously with full visibility; every failure mode deterministic in tests; inventory never double-deducted or silently lost.

#### Assumptions

- A-P5-1: `shipments:process` only dispatches for reservations with `status = packed` (not `partially_fulfilled` or `open`)
- A-P5-2: Webhook endpoint authenticated with API key only — no Sanctum (`public.php` route file)
- A-P5-3: `ProcessShipmentJob`: `$tries = 3`, `$backoff = [10, 30, 60]` seconds; `failed(\Throwable)` hook marks shipment `failed`
- A-P5-4: `MockShippingProvider` bound in `AppServiceProvider` via `ShippingProviderInterface` — swap for real provider in future without touching job code
- A-P5-5: `shipments:process` creates a `Shipment` row if none in `pending` state; skips reservations already having a pending or in-transit shipment

#### Edge cases

- E1-P5: Job retry after timeout → `ShipmentService::confirmShipment()` guards `shipment->status !== ShipmentStatus::Shipped` before applying — if already shipped, late confirmation is a no-op
- E2-P5: Webhook for unknown `shipment_id` → `404` (not a hard error — provider may have wrong ID)
- E3-P5: Race between two identical webhooks → `processed_webhook_events.event_id` UNIQUE constraint; one insert succeeds, other gets `QueryException` with UNIQUE violation → caught → return "Event already processed" — `200 OK`
- E4-P5: `delayed_success` scenario → job marks attempt `delayed_success`, dispatches `CheckShipmentStatusJob` with a delay; that job calls provider again; if status now `success`, applies `confirmShipment()`

#### Files map

```
app/Core/Enums/
  ShipmentStatus.php                           [NEW]
  ShipmentAttemptStatus.php                    [NEW]
app/Models/
  Shipment.php                                 [NEW]
  ShipmentAttempt.php                          [NEW]
  ProcessedWebhookEvent.php                    [NEW]
app/Core/Services/Shipment/
  ShipmentService.php                          [NEW]
app/Core/Helpers/Shipping/
  ShippingProviderInterface.php                [NEW]
  MockShippingProvider.php                     [NEW]
app/Jobs/
  ProcessShipmentJob.php                       [NEW]
  CheckShipmentStatusJob.php                   [NEW]
app/Http/Requests/Webhook/
  ShippingWebhookRequest.php                   [NEW]
app/Http/Controllers/API/Webhook/
  ShippingWebhookController.php                [NEW]
app/Console/Commands/
  ProcessShipmentsCommand.php                  [NEW]
database/migrations/
  ..._create_shipments_table.php               [NEW]
  ..._create_shipment_attempts_table.php       [NEW]
  ..._create_processed_webhook_events_table.php [NEW]
database/factories/
  ShipmentFactory.php                          [NEW]
routes/apis/V1/public.php                      [MODIFY — add POST webhooks/shipping]
routes/console.php                             [MODIFY — optional: schedule shipments:process]
app/Providers/AppServiceProvider.php           [MODIFY — bind ShippingProviderInterface]
lang/en.json                                   [MODIFY]
lang/ar.json                                   [MODIFY]
tests/Feature/Shipment/
  ProcessShipmentJobTest.php                   [NEW]
  ShipmentWebhookTest.php                      [NEW]
  MockProviderTest.php                         [NEW]
```

#### Sub-phase 5.1 — Database & Setup

1. **Migration** `create_shipments_table`:
   - `$table->id()`
   - `$table->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete()`
   - `$table->unsignedInteger('quantity')`
   - `$table->string('status')->default(ShipmentStatus::Pending->value)->index()`
   - `$table->timestamps()`

2. **Migration** `create_shipment_attempts_table`:
   - `$table->id()`
   - `$table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete()`
   - `$table->unsignedInteger('attempt_number')`
   - `$table->string('status')`
   - `$table->json('provider_response')->nullable()`
   - `$table->timestamp('created_at')->useCurrent()`
   - *(no `updated_at`)*

3. **Migration** `create_processed_webhook_events_table`:
   - `$table->id()`
   - `$table->string('event_id')->unique()`
   - `$table->timestamp('processed_at')->useCurrent()`
   - *(no timestamps())*

4. **Enum** `ShipmentStatus`: `Pending = 'pending'`, `InTransit = 'in_transit'`, `Shipped = 'shipped'`, `Failed = 'failed'`, `Timeout = 'timeout'`

5. **Enum** `ShipmentAttemptStatus`: `Success = 'success'`, `PermanentFailure = 'permanent_failure'`, `Timeout = 'timeout'`, `DelayedSuccess = 'delayed_success'`

#### Sub-phase 5.2 — Full-stack

1. **Models**:
   - `Shipment`: `$fillable = ['reservation_id', 'quantity', 'status']`; cast `status → ShipmentStatus`; `belongsTo(Reservation::class)`, `hasMany(ShipmentAttempt::class)`
   - `ShipmentAttempt`: `$fillable = ['shipment_id', 'attempt_number', 'status', 'provider_response']`; cast `status → ShipmentAttemptStatus`, `provider_response → 'array'`; `public $timestamps = false`
   - `ProcessedWebhookEvent`: `$fillable = ['event_id']`; `public $timestamps = false`

2. **Interface** `ShippingProviderInterface`:
   ```
   ship(Shipment $shipment): array
   // returns: ['status' => ShipmentAttemptStatus, 'quantity_shipped' => int, 'raw' => array]
   ```

3. **`MockShippingProvider`** implements `ShippingProviderInterface`:
   - Constructor: `public function __construct(private ?string $forceScenario = null)`
   - `ship(Shipment $shipment): array`:
     - Scenario = `$this->forceScenario ?? env('MOCK_SHIPPING_SCENARIO')`
     - If scenario set → return that outcome deterministically
     - Else → weighted random: 60% success, 20% permanent_failure, 10% timeout, 10% delayed_success
     - Log every outcome: `Log::info('MockShippingProvider', ['shipment_id' => $shipment->id, 'outcome' => $scenario])`

4. **`ProcessShipmentJob`** (implements `ShouldQueue`):
   - `public int $tries = 3`
   - `public array $backoff = [10, 30, 60]`
   - `handle(ShipmentService $service)`: call `$service->processShipment($this->shipment)`
   - `failed(\Throwable $e)`: call `$service->markFailed($this->shipment, $e->getMessage())`

5. **`ShipmentService`**:
   - `processShipment(Shipment $shipment)`:
     - `DB::transaction()`: lock `inventory` row with `lockForUpdate()`
     - Call `$this->shippingProvider->ship($shipment)`
     - Match on `status`:
       - `success` → call `confirmShipment($shipment, $result['quantity_shipped'])`
       - `permanent_failure` → call `markFailed($shipment)`
       - `timeout` → create attempt with status `timeout`; dispatch `CheckShipmentStatusJob::dispatch($shipment)->delay(60)`
       - `delayed_success` → create attempt with status `delayed_success`; dispatch `CheckShipmentStatusJob`

   - `confirmShipment(Shipment $shipment, int $qty)`:
     - Guard: `$shipment->status === ShipmentStatus::Shipped` → return (no-op — late confirmation)
     - `DB::transaction()`:
     - Lock `inventory`; `quantity_packed -= $qty`; `quantity_shipped += $qty`; save
     - `$reservation->quantity_shipped += $qty`; save
     - If `reservation->quantity_shipped >= reservation->quantity` → `status = Fulfilled`; else `PartiallyFulfilled`
     - Create `InventoryMovement` (type `MovementType::Ship`, `quantity_delta = $qty`)
     - Create `ReservationHistory` (to_status = new reservation status)
     - `$shipment->status = ShipmentStatus::Shipped`; save
     - Create `ShipmentAttempt` (status `Success`)

   - `markFailed(Shipment $shipment, ?string $reason = null)`:
     - `$shipment->status = ShipmentStatus::Failed`; save
     - Create `ShipmentAttempt` (status `PermanentFailure`)
     - Do **not** touch inventory — flagged for operator review

6. **`ProcessShipmentsCommand`** (`shipments:process`):
   - Query `Reservation::query()->where('status', ReservationStatus::Packed->value)->get()`
   - For each reservation: check if a `Shipment` with status `pending` or `in_transit` exists; if not → create new `Shipment` and dispatch `ProcessShipmentJob::dispatch($shipment)->onQueue('default')`

7. **`ShippingWebhookController::handle(ShippingWebhookRequest $request)`**:
   - Check `ProcessedWebhookEvent::where('event_id', $request->event_id)->exists()` → return `sendSuccessResponse([], __('Event already processed'))`
   - Else:
     ```
     DB::transaction(function () use ($request) {
         ProcessedWebhookEvent::create(['event_id' => $request->event_id]);
         $shipment = Shipment::findOrFail($request->shipment_id);
         $this->shipmentService->confirmShipment($shipment, $request->quantity_shipped);
     });
     ```
   - Catch `QueryException` with UNIQUE violation code → return "Event already processed" (race condition)
   - Return `sendSuccessResponse([], __('Webhook processed'))`

8. **FormRequest** `ShippingWebhookRequest`:
   - `rules()`: `event_id` required string; `shipment_id` required integer; `status` required `Rule::in(['success', 'permanent_failure', 'timeout'])`; `quantity_shipped` required integer min:1
   - `messages()` + `attributes()`

9. **Routes** in `public.php`:
   ```php
   Route::post('webhooks/shipping', [ShippingWebhookController::class, 'handle'])->name('webhooks.shipping');
   ```

10. **AppServiceProvider binding**:
    ```php
    $this->app->bind(ShippingProviderInterface::class, MockShippingProvider::class);
    ```

11. **lang keys** — add to both JSON files:
    - `"Webhook processed": "Webhook processed"` / `"تم معالجة الطلب"`
    - `"Event already processed": "Event already processed"` / `"تم معالجة الحدث مسبقاً"`
    - `"Shipment not found": "Shipment not found"` / `"الشحنة غير موجودة"`
    - `"Shipment confirmed": "Shipment confirmed"` / `"تم تأكيد الشحنة"`
    - `"Shipment failed": "Shipment failed"` / `"فشلت الشحنة"`
    - `"Shipment timed out": "Shipment timed out"` / `"انتهت مهلة الشحنة"`

12. Run `vendor/bin/pint --dirty` before commit

#### Tests (Phase 5)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: success → packed -= qty; shipped += qty; status=fulfilled; movement type=ship | Feature | tests/Feature/Shipment/ProcessShipmentJobTest.php | inventory correct; shipment.status=shipped; attempt.status=success |
| Happy: partial shipment (qty_shipped < qty_packed) → partially_fulfilled | Feature | tests/Feature/Shipment/ProcessShipmentJobTest.php | remaining stays packed; reservation.status=partially_fulfilled |
| Sad: permanent_failure → packed inventory unchanged; shipment.status=failed | Feature | tests/Feature/Shipment/ProcessShipmentJobTest.php | quantity_packed unchanged; no movement created |
| Sad: timeout → attempt.status=timeout; inventory unchanged; CheckShipmentStatusJob dispatched | Feature | tests/Feature/Shipment/ProcessShipmentJobTest.php | no inventory change; job queued |
| Sad: crash mid-job (exception before commit) → rollback; inventory unchanged | Feature | tests/Feature/Shipment/ProcessShipmentJobTest.php | all inventory unchanged after exception |
| Happy: crash then clean retry succeeds | Feature | tests/Feature/Shipment/ProcessShipmentJobTest.php | second attempt applies correctly; no double-deduction |
| Happy: late confirmation on already-shipped → no-op | Feature | tests/Feature/Shipment/ProcessShipmentJobTest.php | status guard blocks; inventory unchanged; returns gracefully |
| Happy: webhook processed once | Feature | tests/Feature/Shipment/ShipmentWebhookTest.php | 200; inventory updated; event_id in processed_webhook_events |
| Happy: duplicate webhook → 200 no-op | Feature | tests/Feature/Shipment/ShipmentWebhookTest.php | second call returns 200; no second inventory change; single movement row |
| Sad: webhook for unknown shipment_id → 404 | Feature | tests/Feature/Shipment/ShipmentWebhookTest.php | 404 |
| Happy: MOCK_SHIPPING_SCENARIO=success always succeeds | Feature | tests/Feature/Shipment/MockProviderTest.php | 10 calls all return success outcome |
| Happy: MOCK_SHIPPING_SCENARIO=permanent_failure always fails | Feature | tests/Feature/Shipment/MockProviderTest.php | all calls return failure |
| Happy: forceScenario constructor param overrides env | Feature | tests/Feature/Shipment/MockProviderTest.php | injected param takes priority |

#### cURL Smoke Tests (Phase 5)

```bash
# Step 1 — dispatch shipments (ensure a packed reservation exists first)
php artisan shipments:process

# Step 2 — run one job from queue
php artisan queue:work --once

# Step 3 — confirm via webhook (success)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/webhooks/shipping \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -d "{\"event_id\":\"evt_001\",\"shipment_id\":1,\"status\":\"success\",\"quantity_shipped\":3}"

# Step 4 — duplicate webhook (expect 200 no-op)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/webhooks/shipping \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -d "{\"event_id\":\"evt_001\",\"shipment_id\":1,\"status\":\"success\",\"quantity_shipped\":3}"

# Step 5 — force failure scenario
# Set MOCK_SHIPPING_SCENARIO=permanent_failure in .env, then:
php artisan shipments:process
php artisan queue:work --once
```

#### Complexity tracking

- `ponytail:` `ProcessShipmentJob` uses a status guard (`shipment.status !== Shipped`) rather than a full saga pattern. Ceiling: if a crash occurs after `inventory.save()` but before `ShipmentAttempt::create()`, the retry will see status still `Shipped` and no-op correctly (inventory already updated). The only risk is a missing attempt record, not incorrect inventory. Upgrade path: write attempt record **first** (as `pending`), then update inventory, then mark attempt `success` — three steps but fully auditable.
- `ponytail:` `MockShippingProvider` bound as a singleton in `AppServiceProvider`. In tests, override with `$this->app->instance(ShippingProviderInterface::class, new MockShippingProvider('success'))` for deterministic scenarios — no need for a separate test double class.
