# GoHighLevel Services, Rentals, Products & Variants — Mental Model & POS Integration Research

**Purpose:** Nail down exactly how GHL's Services, Rentals, Products, Categories, and Variants relate to each other under the hood, so you can build a custom POS/booking app on top of the API without getting bitten by ID mismatches.

**Status legend used throughout:**
- 📘 **Documented** — confirmed in official HighLevel docs
- 🔍 **Empirically observed** — matches what you're seeing in your account (Lakeside Pine Retreat Campsite case), not written down anywhere officially
- ⚠️ **Needs verification** — plausible inference, confirm with a live test before you build on it

---

## 1. The Three Layers GHL Is Actually Built From

GHL doesn't have one unified "catalog" system. It has **three separate subsystems** that all *feel* like they're the same "products" concept in the UI, but are backed by different data models:

| Layer | Where you see it | What it's "really" for |
|---|---|---|
| **Scheduling layer** | Calendars → Services, Calendars → Rentals, Calendars → Meetings | Booking logic: staff, availability, duration, resources, listings |
| **Commerce layer** | Payments → Products | The actual sellable SKU: name, price, variants, tax, inventory, Stripe/Square sync |
| **Storefront/Org layer** | Payments → Products → Categories/Collections; Services → Categories; Rentals → Categories | Grouping/display buckets — three *different* category systems, not one |

Every bookable "thing" (a service, a rental listing, an add-on) needs a Payments-layer Product to actually take money, issue invoices, and show up in Stripe. That's why creating a Service silently creates a Product behind it 📘 — HighLevel's own docs confirm this pairing exists (their FAQ says "inventory tracking for **products linked to services**" — plural, confirming every service has an underlying product record).

This is the root of your confusion: **you're looking at one business object (a bookable offering) through three different lenses (Calendar/Service, Payments/Product, Store/Collection), and each lens has its own ID and its own rules for what a "variant" means.**

---

## 2. The Commerce Layer — How Products/Variants/Prices *Actually* Work (documented, classic e-commerce model)

This is the official, documented model used by **Payments → Products** (the classic store/e-commerce catalog):

```
Product (ONE _id)
 ├─ name, description, productType (PHYSICAL | DIGITAL), image, availableInStore
 ├─ variants: [ { id, name: "Color", options: [{id, name:"Red"}, {id, name:"Blue"}] },
 │              { id, name: "Size",  options: [{id, name:"Small"}, {id, name:"Large"}] } ]
 └─ Prices (MULTIPLE, separate objects, each with its own _id)
      ├─ Price A: variantOptionIds: [Red, Small] → amount, sku, stock
      ├─ Price B: variantOptionIds: [Red, Large] → amount, sku, stock
      └─ Price C: variantOptionIds: [Blue, Small] → amount, sku, stock
```

Key facts, straight from HighLevel's public API docs:
- A "Product with Variants" is **ONE product document**. Variants live *inside* it as a `variants` array of option-groups (e.g. Color, Size).
- Each concrete combination (Red/Small, Red/Large…) gets its own **Price** object, created via a separate `POST /products/{productId}/price` call, referencing `variantOptionIds`.
- Images can be mapped to a specific Price via `priceIds` on the media object — so even photo-per-variant is handled at the Price level, not by creating new Products.
- `productType` is documented as `PHYSICAL` or `DIGITAL`. That's the officially published enum.

**So in the classic store model: 1 product → N variant-option-groups → N prices. Never N products.**

This is *not* what you're seeing with Services — which tells you Services doesn't reuse this engine faithfully. See §3.

---

## 3. The Scheduling Layer — Services 🔍

Services (Calendar → Calendar Settings → Services) is a newer module, layered on top of the old service-calendar system 📘 ("when you enable Services, HighLevel automatically copies your existing service calendars and creates equivalent Services"). It borrows *concepts* from the commerce layer (Variations, pricing, categories) but does **not** appear to store variations the same way classic Products do.

Based on what you're observing in your account (Lakeside Pine Retreat Campsite), here's the pattern:

