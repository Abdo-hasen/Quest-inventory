# P1 — Authentication & Authorization Implementation Plan

## Phase 3 — Implementation Plan

### Phase 1 — Authentication Base (Migration, Enum, User Model, Login/Logout Endpoints & Response Contract)

#### User story

**As an** API user (Admin, Order Creator, or Warehouse Operator)  
**I want to** authenticate using my credentials (`email` and `password`) to receive a Bearer token, and be able to revoke it via logout  
**So that** I can securely access protected inventory endpoints according to my assigned role.

**Acceptance Criteria:**

- [x] AC-P1-1: Migration adds `role` column (`string`, default `'order_creator'`) to `users` table.
- [x] AC-P1-2: `UserRole` backed enum (`admin`, `order_creator`, `warehouse_operator`) created and cast on `User` model (`'role' => UserRole::class`).
- [x] AC-P1-3: `User` model uses `Laravel\Sanctum\HasApiTokens` and protects `$fillable` attributes (`name`, `email`, `password`, `role`).
- [x] AC-P1-4: `POST /api/login` validates credentials with `LoginRequest`, checks password hash, creates Sanctum token, and returns 200 OK with `{ token, role }` using `InteractWithResponse` trait shape.
- [x] AC-P1-5: Invalid credentials return 401 Unauthorized via `InteractWithResponse`.
- [x] AC-P1-6: `POST /api/logout` (protected by `auth:sanctum`) revokes current token and returns 200 OK using `InteractWithResponse`.


**Expected Result:** Users can log in to obtain a Bearer token containing their role and log out to revoke it.

#### Assumptions
- A-P1-1: Single-tenant system (no `organization_id`).
- A-P1-2: User accounts are created via admin seeding or CLI (no public registration endpoint per spec).
- A-P1-3: Sanctum's `personal_access_tokens` migration is published and run.

#### Files map
```
app/
├── Core/
│   ├── Enums/
│   │   └── UserRole.php (NEW)
│   └── Services/
│       └── Auth/
│           └── AuthService.php (NEW)
├── Http/
│   ├── Controllers/
│   │   └── API/
│   │       └── Auth/
│   │           └── AuthController.php (NEW)
│   └── Requests/
│       └── Auth/
│           └── LoginRequest.php (NEW)
└── Models/
    └── User.php (MODIFY - add HasApiTokens trait, role in $fillable, role cast)
database/
└── migrations/
    └── XXXX_XX_XX_XXXXXX_add_role_to_users_table.php (NEW)
lang/
├── ar.json (NEW)
└── en.json (NEW)
routes/
└── api.php (NEW)
```

#### Sub-phase 1.1 — Database & Setup
1. Create `UserRole` backed string enum in `app/Core/Enums/UserRole.php` (`Admin = 'admin'`, `OrderCreator = 'order_creator'`, `WarehouseOperator = 'warehouse_operator'`).
2. Create migration `add_role_to_users_table`: add `$table->string('role')->default(UserRole::OrderCreator->value)->after('email')`.
3. Update `User` model (`app/Models/User.php`):
   - Import `Laravel\Sanctum\HasApiTokens`.
   - Include `HasApiTokens` trait.
   - Add `'role'` to `$fillable`.
   - Cast `'role' => UserRole::class`.

#### Sub-phase 1.2 — Full-stack
1. Create `LoginRequest` in `app/Http/Requests/Auth/LoginRequest.php`:
   - Rules: `'email' => ['required', 'string', 'email']`, `'password' => ['required', 'string']`.
   - Implement `attributes()` and `messages()` using `__()`.
2. Create `AuthService` in `app/Core/Services/Auth/AuthService.php`:
   - `login(array $credentials)`: locates user, verifies password, creates Sanctum token, returns `['token' => $token, 'role' => $user->role->value]`.
   - `logout(User $user)`: revokes `$user->currentAccessToken()->delete()`.
3. Create `AuthController` in `app/Http/Controllers/API/Auth/AuthController.php`:
   - Uses `InteractWithResponse` trait.
   - Injects `AuthService`.
   - `login(LoginRequest $request)`: calls service, returns `sendSuccessResponse(...)` or `sendFailedResponse(__('Invalid credentials'), 401)`.
   - `logout(Request $request)`: calls service, returns `sendSuccessResponse([], __('Logged out successfully'))`.
4. Define routes in `routes/api.php`:
   - `POST /api/login` -> `AuthController@login`
   - `POST /api/logout` -> `AuthController@logout` (protected by `auth:sanctum`).
5. Add translation keys to `lang/en.json` and `lang/ar.json`.
6. Code style: run `vendor/bin/pint --dirty`.

#### API Contract

**`POST /api/login`**

Request:
```json
{
  "email": "operator@warehouse.com",
  "password": "secret123"
}
```

Response 200 OK:
```json
{
  "ok": true,
  "code": 200,
  "message": "Login successful",
  "direct": null,
  "data": {
    "token": "1|abcdef...",
    "role": "warehouse_operator"
  }
}
```

Response 401 Unauthorized:
```json
{
  "ok": false,
  "code": 401,
  "message": "Invalid credentials",
  "direct": null,
  "data": null
}
```

**`POST /api/logout`** *(auth:sanctum required)*

Header: `Authorization: Bearer <token>`

Response 200 OK:
```json
{
  "ok": true,
  "code": 200,
  "message": "Logged out successfully",
  "direct": null,
  "data": []
}
```

