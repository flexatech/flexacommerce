# 11. Checkout builder (design)

Status: part 1 (fields) and part 2 (sections and layout) built, 2026-10-27; decision records ADR-031 and ADR-032 in doc 08. Part 3 is built too: the field conditions (§11, ADR-033), the steps layout (§12, ADR-034), the coupon box (§13, ADR-035) and the Checkout Field Editor import (§14, ADR-036). Where the parts differ from the first draft of this page, §9 to §14 say so.

## 1. Goal

A merchant arranges the checkout page by dragging, without code. They can:

- move whole sections and put them in the left or the right column;
- reorder the fields of the billing and shipping addresses, rename them, make them half width, and set each one to required, optional or hidden;
- add their own fields (gift message, delivery date, VAT number, "how did you hear about us") and see the answers on the order.

The checkout keeps working without JavaScript, keeps its accessibility (labels, error summary, `aria-invalid`), and stays inside the performance budgets of doc 10. A store that never opens the builder looks exactly as it does today.

## 2. What it changes for the merchant

Today three settings shape the form (`checkout_company`, `checkout_phone`, `checkout_address_2`) and everything else needs the `flexacommerce.checkout.fields` filter. After this:

| Today | With the builder |
|---|---|
| Company, phone and address line 2: hidden, optional or required in Settings | Same choice on the field itself, in the builder; the three settings move into the layout and leave `Settings` (3 keys freed under the 64-key cap) |
| Field order fixed in code | Drag fields within an address |
| Labels fixed | Rename a label; an empty label goes back to the translated default |
| Every field full width | Full or half width (first name and last name side by side by default) |
| Extra fields need PHP | Add text, long text, dropdown, radio buttons, checkbox, date, number, phone and email fields |
| Two fixed columns | Move sections between the columns, or choose one column |
| Summary always on the right | On phones, the order summary can sit at the top, folded (`<details>`, works without JS) |

## 3. The builder screen

FlexaCommerce > Checkout (a new submenu after Promotions). One screen, three areas:

```
+--------------------------------------------------------------------------------------+
| Checkout                         [Desktop | Phone]   [Preview on site]  [Save]       |
+------------------+----------------------------------------------+--------------------+
| ADD              |  Main column                 | Side column   | FIELD              |
|                  |  +------------------------+  | +-----------+ |                    |
| Fields           |  | :: Billing details     |  | |:: Shipping| | Label              |
|  T  Text         |  |  [First name][Last nm] |  | |   method  | | [Gift message   ]  |
|  ¶  Long text    |  |  [Email address      ] |  | +-----------+ | Placeholder        |
|  v  Dropdown     |  |  [Country / Region   ] |  | |:: Order   | | [               ]  |
|  o  Radio        |  |  [Street address     ] |  | |   summary | | Help text          |
|  x  Checkbox     |  |  ...                   |  | +-----------+ | [               ]  |
|  #  Number       |  +------------------------+  | |:: Payment | | Width              |
|  d  Date         |  | :: Shipping address    |  | |  + Place  | | (o) Full ( ) Half  |
|  @  Email        |  +------------------------+  | |    order  | | Answer             |
|  p  Phone        |  | :: Additional info     |  | +-----------+ | (o) Optional       |
|                  |  |  [Gift message       ] |  |               | ( ) Required       |
| Content          |  |  [Order notes        ] |  |               | ( ) Hidden         |
|  H  Heading      |  +------------------------+  |               | [x] Show in emails |
|  ≡  Paragraph    |                              |               | [Delete field]     |
+------------------+----------------------------------------------+--------------------+
| Layout: (o) Two columns ( ) One column    On phones, summary: (o) Top, folded ( ) End |
+--------------------------------------------------------------------------------------+
```

