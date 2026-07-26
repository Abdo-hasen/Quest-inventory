Quest Brief
👋 We are Innov8, a software company building digital solutions that help businesses streamline their operations across multiple industries. Our products include ERP systems, Logistics Management Systems, and POS solutions, helping organizations automate workflows, manage inventory, optimize deliveries, and improve operational efficiency.

We're hiring a Full Stack PHP Developer (1-2 YOE) to join our engineering team and build business-critical modules that handle inventory movement, warehouse operations, purchase orders, deliveries, and financial transactions.

🕓 Start Date: Immediate

🌍 Location: Hybrid (Smouha, Alexandria)

     (Alexandria residents are preferred)

💰 Salary: 15K-20K EGP

🛠️ How the Hiring Quest Works
Register for the quest

Receive the full challenge after registration closes

Submit your solution before the deadline

Top candidates will be invited to a technical review session

One candidate will be hired — outstanding candidates may also be considered for future opportunities

🔍 Who We're Looking For
✅ 1-2 years of professional PHP development experience 

✅ Strong knowledge of Laravel and/or CodeIgniter

✅ Excellent understanding of OOP, SOLID principles, and common Design Patterns

✅ Experience with Dependency Injection and ORM

✅ Strong SQL and MySQL optimization skills

✅ Good understanding of JavaScript, jQuery, Bootstrap, HTML, and CSS

✅ Experience designing and consuming REST APIs

✅ Strong understanding of web security (OWASP Top 10, Authentication, Authorization, SQL Injection, XSS, CSRF, Input Validation)

✅ Experience with Git

✅ Comfortable working within Agile/Scrum teams

⭐ Bonus

React.js experience

Docker experience

Automated testing experience

🧩 The Challenge — "Warehouse Inventory Reservation Engine"
The Story
Innov8 provides ERP and Logistics software used by companies operating multiple warehouses.

When customers place sales orders, inventory cannot simply be deducted immediately.

Instead, products move through several business stages:

Available inventory

Reserved inventory

Picked inventory

Packed inventory

Shipped inventory

Delivered inventory

Meanwhile, many things may happen:

Multiple users reserve inventory simultaneously.

Warehouse operators cancel reservations.

Orders are edited after reservation.

Shipments fail.

Orders are partially fulfilled.

Background jobs may retry.

External shipping providers may send duplicate webhooks.

Inventory must never become negative.

The same inventory must never be reserved twice.

Your task is to design the Inventory Reservation Engine, the core component responsible for ensuring inventory always remains correct regardless of retries, duplicate events, or concurrent operations.

Think of this as the heart of an ERP system where inventory correctness is more important than feature count.

What the System Must Do
Build the inventory core responsible for:

Managing stock across warehouses

Reserving inventory safely

Releasing reservations

Confirming shipment

Handling partial shipments

Recording inventory movements

Receiving duplicate shipment callbacks safely

Maintaining inventory consistency even when jobs fail

At any point, the system should answer:

Current available stock

Reserved stock

Picked stock

Shipped stock

Inventory movement history

Which reservations remain open

Which orders have already consumed inventory

Real World Conditions
Your design should safely handle situations such as:

Two users reserve the last remaining item simultaneously.

The reservation command runs twice.

The same background job executes multiple times.

A shipment webhook arrives more than once.

A worker crashes halfway through processing.

Orders are partially cancelled after reservation.

Products are transferred between warehouses while reservations exist.

Inventory updates must remain consistent under concurrent requests.

Some Business Rules Are Intentionally Missing
Real ERP systems rarely have perfectly defined requirements.

For example:

Can reservations expire?

How should partial shipments affect reservations?

Can inventory be transferred while reserved?

Should reservations lock inventory immediately?

How should overselling be prevented?

These decisions are intentionally left open.

Choose sensible rules.

Document your assumptions.

Explain your trade-offs.

We are evaluating your engineering judgment as much as your implementation.

📦 What to Hand In
You do not need to build an entire ERP.

Focus on building the inventory engine correctly.

Required
1. Database Schema + Migrations
Include tables for:

Products

Warehouses

Inventory

Reservations

Orders

Shipment records

Inventory movements

Reservation history