### 3.1 Base creation
- Creating a Service ("Lakeside Pine Retreat Campsite") auto-provisions **one Product** in Payments with the same name. 🔍 This is the "original" product — its `_id` is what the Service's base/first variation references.
- This Product is (empirically) tagged with a `productType` that is *not* the publicly documented `PHYSICAL`/`DIGITAL` — you're seeing some records come back as **"service" type** and others as **"digital" type**. ⚠️ Needs verification, but the working theory: 
  - The **first/base Price or variation** may inherit `productType` that reflects the parent Service (effectively a service-booking SKU).
  - Add-ons or certain variation types can land as `DIGITAL` because add-ons/no-inventory items are internally modeled like digital goods (no shipping, no physical stock).

### 3.2 Adding a Variation inside a Service
- This is the key divergence from the classic model. Instead of adding a `variants` option-group + new `Price` to the *same* product (like the T-shirt example in §2), each **Variation** you add inside a Service appears to provision **its own standalone Product record** in Payments. 🔍
- The naming convention you're seeing — `Lakeside Pine Retreat Campsite` (base) + `Lakeside Pine Retreat Campsite - <Variant Name>` (each variation) — is consistent with GHL auto-generating `"{ServiceName} - {VariationName}"` as the Product `name` field for every variation, rather than writing a `variantOptionIds`-linked Price under one parent product.
- Net effect: **one Service in the Calendar UI can correspond to *multiple* Products in Payments** — not one product with a variants array. This breaks the mental model from §2.

### 3.3 Categories
- Service Categories (Calendar Settings → Services → Categories) are **their own object**, separate from Payments → Products → Categories/Collections, and separate again from Rentals Categories. 📘 (Confirmed: HighLevel docs explicitly describe three separate category UIs — Services categories, Rentals categories/"Apartments, Vehicles, Equipment" style, and Store Collections with Manual/Smart types.)
- When a Service (and its auto-generated Products) get assigned a category, that same grouping *may* also surface the underlying Products inside a Payments Collection carrying a similar name — but they are **not guaranteed to be the same `categoryId`**. Treat Service-category and Product-category as two separate foreign keys that happen to be kept in sync by GHL's internal logic, not something you can rely on structurally. ⚠️

---

## 4. The Scheduling Layer — Rentals 🔍📘

Rentals is architecturally similar to Services but explicitly documented as a **separate scheduling type** from Services/Meetings 📘 — "Rentals introduces its own booking flow, invoicing logic, and global configuration." Key documented facts:

- Rentals uses **Listings** (not "Services") as the base bookable object.
- Listings live under **Categories** (e.g., Apartments, Vehicles, Equipment) — again, a *third*, independent category system.
- Listings explicitly support **"listing variants, inventory, and pricing adjustments over time"** and **"quantity-based and multi-item checkouts."** 📘
- Given the "Manage stock and variants" language and the multi-item checkout design, Rentals variants likely follow the **same per-variant-Product pattern** as Services (each variant = its own Product record), since Rentals needs independent stock/quantity tracking per variant — which is much easier to model as separate Products than as sub-Price objects. ⚠️ Needs verification, but very likely given it needs independent inventory counts per variant (a Lakeside cabin vs a Lakeside RV site, for instance, need separate stock, not just separate price tiers).

---

## 5. Product Types You're Seeing — Working Theory

You mentioned some auto-created products come back as **"service" type** and others as **"digital" type**. The publicly documented `productType` enum only lists `PHYSICAL` and `DIGITAL`. The most likely explanation:

| What you see | Likely internal reason |
|---|---|
| `productType: DIGITAL` | Anything with no shipping/no physical inventory defaults here — this includes most Service/Rental booking products, since HighLevel's public schema doesn't have a first-class `SERVICE` product type exposed via API (even though the UI may label it "Service" contextually) |
| Something labeled "service" in UI but `DIGITAL` under the hood | The UI is doing context-aware labeling based on which Calendar object (Service vs Rental vs Store) created the product, not a different underlying `productType` enum value |

⚠️ **Confirm this with a raw API pull** (see §8) — pull the raw JSON for a couple of your auto-created products and check the literal `productType` field value. If you find a genuine `SERVICE` enum value, that contradicts the public docs and is worth documenting for your own reference (and worth flagging to HighLevel support/GitHub issues, since undocumented enum values change without notice).