- **Left, Add:** drag an item onto a section, or click it to add it to the selected section.
- **Middle, canvas:** a drawing of the checkout with the real labels, widths and required marks. It is not the live theme; theme styles vary too much to fake. The canvas shows what goes where.
- **Right, inspector:** the selected field or section. Fields: label, placeholder, help text, width, answer (required, optional, hidden), choices for dropdown and radio, and "Show in emails and on the order". Sections: title, and buttons to move it up, down or to the other column.
- **Preview on site** opens the real checkout page in a new tab with the unsaved layout, in the active theme. The draft lives in a per-user transient for an hour; the preview URL carries a nonce, and only users who can manage settings see it. With an empty cart the preview adds one sample product to a throwaway cart, so the page has something to show.
- **Reset to default** is a quiet button in the header, with a confirm dialog. While there are unsaved changes the same place shows **Undo changes**.
- Save stays disabled until something changed; leaving with unsaved changes asks first.

### Accessibility of the builder

Dragging is never the only way. Every handle is a button: Space lifts the item, the arrow keys move it, Space drops it, Escape cancels, and a live region reads "Phone, moved to position 4 of 9 in Billing details". The inspector also has Move up, Move down and Move to the other column. This is what `@dnd-kit` gives with its keyboard sensor and announcements.

## 4. Sections

| Section | Can hide | Rules |
|---|---|---|
| Billing details | No | Holds the email. |
| Shipping address | No | Shown only when the cart needs shipping, with its "Ship to a different address" box. |
| Additional information | Yes | Custom fields and the order notes. Hidden by default until it has something in it. |
| Order notes | Yes | A field of Additional information, so it can be dragged within it. |
| Shipping method | No | Shown only when the cart needs shipping. |
| Order summary | No | The total must stay on the page near the button. |
| Payment | No | Always the last section of its column, with the terms box and the Place order button at its end. The builder does not let anything be dropped under it. |
| Heading, Paragraph | Yes | Content items, allowed in any section. Plain text with links (`wp_kses` with `a`, `strong`, `em`). |

Default layout = today's page: main column with Billing details, Shipping address, Additional information (order notes); side column with Shipping method, Order summary, Payment.

## 5. Fields

### Core address fields

- Can be reordered, renamed, given a placeholder and help text, set to half width.
- Country, and Email in billing, stay required and visible. The builder shows them with a lock and a line saying why.
- State and Postcode follow the country (some countries have no states or postcodes). The builder offers "Hidden" only for them, never "Required" or "Optional"; their label still changes with the country unless the merchant typed one.
- Changes apply to the account page address form too, since both use `FieldSchema`.

### Custom fields

- Types: text, long text, dropdown, radio, checkbox, date, number, phone, email. Dropdown and radio keep choices with a stable value.
- Each field lives in Billing, Shipping or Additional information.
- The id is made once from the first label (`gift_message`, then `gift_message_2`) and never changes after that, so old orders keep their answers when the label is edited.
- Values are stored in order meta, as extension fields are now: `_billing_{id}`, `_shipping_{id}` and `_additional_{id}`.
- Answers appear on the admin order screen, the order confirmation, the account order view and, when "Show in emails" is on, the customer and admin emails. Each line reads with the label the field had when the order was placed (one `_fxc_fields` meta holds the label, the answer and the email switch of every custom field on the order), so renaming a field later does not rewrite history.
- Custom fields are not saved to the customer's address book in this version.
- Deleting a field keeps the answers on old orders.

## 6. How it works

