# 11. Checkout builder (design)

Status: part 1 (fields) and part 2 (sections and layout) built, 2026-10-27; decision records ADR-031 and ADR-032 in doc 08. Of part 3, the field conditions are built (§11, ADR-033); the steps layout, the coupon box and the Checkout Field Editor import are still proposals. Where the parts differ from the first draft of this page, §9 to §11 say so.

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