---

## 6. "First Variant = Original Product" Behavior — What's Actually Happening

Your observation: when you call the Service API, it lists variations *nested inside* the service object, but the **first variation behaves like the "real"/original product**, while the other variations behave like **standalone services/products** when queried directly.

This is consistent with the model in §3:

```
Service "Lakeside Pine Retreat Campsite" (Calendar object)
 ├─ variations: [
 │     { name: "Standard", productId: <SAME _id as the base auto-created product> },   ← "original" product
 │     { name: "Lakeside Premium", productId: <NEW, separate product _id> },
 │     { name: "Family Pack", productId: <ANOTHER separate product _id> }
 │   ]
```

- The **Service object** (from the Calendar/Services API) is the source of truth for booking logic (staff, duration, availability) and holds a `variations` array with references (`productId`/`priceId`) into Payments.
- The **base/first variation** was likely created *at the same moment* as the Service itself, so it inherited the Service's own auto-generated Product — making it look like "the" product for that service.
- **Every subsequent variation you add** is *not* retrofitted into that same product's `variants`/Price array (unlike the classic e-commerce model in §2). Instead GHL provisions a **new Product**, named `{Service Name} - {Variation Name}`, and links it back via a `productId`/`priceId` reference stored on the variation object inside the Service.
- Consequence: if you call **Payments → Products (list)** independently of the Services API, you will see the base product *and* every variation product *as separate, flat, independently-listed products* — each with the *same Category* (if you assigned one), but *different `_id`s*, and no explicit "parent/child" field connecting them back to each other **except by matching against the Service's `variations[].productId` array.**

**Practical implication:** the *Service object* is your only reliable source for "which products belong together as variations of the same bookable thing." The Products list alone cannot tell you that 3 products are actually 1 service with 3 tiers — you must cross-reference via the Service's `variations` array.

---

## 7. Category/Collection Confusion — Summary Table

| System | Where | Groups what | Type field |
|---|---|---|---|
| Service Categories | Calendar Settings → Services → Categories | Services (and by extension their auto-created products) | Its own `categoryId`, service-scoped |
| Rentals Categories | Rentals → Categories (e.g. Apartments, Vehicles, Equipment) | Rental Listings | Its own `categoryId`, rental-scoped |
| Store Categories/Collections | Payments → Products → Collections (Manual or Smart) | Raw Products (all types, incl. auto-created service/rental products) | `categoryId` on the Product record itself; Smart Collections use rule-based auto-inclusion (price, title, inventory, etc.) |

A product can appear in a Store Collection even though it was auto-generated by a Service — but the Service Category and the Store Collection are **not the same object**, even if named identically. Don't assume `service.categoryId === product.categoryId`. Always resolve category names, not just IDs, when reconciling across the three systems, or better: don't rely on category matching at all — rely on the `productId` linkage from the Service/Listing object.

---

## 8. Verification Test Plan (do this before writing sync logic)

Since GHL's public docs don't describe the Service→Product duplication behavior, treat §3, §5, §6 as a hypothesis and confirm empirically:

1. **Create a throwaway test Service** with 1 base config + 2 variations, distinct prices.
2. Immediately pull:
   - `GET /calendars/services/{serviceId}` (or the equivalent Services endpoint your MCP exposes) → capture the full `variations` array, note every `productId`/`priceId`.
   - `GET /products?locationId=...` → find all products with names containing your test service name.
3. Compare: does the base variation's `productId` equal the product auto-created at the moment you saved the Service (check `createdAt` timestamps)? Do the 2 variation products have separate `_id`s with `createdAt` matching when you added each variation?
4. Pull the raw `productType` field on each of the 3 products — confirm literal enum values (not just UI labels).
5. Check `availableInStore` on each — auto-created service products are often `false` (hidden from storefront) even though they're real Products; this matters if your POS reads from a Products-list endpoint and expects `availableInStore: true` to know what's sellable.
6. Repeat the same test with a Rentals Listing (1 base + 2 variants) to confirm §4's hypothesis about per-variant Products for Rentals too.
7. Delete/adjust a variation from the Service afterward → confirm whether the underlying Product gets deleted, archived, or orphaned (this affects your sync/cleanup logic).