- **Storage:** one option, `flexacommerce_checkout_layout`, autoload off. It holds only what differs from the default: section order and columns, and per field the overrides and the custom field definitions. A store without the option renders the default, which is today's markup.
- **Reading:** `Checkout\CheckoutLayout` (value object, `from_array()`, `to_array()`, `default()`) normalises the option once per request. `FieldSchema::defaults()` applies it before the `flexacommerce.checkout.fields` filter, so extensions still see and can change the final list. `CheckoutValidator` uses the same schema, so a field the builder makes required is checked on the server. `CheckoutField` gains the new types, `width`, `placeholder`, `help` and `choices`.
- **Rendering:** `CheckoutForm` walks the sections in the stored order and prints them into one or two columns. Field ids, names and the `data-fxc-*` hooks stay the same. `checkout.js` finds things by those hooks and by `.fxc-checkout__form` only (checked), so it keeps working when sections move.
- **Settings move:** a one-off upgrade copies `checkout_company`, `checkout_phone` and `checkout_address_2` into the layout and drops them from `Settings::DEFAULTS`. Reading them in old code paths goes through the layout from then on.
- **REST:** `GET`, `PUT` and `DELETE flexacommerce/v1/admin/checkout-layout` behind `manage_permission` (part 1), plus `POST …/preview` that stores the draft (part 2). The PUT validates the whole layout server side (known section ids, Payment last, no duplicate field ids, choice values unique, lengths capped) and returns the normalised layout.
- **Hooks:** `flexacommerce.checkout.layout` (filter on the normalised layout) and `flexacommerce.checkout.layout.saved` (action).
- **Admin bundle:** the builder is its own lazy chunk; `@dnd-kit/core` and `@dnd-kit/sortable` (the chunk is about 24 KB gzip with the builder itself) load only on this screen.

### Budgets (doc 10)

- No new storefront JavaScript.
- `storefront.css` grows by the half-width grid and the folded summary, about 300 B gzip, under the 10240 B cap.
- One option read on the checkout page and the checkout REST routes. It is cached by the object cache, and no other page loads it.
- `Settings` goes from 64 to 61 keys, which leaves room under the cap that `FrontendBudgetTest` enforces.

## 7. Parts and order of work

1. **Fields** (first commit): `CheckoutLayout`, field overrides (order, label, placeholder, help, width, answer), custom fields with the new types, order meta and display on the order screen, confirmation, account and emails, the settings move. Builder screen with the Billing, Shipping and Additional information sections, drag and drop, keyboard moves, inspector.
2. **Sections and layout:** moving sections between columns, one-column layout, the folded summary on phones, Heading and Paragraph, Preview on site.
3. **Later, each on its own when asked:** show a field only for some countries, for carts that need shipping, or for carts with some products or categories; a steps layout (Information, Shipping, Payment) that falls back to one page without JS; a coupon box on the checkout; importing the WooCommerce Checkout Field Editor options (`wc_fields_billing`, `wc_fields_shipping`, `wc_fields_additional`) in Migration.

## 8. Tests

