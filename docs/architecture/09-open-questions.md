# 09. Open Questions and Assumptions

Status: **Answered 2026-10-24.** The owner accepted every recommended default below, so each default is now the decision. Revisit a row only if its "Blocks" phase turns up new facts.

## 1. Questions

| # | Question | Why it matters | Recommended default | Blocks |
|---|---|---|---|---|
| **Q1** | Plugin slug: keep `flexacommerce` or use `flexa-commerce` like the other Flexa plugins? | The WordPress.org slug is permanent. It also sets the text domain, option names and REST namespace. | Keep `flexacommerce` (matches the existing folder, shorter hooks). | Phase 1 |
| **Q2** | Must MVP calculate tax (VAT/GST/US sales tax), or launch with "prices are final, no tax lines"? | The largest scope question. A real tax engine adds rate tables, shipping tax, rounding and tax-inclusive display. Many EU stores cannot legally sell without tax lines. | Launch M1 without a tax engine (ADR-015) and decide target markets before phase 4. If EU stores are the target, add a simple rate-table engine (standard rate per country, prices inclusive) as P0 in phase 4, about +3 weeks. | Phase 4 |
| Q3 | Distribution: free plugin on WordPress.org with paid add-ons, or commercial only? | Affects guideline compliance work, licensing code (none in free), and support. | WordPress.org free core (assumption A2), add-ons as separate plugins. | Phase 7 |
| **Q4** | Which online gateway comes first after 1.0, and is it first-party? | Validates the gateway contract with webhooks, refunds and signed returns before it freezes at 1.0. | Stripe, first-party add-on, prototyped against the contract during phase 7 so contract changes happen before 1.0. | 1.0 contract freeze |
| Q5 | PHP minimum: 8.2 or 8.3? | PHP 8.2 security support ends 31 Dec 2026. 1.0 is likely to ship after that. | Start with 8.2, decide at phase 7 from WordPress.org PHP usage stats. Code avoids 8.3-only syntax until then. | Phase 7 |
| Q6 | Oldest WooCommerce version supported as a migration source? | Testing cost versus reach. HPOS behavior differs in older versions. | 9.0 (HPOS mature, CRUD stable); older versions get a preflight warning, not a block. | Phase 6 |
| Q7 | Account creation at checkout: guest only, optional account, or required? | Affects checkout form, emails (new account), privacy. | Guest checkout plus optional "create an account" checkbox, with WordPress's own password-set email. | Phase 4 |
| Q8 | Which countries' address formats and required fields are needed at launch? | Field schema work and test fixtures. | Use a bundled country table (states, postcode required flag) for all countries, deep testing for US, UK, DE, FR, VN, PT. | Phase 4 |
| **Q9** | Which classic theme is the reference for compatibility testing? | Classic theme markup differs. One must be the test target. | Astra (large classic install base), plus Twenty Twenty-One as the core classic theme. | Phase 5 |
| Q10 | Admin orders and customers: React screens (house UI) or WordPress list tables? | React matches other Flexa plugins and allows richer filtering; list tables are cheaper and match core. | React screens using the Flexa UI system (consistency with the lineage). | Phase 4 |
| Q11 | Is name search on orders required to be fast at 100k+ orders? | Unindexed `LIKE '%x%'` (doc 03 §5) or FULLTEXT index (different behavior across MySQL/MariaDB). | Start without FULLTEXT; measure in the benchmark. | Phase 7 |
| Q12 | FXC→WC: is storing the FlexaCommerce order number in `_order_number` meta acceptable, given WooCommerce core displays the order id? | Customers may see a different number in WooCommerce. | Accept, document, and recommend a sequential order number plugin for stores that need identical numbers. | Phase 6 |
| Q13 | Are product reviews part of MVP? | Reviews need moderation UI, rating aggregates, schema markup. | Not in MVP. Product `comment_status` is copied; core comments are shown with the theme's comment template if enabled. | Phase 5 |
| Q14 | During migration, should reviews (comments) be moved to the new product post? | Reviews stay on the WooCommerce post id now (doc 05 §5.2) and become invisible after cutover. | Add a P1 step in phase 6 that re-parents product review comments to the FlexaCommerce post at cutover (reversible via the id map). | Phase 6 |
| Q15 | Are gapless invoice numbers a legal requirement for target markets? | Order numbers are unique but not gapless (doc 03 §2.7). | Not in MVP; a post-MVP invoice module issues gapless invoice numbers separately. | Post-MVP |
| Q16 | Default `hold_minutes` for unpaid online orders: 60? | Trade-off between abandoned orders holding stock and slow payers. | 60, setting exposed. | Phase 4 |
| Q17 | Naming and trademark: may the readme and admin UI say "Migrate from WooCommerce"? | WordPress.org guideline 17 and trademark policies on third-party names. | Allowed as a descriptive reference ("Migrate from WooCommerce"), never in the plugin name or slug. Legal check before 1.0. | Phase 7 |
| Q18 | Benchmark hardware: who provides a stable machine? | Results must be reproducible. | A dedicated VPS with fixed specs, documented in the report. Needed from phase 2.5 (doc 10 §6.1), not only phase 7. | Phase 2.5 |
| Q19 | Mini-cart count: REST call per page view, or a non-sensitive count cookie? | The REST call costs one HTTP request per page view for visitors with a cart; the cookie can be stale across tabs (doc 10 §3.7). | Keep REST (doc 02 §7.3.1) unless the phase 3 T3 run shows the extra request is significant. **Changed in phase 3 (2026-10-25):** the count cookie, because the REST call is a full WordPress boot per page view for every visitor with a cart (about 14 queries, 10 ms server time on the bench stack, `docs/benchmarks/2026-10-25.md`) while the cookie costs nothing on the server; REST stays as the fallback for a logged-in shopper with no count cookie (doc 10 §3.7). | Phase 3 |
| Q20 | Which WooCommerce features does the "tuned" benchmark configuration turn off? | Too few makes the comparison flattering to us, too many makes it unrepresentative (doc 10 §6.3). | Only features with no FlexaCommerce equivalent at that milestone, list published with each run, reviewed by someone outside the project. | Phase 3 |