---

## 9. Architecture Recommendation for Your Custom POS/Booking App

Given the above, here's how to structure your integration so it doesn't break when GHL's internal product-duplication quirk surfaces:

1. **Treat the Service/Listing object as the parent entity, not the Product.** Your POS's "bookable item" table should key off `serviceId` (or `listingId` for Rentals), not `productId`.
2. **Store a mapping table**: `service_variation_id → productId → priceId`, refreshed whenever you sync. Don't hardcode assumptions about which `productId` is "the original" — just record it as `is_base_variation: true/false` based on whichever variation's `productId` matches the Service's own creation-time product.
3. **Never resolve "all products for this service" by name-matching** (`"Lakeside Pine Retreat Campsite - *"`) — that's fragile (renames break it, duplicate names across categories break it). Always resolve via the Service's `variations[].productId` array as the source of truth.
4. **For your POS checkout/booking flow**, when a customer picks a variation, use the *service's own booking endpoint* (create-appointment/create-booking) with the `variationId`, not a generic "add product to cart" flow — payment collection for services is documented to run through the Services module's own payment integration (Stripe/Square/Razorpay/Authorize.net/NMI), separate from a standard Store checkout.
5. **Inventory**: don't rely on Product-level `trackInventory`/`availableQuantity` for Services — HighLevel's own FAQ confirms inventory tracking for service-linked products is *not* synced to the booking lifecycle. If you need real stock/capacity limits (e.g., "only 3 campsites available on this date"), that has to come from Rentals' own inventory/quantity fields or from your own Supabase-side capacity table tied to bookings, not from the Product record.
6. **Categories**: pick one system as your canonical grouping for the POS UI (recommend: Service Categories / Rentals Categories, since that's what the booking widget itself uses) and treat Store Collections as a display-only concern if you ever expose an e-commerce storefront alongside the booking app.
7. **Webhooks**: subscribe to `ProductCreate`/`ProductUpdate` *and* whatever Service/Booking-created/updated events your calendar exposes, so your POS's local cache stays in sync without polling — given GHL creates/renames Products as a side effect of editing Services, a Product-only webhook won't tell you *why* a product changed; you need the Service-side event too to keep your mapping table correct.

---

## 10. Open Questions to Resolve With HighLevel Support / GitHub Issues

If you want authoritative answers instead of empirical inference, these are worth filing on the [highlevel-api-docs GitHub](https://github.com/GoHighLevel/highlevel-api-docs) or asking Support directly, since they're not covered by current public docs:

- Is there a documented, literal `productType` value for service/rental-generated products, or are they always `DIGITAL` under the hood?
- Is the "one product per Service variation" behavior intentional/permanent, or a legacy artifact that might get refactored to use the classic `variants[]` + multi-Price model (like Store Products) in a future release?
- Is there a public API field that explicitly links a variation-Product back to its parent Service (beyond reverse-lookup via the Service's own `variations` array)? That would remove the need for you to maintain your own mapping table.
- Do Rentals Listings use the same per-variant-Product duplication as Services, or a different internal model?

---

## Sources
- HighLevel Support: *Create a Product with Price using Public API* (Products/Variants/Prices data model)
- HighLevel Support: *How to Set Up Products & Start Selling Online* (Products, Variants section, one-time vs recurring)
- HighLevel Support: *Services: Create New Services with Resources & Add-ons* (Variations, inventory-sync FAQ)
- HighLevel Support: *Enable Services for a Sub-Account(s)* (Services overview, payment providers, service variations)
- HighLevel Support: *Calendar View for Rentals* / *Introducing Rentals* changelog (Listings, categories, variants, quantity/inventory)
- HighLevel Support: *Manual and Smart Collections in HighLevel Stores* (Store Collections as a third, separate grouping system)
- HighLevel Support: *Ecommerce: Dropshipping Integration* (documented `productType` enum: PHYSICAL/DIGITAL)