- Integration: default layout renders the same markup as today (snapshot of today's form); overrides change order, labels and widths; a custom required field blocks the order with an error linked to it; custom answers land in order meta with their label; deleting a field keeps old answers; locked fields cannot be hidden through the REST route; Payment cannot be moved under another section; the settings move keeps a store's company and phone choices; the filter still runs after the layout.
- WooCommerce suite: migration writes no layout meta it cannot read back.
- Budgets: `CheckoutBudgetTest` with a 20-field layout; `FrontendBudgetTest` unchanged on non-checkout pages.
- E2E with axe: build a layout in the admin with the keyboard only, save, place an order on TT5, Astra and TT1.

## 9. What part 1 built

Part 1 shipped the fields; §10 covers the sections, columns, content items and Preview on site of part 2. Differences from the draft above:

- **Default widths.** First name and last name are half width in the default layout, in billing and shipping. The default form looks as before at phone width, where half fields stack.
- **One meta for the answers.** Each custom answer is order meta `_{section}_{id}` (`_additional_gift_message`), and `_fxc_fields` is a JSON list of `{key, label, value, email}` written at checkout. Every screen and email reads that list (`Orders\OrderFields`), so a renamed or deleted field still shows on old orders. Privacy export lists the answers and erase removes both.
- **REST.** `GET`, `PUT` and `DELETE /admin/checkout-layout`. GET returns `fields` (the layout), `default` and `labels` (the translated default label of each core field, which the builder shows as the placeholder of an empty label). A refused PUT answers 400 `flexacommerce_invalid_checkout_layout` with `data.field` set to the path at fault (`billing.0.answer`), and the builder selects that field and shows the message under the setting.
- **Additional fields filter.** Custom fields of Additional information pass through `flexacommerce.checkout.additional_fields`, the counterpart of `flexacommerce.checkout.fields` for the address sections.
- **Inspector moves.** Move up, Move down and a Section select (custom fields only); core fields stay in their section, which the drag targets also enforce.
- **Ids.** A new field gets its id from its label on the first save (accents dropped, `đ` read as `d`, `field_` added when the label starts with a digit), and keeps it.
- **Required custom fields** carry `required` like the address fields, so the browser stops an empty one first; the server checks it again and links the error.
- **Admin header.** With nine tabs the header scrolls sideways inside itself on narrow screens, and the open tab is scrolled into view.


## 10. What part 2 built

Part 2 shipped the page: sections in two columns or one, the folded summary on phones, headings and paragraphs, and Preview on site. Differences from the draft above:

- **The page is a second part of the layout.** The option keeps `fields` and gains `page`: `columns` (`two` or `one`), `summary` (`end` or `top`), and the `main` and `side` lists. A list holds the six sections (`billing`, `shipping`, `additional`, `shipping_method`, `summary`, `payment`), each with an optional `title`, and the merchant's headings and paragraphs. A store without `page` renders the form of before, byte for byte.
- **Headings and paragraphs sit between sections,** in a column, not inside a section. Ids are `text_` and up to 16 lowercase letters or digits, at most 20 items. A heading is plain text (120 characters); a paragraph keeps `a[href]`, `strong` and `em` and is wrapped with `wpautop` (1000 characters). An empty one is refused at `page.{column}.{index}.text`.
- **Every section stays on the page.** A PUT that leaves one out is refused at `page`. There is no hide switch on a section: Additional information disappears when all its fields are hidden, and Shipping address and Shipping show only when the cart needs shipping.
- **Payment closes its column,** with the terms and the Place order button. The server refuses anything under it (`page.side.1.id`); the builder keeps it last after every move, and the drop targets never put a block under it.
- **One column** stores every block in `main`, in reading order. Switching back in the builder puts the blocks after the last field section in the side column, or restores the split the page had when the order did not change in between.
- **Folded summary.** With `summary: top` the order summary is a `<details open>` element. CSS moves it to the top of the form on phones (`display: contents` on the columns, `order: -1`), and `checkout.js` closes it there and keeps it open on wider screens, where its own line is hidden. The line shows the title (default "Order summary") and the total, kept current by the totals refresh. Without JavaScript it stays open.
- **Titles.** A section title replaces the heading, legend or caption the section prints; empty means the translated default. Payment uses it as the legend of the payment methods.
- **Preview on site.** `POST /admin/checkout-layout/preview` checks the unsaved layout like a save and keeps it in a transient for its author (one hour). It answers with the checkout URL and `fxc_preview` set to a nonce. The checkout shows the draft only to that user, with that nonce and the manage capability. An empty cart gets a sample product and a flat rate, a notice says nothing can be ordered, and Place order is disabled. Requotes over REST still use the saved layout.
- **Builder.** The canvas draws the page: the columns side by side when the canvas is wide enough (container query, 42rem), sections as cards with their fields inside, and sketches of Shipping, the summary and Payment. Sections, headings and paragraphs drag by their grip within and between the columns; fields keep dragging inside their sections, and each kind of drag only sees its own drop targets, so the keyboard moves stay predictable. The section inspector has the title, Move up, Move down and a Column select; the content inspector the type and the text. From 768px the inspector sits beside the canvas.

## 11. What part 3 built: field conditions

The first item of part 3: a field of the merchant's can show only for some countries, for some carts, or both. Core address fields have no conditions; their answer (Required, Optional, Hidden) is enough, and the address formats already follow the country.

- **Stored shape.** Each custom entry has `when: {countries, shipping, products, categories}`. `countries` is a list of country codes, `shipping` is `any`, `ships` or `no_shipping`, and `products` and `categories` are lists of ids, at most 100 in each list. Every part left empty means always, and a layout saved before part 3 reads as always. A PUT with an unknown country or shipping value is refused at `billing.0.when.countries` or `.when.shipping`; on read the bad parts are dropped.
- **How the parts combine.** The country, the shipping part and the products part must all pass. Inside the products part, products and categories are either-or: the field shows when the cart holds one of the products, or a product in one of the categories. A product in a subcategory counts for its parent categories.
- **Which country.** Billing fields follow the billing country and shipping fields the shipping country. Additional information follows the country the order ships to: the shipping address when "Ship to a different address" is ticked, else the billing address.
- **Cart conditions are checked on the server.** The cart does not change on the checkout page, so `Checkout\CartFacts` (whether the order ships, the product ids, the category ids with their parents) is built once per render or requote, and a field the cart does not meet is left out of the schema. Category ids are read only when a field of the layout names a category (`CheckoutLayout::names_categories()`), so a checkout without one makes no term query.
- **Country conditions are checked on the page and on the server.** The shopper can change the country, so the field stays in the schema with `CheckoutField::$countries`. The form prints it with `data-fxc-countries="DE FR"`; for another country it is `hidden` and its inputs `disabled`, so nothing is posted and the browser does not ask for it. `checkout.js` shows and hides it when a country changes or the shipping address is toggled. The validator skips a field whose `shown_in($country)` is false: it is not checked and its answer is not stored.
- **Builder.** The inspector of a custom field that is not hidden has a "Show this field" group: a country list with chips, a Cart select (Any cart, Only when the order ships, Only when nothing ships), and product and category pickers that search like the coupon form. The canvas marks the field "Conditional". The country list is the store's sell-to countries plus any code a field already names, sent as `countries` with the GET.
- **Preview on site** treats the sample cart as one that ships and holds no product, so a field limited to products or categories does not show there.
- **Tests.** Unit: cleaning and refusing `when`, `CartFacts::allows`, the schema leaving out a field, the validator skipping fields for other countries. Integration: the rendered attributes, a subcategory match, an order placed without a field for another country. E2E: a field for Germany shows and hides as the shopper changes country, and the order goes through from the US.

## 12. What part 3 built: steps layout

The second item of part 3: the merchant can split the checkout into Information, Shipping and Payment, shown one at a time. Without JavaScript the same page is one form, as before.

- **Stored shape.** `page.flow` is `page` (the default) or `steps`. A layout saved before part 3 reads as `page`. A PUT with another value is refused at `page.flow`; on read it falls back to `page`.
- **Which step a block is in.** Each section has a fixed step: Billing details, Shipping address and Additional information go in Information, Shipping (the rates) in Shipping, Payment in Payment. The order summary shows in every step. A heading or paragraph goes with the first section under it in its column, or with the nearest one above it when none follows; next to the summary it shows in every step. `CheckoutLayout::step()` holds the rule and `stepOf()` in `pageModel.ts` mirrors it for the builder.
- **No shipping step for a cart that does not ship.** The server leaves the step out of the list and the rates section renders nothing.
- **Markup.** The form gets `data-fxc-flow="steps"`. Before the columns comes `<nav class="fxc-checkout__steps" aria-label="Checkout steps" hidden>` with one button per step, and each block with a step is wrapped in `<div class="fxc-checkout__step" data-fxc-step="…">`. Nothing is hidden in the markup apart from the nav, so the page posts as one form without the script.
- **What `checkout.js` does.** It shows the nav and one step at a time, moves the blocks of the side column that belong to a step to the end of the main column (the side keeps the summary), and adds Back and "Continue to …" buttons after each step. Continue checks the step's enabled controls with the browser's own validation and reports the first problem; going forward from the nav checks each step on the way. Enter in a field before the last step continues. From the second step a recap lists the email, the address the order ships to and, on Payment, the shipping method, each with a Change button that goes back to its field. Each step is a history entry, so the browser's Back button goes back a step. The form is `novalidate` in this mode and Place order checks every step first; a server error opens the step of its first field, as does a failed post without the script.
- **Builder.** A "Checkout" control next to Layout switches between One page and Steps. In steps mode each block on the canvas shows its step ("Step 1 · Information", "Every step").
- **Tests.** Unit: the flow default, fallback and refusal, and the step of each block. Integration: the steps markup with nothing hidden, an order posted as one page, no shipping step for a cart without shipping, and no step markup in the default layout. E2E: Continue holds back an empty address, the recap, the browser's Back button, Enter, Change, the nav, and an order placed, with an accessibility check.

## 13. What part 3 built: coupon box

The third item of part 3: the shopper can add or remove a coupon code on the checkout, without going back to the cart.

- **Stored shape.** `page.coupons` is `show` (the default) or `hide`. A layout saved before this reads as `show`, so the box appears on stores that never open the builder. A PUT with another value is refused at `page.coupons`; on read it falls back to `show`.
- **Where it is.** Under the order summary, inside the summary block: it moves with the summary, shows in every step of a steps checkout and folds with the summary at the top on phones. It is a `<details>` closed behind "Have a coupon code?", so it takes one line until the shopper asks for it.
- **Markup.** Forms do not nest, so the coupon form (`form#fxc-checkout-coupon`, posting to `admin-post.php` with the cart form handler's `coupon` operation) comes after the order form, empty and `hidden`. The code field, the Apply button and the remove buttons join it through the `form` attribute. The code never goes into the order post. Each coupon row the shopper added has a remove button (`name="remove_coupon"`, labelled "Remove coupon CODE"); an automatic discount has none.
- **Without JavaScript.** The post goes through `FormHandler` as on the cart page and redirects back to the checkout with `?fxc-cart=…`. The box then renders open with the result message, and the page renders the new totals and a fresh expected total.
- **What `checkout.js` does.** It takes over the coupon form's submit: Apply posts `store/cart/coupons`, a remove button sends `DELETE store/cart/coupons/{code}`. A requote then brings the totals, the coupon rows and the expected total; with no country to quote, the cart's own totals are shown. The result goes in a message under the field (`role="status"`, or `role="alert"` for a refused code). After a refused code or a remove the focus goes to the code field. An empty field says "Enter a coupon code." without a request.
- **Builder.** The order summary's settings have a "Coupon code box" switch, and the summary sketch on the canvas shows the "Have a coupon code?" line while it is on.
- **Tests.** Unit: the default, the fallback and the refusal. Integration: the no-JS apply and remove from the checkout, the box open with the result after a post and closed on a plain visit, the code field outside the order form, remove buttons for codes only, and no box or coupon form with `hide`; the existing checkout markup tests now expect the box. E2E: a refused code, apply, remove with the focus back in the field, the totals after each, an order placed with the discount, and an accessibility check.

## 14. What part 3 built: Checkout Field Editor import

The fourth item of part 3: a store moving from WooCommerce keeps the checkout fields it set up in the Checkout Field Editor (ThemeHigh, free and Pro).

- **Where.** A Migration stage, `checkout`, after `options`, shown as "Checkout fields" in the wizard and in `wp flexacommerce migrate --stages=checkout`. It goes through the file transport like the other stages. The mapping and its warnings are in doc 05 §5.10.
- **What the merchant gets.** Each section saved in the extension becomes that section of the builder: the order, the labels the merchant changed, the widths, required, optional or hidden, and the merchant's own fields with their choices. Sections never saved in the extension, the page settings (columns, steps, coupon box, content blocks) and fields added in the builder stay.
- **Translations.** A label or placeholder that kept WooCommerce's text is cleared, so the field shows FlexaCommerce's own text in the site language. Only text the merchant wrote is stored.
- **What it leaves to the merchant.** Headings and paragraphs between fields, multiselect and checkbox group fields, hidden fields, default values and the Pro conditions. Each is a line in the job log that names the field, so the merchant can rebuild it in the builder (a heading between sections, conditions from §11).
- **Saving.** The writer builds the new layout and saves it through `CheckoutLayout::save()`, so it passes the builder's own checks. Migration reaches no other class of Checkout (doc 02 §3.2).
- **Tests.** WooCommerce suite, in the three order storage modes: core order, labels, widths and answers; WooCommerce text cleared; a locked email; custom field ids, types and choices; the warnings in the log; an unsaved section, the page and a builder field kept; a second run skipped when unchanged and updated when changed; a store without the extension imports nothing.