Bold questions were the ones to answer before implementation started. Decisions taken: Q1 keep `flexacommerce`; Q2 no tax engine in M1, target markets decided before phase 4; Q4 Stripe as a first-party add-on; Q9 Astra plus Twenty Twenty-One; Q3, Q5 to Q8 and Q10 to Q18 as recommended.

## 2. Assumptions to validate

| # | Assumption (doc 01 §7) | How to validate | If wrong |
|---|---|---|---|
| A1 | Typical catalog ≤ 10,000 products, ≤ 100,000 orders | Interviews with 5 target stores, WooCommerce usage data | Pull the product lookup table (doc 03 §5) into 1.0 |
| A2 | WordPress.org distribution | Owner decision (Q3) | Licensing and update mechanism work |
| A3 | Migration on one site | Ask migrating beta stores | File transport moves into 1.0 (canonical format is ready) |
| A4 | One currency, set before the first order | Target market check | Multi-currency is a separate project |
| A5 | Physical goods only, whole quantities | Target market check | Digital product type, decimal quantity design |
| A6 | Team has PHP/WordPress/MySQL skills | Team review | Adjust estimates in doc 07 |

## 3. Facts checked during planning that may change

| Fact | Source | Re-check when |
|---|---|---|
| WooCommerce 11.0.1 order creation, stock and email behavior (doc 05 §2) | WooCommerce source on the local reference site | Each WooCommerce major release (nightly test covers it) |
| WordPress 7.1 API availability (`register_block_template`, metadata collections, Interactivity API, `%i`) | WordPress source and developer docs | Before setting the final minimum WP version |
| PHP 8.2 end of security support (31 Dec 2026) | php.net supported versions page | Phase 7 |