#### Tests (this phase)
| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: Successful login returns token & role | Feature | `tests/Feature/Auth/LoginTest.php` | `assertStatus(200)`, `jsonPath('ok', true)`, `jsonPath('data.token', ...)` |
| Sad: Invalid password returns 401 | Feature | `tests/Feature/Auth/LoginTest.php` | `assertStatus(401)`, `jsonPath('ok', false)` |
| Sad: Missing fields return 422 | Feature | `tests/Feature/Auth/LoginTest.php` | `assertStatus(422)` |
| Happy: Authenticated logout revokes token | Feature | `tests/Feature/Auth/LogoutTest.php` | `assertStatus(200)`, token deleted in DB |
| Sad: Unauthenticated logout returns 401 | Feature | `tests/Feature/Auth/LogoutTest.php` | `assertStatus(401)` |

#### Edge cases
- E1-P1: Non-existent email returns 401 "Invalid credentials" (prevents user enumeration).
- E2-P1: Logout deletes `currentAccessToken()` specifically, preserving other active sessions if applicable.

---

### Phase 2 — Authorization & Gate Definition (Gate Rules, `can:` Middleware Wiring & 403 Enforcement)

#### User story

**As a** System Administrator  
**I want** fine-grained authorization gates defined for all 10 domain actions (`manage-products`, `manage-warehouses`, `manage-users`, `adjust-stock`, `create-orders`, `view-own-orders`, `manage-reservations`, `pick-pack-ship`, `transfer-stock`, `view-inventory`)  
**So that** HTTP endpoints and policies enforce role boundaries and block unauthorized requests with 403 Forbidden.

**Acceptance Criteria:**

- [x] AC-P2-1: 10 domain Gates defined in `AppServiceProvider::boot()` via `Gate::define()`.
- [x] AC-P2-2: Gate checks evaluate `$user->role` enum against assigned roles:
  - `admin` -> `manage-products`, `manage-warehouses`, `manage-users`, `adjust-stock`, `view-inventory`
  - `order_creator` -> `create-orders`, `view-own-orders`, `view-inventory`
  - `warehouse_operator` -> `manage-reservations`, `pick-pack-ship`, `transfer-stock`, `view-inventory`
- [x] AC-P2-3: `can:<ability>` route middleware protects endpoints.
- [x] AC-P2-4: Unauthorized access returns 403 Forbidden JSON matching `InteractWithResponse` contract.


**Expected Result:** Domain actions and endpoints enforce role boundaries, denying unauthorized requests with 403 Forbidden.

#### Assumptions
- A-P2-1: Gates evaluate `$user->role` directly against `UserRole` enum cases.
- A-P2-2: `can:` middleware maps to standard `Illuminate\Auth\Middleware\Authorize`.

#### Files map
```
app/
└── Providers/
    └── AppServiceProvider.php (MODIFY - define 10 domain Gates in boot())
tests/
└── Feature/
    └── Auth/
        └── AuthorizationGateTest.php (NEW)
```

#### Sub-phase 2.1 — Full-stack
1. Update `app/Providers/AppServiceProvider.php`:
   - Import `Illuminate\Support\Facades\Gate` and `App\Core\Enums\UserRole`.
   - In `boot()`, define the 10 domain gates:
     - `manage-products`: `$user->role === UserRole::Admin`
     - `manage-warehouses`: `$user->role === UserRole::Admin`
     - `manage-users`: `$user->role === UserRole::Admin`
     - `adjust-stock`: `$user->role === UserRole::Admin`
     - `create-orders`: `$user->role === UserRole::OrderCreator`
     - `view-own-orders`: `$user->role === UserRole::OrderCreator`
     - `manage-reservations`: `$user->role === UserRole::WarehouseOperator`
     - `pick-pack-ship`: `$user->role === UserRole::WarehouseOperator`
     - `transfer-stock`: `$user->role === UserRole::WarehouseOperator`
     - `view-inventory`: `in_array($user->role, [UserRole::Admin, UserRole::OrderCreator, UserRole::WarehouseOperator])`
2. Write Pest feature test `tests/Feature/Auth/AuthorizationGateTest.php` to verify all 10 gates across all 3 roles.
3. Code style: run `vendor/bin/pint --dirty`.

#### Tests (this phase)
| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: Admin user passes `manage-products` Gate | Feature | `tests/Feature/Auth/AuthorizationGateTest.php` | `Gate::forUser($admin)->allows('manage-products') === true` |
| Sad: OrderCreator blocked from `manage-products` Gate | Feature | `tests/Feature/Auth/AuthorizationGateTest.php` | `Gate::forUser($orderCreator)->allows('manage-products') === false` |
| Happy: OrderCreator passes `create-orders` Gate | Feature | `tests/Feature/Auth/AuthorizationGateTest.php` | `Gate::forUser($orderCreator)->allows('create-orders') === true` |
| Sad: WarehouseOperator blocked from `create-orders` Gate | Feature | `tests/Feature/Auth/AuthorizationGateTest.php` | `Gate::forUser($operator)->allows('create-orders') === false` |
| Happy: WarehouseOperator passes `pick-pack-ship` Gate | Feature | `tests/Feature/Auth/AuthorizationGateTest.php` | `Gate::forUser($operator)->allows('pick-pack-ship') === true` |
| Happy: All 3 roles pass `view-inventory` Gate | Feature | `tests/Feature/Auth/AuthorizationGateTest.php` | `Gate::forUser($anyRole)->allows('view-inventory') === true` |
| Sad: Route protected by `can:manage-products` returns 403 for non-admin | Feature | `tests/Feature/Auth/AuthorizationGateTest.php` | `actingAs($operator)->getJson('/test-route')->assertStatus(403)` |

#### Edge cases
- E1-P2: Users without a valid role assigned fail all gate checks by default.