2. Inventory Reservation Logic
Implement the business logic responsible for:

Reserving stock

Preventing overselling

Releasing reservations

Partial reservation handling

Reservation expiration (if you choose to support it)

3. Shipment Processing
Implement an Artisan command and queued jobs that:

Process pending shipments

Confirm inventory deduction

Handle retries safely

Prevent duplicate shipment processing

4. Mock Shipping Provider
Implement a fake shipping provider that randomly:

Succeeds

Fails permanently

Times out

Sends duplicate delivery confirmations

Delays confirmation before reporting success

Show how your design safely handles every case.

🤖 AI Usage Policy
AI tools are allowed.

However, this quest is designed to evaluate your engineering judgment, architectural thinking, and understanding of your solution—not just the final implementation.

Please include:

/docs/AI_USAGE.md

Cover the following:

How you used AI during the task

The main prompts or workflows you relied on

Which parts were fully generated versus manually designed or modified

The engineering decisions you personally made

What differentiates your solution from other submissions

Any architectural trade-offs or improvements you intentionally chose

You do not need to include every prompt.

Provide enough information for us to understand your workflow and level of ownership.

During the technical review we may ask you to:

Explain any part of your implementation

Modify part of the solution live

Justify architectural decisions

Discuss alternative designs

Explain why specific trade-offs were chosen

Submissions demonstrating strong understanding will score significantly higher than submissions that are merely feature-complete.

🎥 Video Submission (Required)
Submit a 15–20 minute walkthrough.

We're evaluating your engineering thinking—not just the finished code.

1. Introduction (2–3 min)
Introduce yourself.

Briefly discuss previous projects involving:

ERP systems

Inventory

Warehousing

Logistics

POS

Large CRUD business systems

Explain why you chose your architecture.

2. Architecture Walkthrough (5 minutes)
Explain:

Domain model

Database schema

Reservation lifecycle

Inventory movement strategy

Concurrency handling

Security considerations

Key architectural decisions

Design patterns used

SOLID principles applied

3. Failure Scenario Demonstration (5–7 minutes)
Demonstrate at least five scenarios:

Two users reserve the last item simultaneously

Running reservation twice

Duplicate shipment webhook

Worker retry after failure

Shipment timeout followed by confirmation

Reservation cancellation

Partial shipment

Inventory transfer

Concurrent requests attempting to update inventory

SQL transaction rollback after failure

Explain why inventory remains correct after each scenario.

4. Testing Strategy (2–3 minutes)
Explain:

What you tested

Why these tests matter

Which business risks they protect against

How unit tests validate business rules

5. AI Usage & Engineering Decisions (2–3 minutes)
Explain:

Where AI helped

What AI-generated suggestions you rejected

Important engineering decisions you made independently

How your solution differs from a typical AI-generated implementation

6. Future Improvements (1–2 minutes)
If this system were running in production:

What would you improve?

How would you scale it?

What remaining risks exist?

How would you support millions of inventory transactions?

🧰 Tech Stack
PHP 8+

Laravel (preferred) or CodeIgniter

MySQL

JavaScript

Bootstrap

jQuery

Git

Optional:

React.js

Docker

PHPUnit or Pest

📝 What You Should Submit
Repository
GitHub or GitLab containing:

Source code

Database migrations

Seeders

Factories

Tests

Documentation
README.md
Include:

Setup instructions

How to run the project

How to run tests

Assumptions

Known limitations

/docs/ARCHITECTURE.md

Include:

Domain model

Reservation lifecycle

Inventory movement strategy

Concurrency handling

Database design decisions

Security considerations

Scaling strategy

Trade-offs

Future improvements

/docs/AI_USAGE.md

Include the AI usage explanation described above.

Evidence
Include:

Screenshot of passing test cases

Video walkthrough link

📊 Evaluation Criteria
Area
Weight

Inventory Correctness & Business Logic 25%

Concurrency Handling & Data Integrity 20%

System Design & Architecture 20%

Laravel/PHP Implementation Quality 15%

Security Best Practices 10%

Testing Strategy & Coverage 5%

Documentation, AI Transparency & Engineering Reasoning 5%

👉 Final hiring decisions will be made within 3–5 business days after the technical review.