# AI Usage & Engineering Decision Log

This document details how AI assistance (Google Gemini via Antigravity Agentic IDE) was utilized in the design, planning, implementation, and verification of the **Warehouse Inventory Reservation Engine**.

---

## 1. Overview of AI Integration

AI was leveraged as an agentic pair-programmer throughout the development lifecycle. Rather than generating code blindly, AI was operated under strict custom instructions, domain rules, and a layered architectural framework to build a production-grade Laravel 12 API.

### Core Areas of AI Assistance
- **Architecture Planning**: Generating initial domain schemas, entity boundary definitions (OMS vs. WMS), and reservation state transition models.
- **Service & Domain Implementation**: Writing transactional services (`OrderService`, `ReservationService`, `ShipmentService`, `TransferService`) with pessimistic database locks.
- **Validation & Security Layers**: Drafting `FormRequest` validation classes, Sanctum policies, and backed enums (`UserRole`, `ReservationStatus`, `MovementType`, `ShipmentStatus`).
- **Comprehensive Pest Testing**: Generating 112 Pest feature tests covering happy paths, edge cases, validation failures (422), unauthorized requests (401/403), and race condition simulations.

---

## 2. Custom Agentic Skills, Workflows & System Rules Applied

To eliminate common AI coding mistakes and enforce strict production standards, custom agentic workflows and phase-by-phase guidelines were defined for this repository:

1. **Spec-Driven & Agile Custom Skills**:
   - Authored custom agentic skills inspired by Agile principles and spec-driven methodologies (similar to Spec Kit):
     - **Implementation Skill**: Executes phase-by-phase implementation plans in strict sequence with immediate Pint linting and Pest test validation.
     - **Security & Code Review Skill**: Performs deep code reviews focusing on security boundaries, concurrency locks, edge cases, and architectural alignment.
     - **Laravel Best Practices Skill**: Enforces standard Laravel 12 patterns and clean layered separation.

2. **Phase-by-Phase Execution & Documentation Folders**:
   - The project was planned and implemented strictly phase-by-phase using structured documentation:
     - **Overview & Master Plans**: [`Docs/Overview-Plans/`](Overview-Plans) contains overall requirements, DB specs, and phase breakdowns.
     - **Phase Specifications**: [`Docs/user-stories-plans/`](user-stories-plans) contains detailed specifications (`p1` to `p8`) outlining exact requirements, models, routes, services, and tests for each phase.
     - **Architecture Documentation**: [`Docs/ARCHITECTURE/`](ARCHITECTURE) holds database schemas (`DB-ARCHITECTURE.md`) and user story architecture breakdowns (`Stories-Architect.md`).
   - Every phase followed a strict execution cycle: **Phase Plan Review → Implementation → Pest Test Verification → Phase Sign-off**.

3. **Standardized Workflow & Guardrails**:
   - Established strict system rules to prevent frequent AI pitfalls (e.g., redundant service providers, buried logic in views/controllers, or incorrect framework conventions).
   - Enforced thin controllers delegating exclusively to dedicated service classes wrapped in `DB::transaction()`.
   - Enforced unified JSON response structure (`InteractWithResponse` trait).

4. **Lazy Senior Developer ("Ponytail" Mode)**:
   - Mindset focused on maximum efficiency and code minimalism: write only what is needed, leverage native PHP/Laravel features, avoid premature abstractions, and fix root causes at the function level rather than patching caller symptoms.

5. **Laravel Boost & Security Reviews**:
   - Strictly followed Laravel 12 backend standards while continuously auditing security boundaries, OWASP mass-assignment `$fillable` models, webhook idempotency, and pessimistic DB locks (`lockForUpdate()`).

---

## 3. Human Engineering Approach, Consultation & Trade-offs

### Human-First Consultation & Brainstorming Process
Development followed a human-directed engineering approach:
1. **Initial Problem Analysis & Architecture**: System scope, domain entity relationships, OMS vs. WMS boundaries, and concurrency requirements were first analyzed and brainstormed manually.
2. **AI as Technical Consultant**: AI was consulted as an expert advisor to discuss potential trade-offs, review community standards (Laravel & REST best practices), evaluate alternatives, and refine execution steps before implementation.

### Architectural Trade-offs & Engineering Decisions
*(For detailed architectural specifications, see [`Docs/ARCHITECTURE.md`](ARCHITECTURE.md))*

- **Pessimistic Database Locks (`lockForUpdate()`) vs. Distributed Locks**:
  - *Decision*: Selected DB-level pessimistic locks inside transactions over Redis locks to achieve strict, atomic consistency without external infrastructure complexity.
- **Deadlock Avoidance in Multi-Row Transfers**:
  - *Decision*: Implemented mandatory ascending ID sorting prior to locking rows during inter-warehouse transfers.
- **Materialized Snapshot + Immutable Append-Only Ledger**:
  - *Decision*: Paired materialized `inventories` quantity counters with append-only `inventory_movements` and `reservation_histories` for full operational auditability.
- **OWASP-Compliant Mass Assignment (`$fillable`)**:
  - *Decision*: Maintained explicit `$fillable` attributes on models instead of global unguarding to protect API boundaries against attribute injection.

### Human Design vs. AI Generation Breakdown
- **Manually Designed & Directed (Human-Led)**:
  - System architecture design and entity boundary definitions (OMS vs. WMS).
  - State machine transition logic (Reserve → Pick → Pack → Ship / Expire / Release).
  - Concurrency & deadlock prevention strategies (`lockForUpdate()` with sorted IDs).
  - RBAC permission matrix (`admin`, `order_creator`, `warehouse_operator`).
  - Custom agent rules & skills (`Ponytail` mode, spec-driven implementation, security review skill).
- **AI-Assisted / Generated (Supervised Execution)**:
  - Initial database migration schema boilerplate.
  - FormRequest validation rule arrays (`rules()`, `messages()`, `attributes()`).
  - API resource transformer structures and Pest feature test assertions.

---

## 4. Key Differentiating Factors

1. **Production & SaaS System Engineering Experience**:
   - The design is informed by real-world production experience building high-availability backend systems and SaaS platforms. Critical edge cases—such as webhook replay protection, race conditions, partial cancellations, and stale reservations—were designed into the core system from day one.

2. **Continuous AI Tooling & Workflow Optimization**:
   - Rather than accepting generic, unconstrained AI code, the workflow leverages customized agentic skills, strict verification gates, and continuous experimentation with state-of-the-art AI developer tools to deliver clean, maintainable, and audit-ready production code.

---

## 5. Verification & Quality Gates

All implementations passed a strict two-tier verification process:

1. **Automated Pest Test Suite**:
   - Executed `php artisan test` after every feature layer implementation.
   - **Result**: 112 passing feature tests (469 assertions) covering API endpoints, concurrency locks, state transitions, and edge cases.

2. **Live HTTP Smoke Testing**:
   - Simulated real client requests against `http://127.0.0.1:8000` with Sanctum Bearer tokens and `X-API-KEY` headers.
   - Verified exact response payload adherence to the `InteractWithResponse` standard contract.
