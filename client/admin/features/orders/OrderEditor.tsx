import { useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, Loader2, Trash2, UserRound, X } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { Textarea } from "@/shared/components/ui/textarea";
import { ApiError } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { PageTitle } from "../../components/PageTitle";
import { useConditionChoices } from "../conditions/useConditions";
import { useTargetSearch } from "../coupons/useCoupons";
import { SearchBox, type Row } from "../simulator/SearchBox";
import { useCustomerSearch } from "../simulator/useSimulator";
import { orderUrl } from "./navigation";
import type { OrderAddress } from "./types";
import {
    EMPTY_ADDRESS,
    useCustomerAddresses,
    useProductForm,
    useQuote,
    useSaveOrder,
    useStoredDraft,
    type Draft,
    type DraftLine,
    type OptionFieldDef,
    type OptionValue,
    type ProductForm,
    type StoredDraft,
} from "./useOrderEditor";

/** The store's own cart limit; the server cuts a longer list. */
const MAX_LINES = 100;

interface EditorLine extends DraftLine {
    uid: number;
    name: string;
}

interface EditorState {
    lines: EditorLine[];
    customer: { id: number; label: string } | null;
    billing: OrderAddress;
    shipTo: boolean;
    shipping: OrderAddress;
    coupons: string[];
    shipping_rate: string;
    payment_method: string;
    customer_note: string;
}

let nextUid = 1;

function fromStored(stored: StoredDraft | undefined): EditorState {
    return {
        lines: (stored?.lines ?? []).map((line) => ({
            uid: nextUid++,
            product_id: line.product_id,
            variant_id: line.variant_id,
            quantity: line.quantity,
            attributes: { ...line.attributes },
            options: { ...line.options },
            name: line.name,
        })),
        customer: stored?.customer ? { id: stored.customer.id, label: `${stored.customer.name} (${stored.customer.email})` } : null,
        billing: { ...EMPTY_ADDRESS, ...(stored?.billing ?? {}) },
        shipTo: stored?.shipping != null,
        shipping: { ...EMPTY_ADDRESS, ...(stored?.shipping ?? {}) },
        coupons: stored?.coupons ?? [],
        shipping_rate: stored?.shipping_rate ?? "",
        payment_method: stored?.payment_method ?? "",
        customer_note: stored?.customer_note ?? "",
    };
}

/** Create an order for a customer, or change an unpaid one (ADR-061). `id` 0 is a new order. */
export function OrderEditor({ id, onDone }: { id: number; onDone: (id: number) => void }) {
    const stored = useStoredDraft(id);
    const back = (
        <a
            href={orderUrl(id)}
            onClick={(e) => {
                e.preventDefault();
                onDone(id);
            }}
            className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:mb-3 fxc:inline-flex fxc:items-center fxc:gap-1 fxc:text-sm fxc:no-underline"
        >
            <ArrowLeft aria-hidden className="fxc:size-4" />
            {id > 0 ? __("Back to the order") : __("All orders")}
        </a>
    );

    if (id > 0 && stored.isPending) {
        return (
            <div>
                {back}
                <p className="fxc:text-muted-foreground">{__("Loading order…")}</p>
            </div>
        );
    }
    if (id > 0 && (stored.isError || stored.data?.editable === false)) {
        return (
            <div>
                {back}
                <p className={stored.isError ? "fxc:text-destructive" : "fxc:text-muted-foreground"}>
                    {stored.isError
                        ? stored.error.message
                        : __("Only unpaid orders that are pending, on hold or failed can be edited. The addresses can still be changed on the order screen.")}
                </p>
            </div>
        );
    }
    return <EditorForm id={id} initial={fromStored(stored.data)} back={back} onDone={onDone} />;
}

function EditorForm({ id, initial, back, onDone }: { id: number; initial: EditorState; back: React.ReactNode; onDone: (id: number) => void }) {
    const [state, setState] = useState(initial);
    const [sendDetails, setSendDetails] = useState(true);
    const [couponText, setCouponText] = useState("");
    const choices = useConditionChoices();
    const addresses = useCustomerAddresses();
    const save = useSaveOrder(id);
    const countries = (choices.data?.countries ?? []).map((c) => ({ value: c.id, label: c.title }));
    const patch = (next: Partial<EditorState>) => setState((s) => ({ ...s, ...next }));

    const draft: Draft = useMemo(
        () => ({
            lines: state.lines.map(({ product_id, variant_id, quantity, attributes, options }) => ({ product_id, variant_id, quantity, attributes, options })),
            customer_id: state.customer?.id ?? 0,
            billing: state.billing,
            shipping: state.shipTo ? state.shipping : null,
            coupons: state.coupons,
            shipping_rate: state.shipping_rate,
            payment_method: state.payment_method,
            customer_note: state.customer_note,
        }),
        [state],
    );
    const debounced = useDebounced(draft, 400);
    const quote = useQuote(debounced, debounced.lines.length > 0);
    const errors = state.lines.length > 0 ? (quote.data?.errors ?? {}) : { lines: __("Add at least one product.") };
    const blocked = Object.keys(errors).length > 0 || quote.isFetching || debounced !== draft;

    // The quote picks the first rate when the one chosen is not offered.
    useEffect(() => {
        if (quote.data && quote.data.shipping_rate !== state.shipping_rate && !quote.isPlaceholderData) {
            patch({ shipping_rate: quote.data.shipping_rate });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [quote.data]);

    const pickCustomer = (customer: { id: number; label: string }) => {
        patch({ customer });
        addresses.mutate(customer.id, {
            onSuccess: (saved) =>
                setState((s) => ({
                    ...s,
                    billing: { ...EMPTY_ADDRESS, ...saved.billing },
                    shipTo: saved.shipping !== null,
                    shipping: { ...EMPTY_ADDRESS, ...(saved.shipping ?? {}) },
                })),
        });
    };

    const addCoupon = () => {
        const code = couponText.trim();
        if (code !== "" && !state.coupons.some((c) => c.toLowerCase() === code.toLowerCase())) {
            patch({ coupons: [...state.coupons, code] });
        }
        setCouponText("");
    };

    const submit = () =>
        save.mutate(
            { draft, send_details: id === 0 && sendDetails },
            {
                onSuccess: (order) => {
                    showToast.success(id > 0 ? __("Order updated.") : sprintf(__("Order #%s created."), order.number));
                    if ("details_sent" in order && order.details_sent === false) {
                        showToast.error(__("The order details email could not be sent. The log has the reason."));
                    }
                    onDone(order.id);
                },
                onError: (e) => {
                    const fields = e instanceof ApiError && typeof e.data.errors === "object" && e.data.errors !== null ? Object.values(e.data.errors as Record<string, string>) : [];
                    showToast.error(fields.length > 0 ? fields.join(" ") : e.message);
                },
            },
        );

    const data = quote.data;
    return (
        <div>
            {back}
            <PageTitle
                title={id > 0 ? __("Edit order") : __("New order")}
                description={
                    id > 0
                        ? __("Lines, coupons and prices are worked out again. Stock and coupon uses are given back and taken again when you save.")
                        : __("Prices, rules, coupons and taxes are the ones the customer would get at checkout.")
                }
            />
            <div className="fxc:grid fxc:gap-6 fxc:lg:grid-cols-3">
                <div className="fxc:space-y-6 fxc:lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{__("Products")}</CardTitle>
                        </CardHeader>
                        <CardContent className="fxc:space-y-4">
                            <EditorProductSearch
                                disabled={state.lines.length >= MAX_LINES}
                                onPick={(productId, variantId, name) =>
                                    patch({
                                        lines: [
                                            ...state.lines,
                                            { uid: nextUid++, product_id: productId, variant_id: variantId, quantity: 1, attributes: {}, options: {}, name },
                                        ],
                                    })
                                }
                            />
                            {state.lines.length === 0 ? (
                                <p className="fxc:text-muted-foreground fxc:text-sm">{__("Search for a product to add the first line.")}</p>
                            ) : (
                                <ul className="fxc:space-y-3">
                                    {state.lines.map((line, i) => (
                                        <LineEditor
                                            key={line.uid}
                                            index={i}
                                            line={line}
                                            onChange={(next) => patch({ lines: state.lines.map((l) => (l.uid === line.uid ? { ...l, ...next } : l)) })}
                                            onRemove={() => patch({ lines: state.lines.filter((l) => l.uid !== line.uid) })}
                                        />
                                    ))}
                                </ul>
                            )}
                            {errors.lines !== undefined && state.lines.length > 0 && (
                                <p role="alert" className="fxc:text-destructive fxc:text-sm">
                                    {errors.lines}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{__("Customer")}</CardTitle>
                            <CardDescription>{__("Pick an account to use its prices, rules and saved addresses, or leave it as a guest.")}</CardDescription>
                        </CardHeader>
                        <CardContent className="fxc:space-y-5">
                            {state.customer ? (
                                <div className="fxc:border-border fxc:flex fxc:items-center fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-sm">
                                    <UserRound aria-hidden className="fxc:text-muted-foreground fxc:size-4" />
                                    <span className="fxc:min-w-0 fxc:flex-1 fxc:truncate">{state.customer.label}</span>
                                    {addresses.isPending && <Loader2 aria-hidden className="fxc:size-4 fxc:animate-spin" />}
                                    <button
                                        type="button"
                                        aria-label={__("Order as a guest")}
                                        className="fxc:text-muted-foreground fxc:hover:text-foreground"
                                        onClick={() => patch({ customer: null })}
                                    >
                                        <X aria-hidden className="fxc:size-4" />
                                    </button>
                                </div>
                            ) : (
                                <EditorCustomerSearch onPick={pickCustomer} />
                            )}
                            <AddressFields
                                prefix="billing"
                                title={__("Billing address")}
                                value={state.billing}
                                onChange={(billing) => patch({ billing })}
                                countries={countries}
                                errors={errors}
                                email
                            />
                            <label className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                                <input type="checkbox" className="flexacommerce-check" checked={state.shipTo} onChange={(e) => patch({ shipTo: e.target.checked })} />
                                {__("Ship to a different address")}
                            </label>
                            {state.shipTo && (
                                <AddressFields
                                    prefix="shipping"
                                    title={__("Shipping address")}
                                    value={state.shipping}
                                    onChange={(shipping) => patch({ shipping })}
                                    countries={countries}
                                    errors={errors}
                                />
                            )}
                            <div>
                                <Label htmlFor="fxc-editor-note" className="fxc:mb-2 fxc:block">
                                    {__("Customer note")}
                                </Label>
                                <Textarea id="fxc-editor-note" value={state.customer_note} onChange={(e) => patch({ customer_note: e.target.value })} />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="fxc:space-y-6">
                    <Card data-testid="order-editor-summary">
                        <CardHeader>
                            <CardTitle className="fxc:flex fxc:items-center fxc:gap-2">
                                {__("Summary")}
                                {quote.isFetching && <Loader2 aria-label={__("Updating prices")} className="fxc:size-4 fxc:animate-spin" />}
                            </CardTitle>
                        </CardHeader>
                        {data && state.lines.length > 0 && (
                            <ul className="fxc:border-b fxc:text-sm">
                                {data.lines.map((line) => (
                                    <li key={line.key} className="fxc:flex fxc:gap-3 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-2">
                                        <div className="fxc:min-w-0 fxc:flex-1">
                                            <div className="fxc:font-medium">
                                                {line.name} × {line.quantity}
                                            </div>
                                            {line.labels.length > 0 && <div className="fxc:text-muted-foreground fxc:text-xs">{line.labels.join(" · ")}</div>}
                                        </div>
                                        <span className="fxc:tabular-nums">{line.total?.formatted ?? "—"}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="fxc:space-y-4 fxc:border-b fxc:px-5 fxc:py-4">
                            <div>
                                <Label htmlFor="fxc-editor-coupon" className="fxc:mb-2 fxc:block">
                                    {__("Coupons")}
                                </Label>
                                <div className="fxc:flex fxc:gap-2">
                                    <Input
                                        id="fxc-editor-coupon"
                                        value={couponText}
                                        placeholder={__("Coupon code")}
                                        onChange={(e) => setCouponText(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === "Enter") {
                                                e.preventDefault();
                                                addCoupon();
                                            }
                                        }}
                                    />
                                    <Button variant="outline" onClick={addCoupon} disabled={couponText.trim() === ""}>
                                        {__("Apply")}
                                    </Button>
                                </div>
                                {(data?.coupons ?? []).length > 0 && (
                                    <ul className="fxc:mt-2 fxc:space-y-1 fxc:text-sm">
                                        {data?.coupons.map((coupon) => (
                                            <li key={coupon.code} className="fxc:flex fxc:items-start fxc:gap-2">
                                                <Badge variant={coupon.applied ? "success" : "destructive"}>{coupon.code}</Badge>
                                                <span className="fxc:text-muted-foreground fxc:min-w-0 fxc:flex-1 fxc:text-xs">
                                                    {coupon.automatic ? __("Automatic") : coupon.applied ? (coupon.amount?.formatted ?? "") : coupon.message}
                                                </span>
                                                {!coupon.automatic && (
                                                    <button
                                                        type="button"
                                                        aria-label={sprintf(__("Remove %s"), coupon.code)}
                                                        className="fxc:text-muted-foreground fxc:hover:text-foreground"
                                                        onClick={() => patch({ coupons: state.coupons.filter((c) => c.toLowerCase() !== coupon.code.toLowerCase()) })}
                                                    >
                                                        <X aria-hidden className="fxc:size-3.5" />
                                                    </button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            {data?.needs_shipping && (
                                <div>
                                    <Label htmlFor="fxc-editor-rate" className="fxc:mb-2 fxc:block">
                                        {__("Shipping")}
                                    </Label>
                                    <Select
                                        id="fxc-editor-rate"
                                        className="fxc:w-full"
                                        value={state.shipping_rate}
                                        onChange={(e) => patch({ shipping_rate: e.target.value })}
                                        options={
                                            data.shipping_rates.length > 0
                                                ? data.shipping_rates.map((r) => ({ value: r.id, label: `${r.label} (${r.cost.formatted})` }))
                                                : [{ value: "", label: __("No rate for this address") }]
                                        }
                                    />
                                </div>
                            )}
                            <div>
                                <Label htmlFor="fxc-editor-method" className="fxc:mb-2 fxc:block">
                                    {__("Payment method")}
                                </Label>
                                <Select
                                    id="fxc-editor-method"
                                    className="fxc:w-full"
                                    value={state.payment_method}
                                    onChange={(e) => patch({ payment_method: e.target.value })}
                                    options={[
                                        { value: "", label: __("Customer chooses when paying") },
                                        ...(data?.payment_methods ?? []).map((m) => ({ value: m.id, label: m.title })),
                                    ]}
                                />
                            </div>
                        </div>
                        {data && state.lines.length > 0 && (
                            <div className="fxc:space-y-1 fxc:border-b fxc:px-5 fxc:py-4 fxc:text-sm">
                                <Total label={__("Subtotal")} value={data.totals.subtotal.formatted} />
                                {data.totals.discount.amount > 0 && <Total label={__("Discount")} value={"−" + data.totals.discount.formatted} />}
                                {data.fees.map((fee, i) => (
                                    <Total key={i} label={fee.name} value={fee.amount.formatted} />
                                ))}
                                {data.needs_shipping && <Total label={__("Shipping")} value={data.totals.shipping.formatted} />}
                                {(data.totals.tax.amount > 0 || !data.prices_include_tax) && <Total label={__("Tax")} value={data.totals.tax.formatted} />}
                                <Total label={__("Total")} value={data.totals.total.formatted} strong />
                            </div>
                        )}
                        <div className="fxc:space-y-3 fxc:px-5 fxc:py-4">
                            {Object.entries(errors).length > 0 && (
                                <ul role="alert" className="fxc:text-destructive fxc:space-y-1 fxc:text-sm">
                                    {Object.entries(errors).map(([field, message]) => (
                                        <li key={field}>{message}</li>
                                    ))}
                                </ul>
                            )}
                            {(data?.notices ?? []).map((notice, i) => (
                                <p key={i} className="fxc:text-muted-foreground fxc:text-xs">
                                    {notice}
                                </p>
                            ))}
                            {id === 0 && (
                                <label className="fxc:flex fxc:items-start fxc:gap-2 fxc:text-sm">
                                    <input
                                        type="checkbox"
                                        className="flexacommerce-check fxc:mt-0.5"
                                        checked={sendDetails}
                                        onChange={(e) => setSendDetails(e.target.checked)}
                                    />
                                    {__("Email the order details to the customer, with a link to pay")}
                                </label>
                            )}
                            <Button className="fxc:w-full" onClick={submit} disabled={blocked || save.isPending}>
                                {save.isPending ? __("Saving…") : id > 0 ? __("Save changes") : __("Create order")}
                            </Button>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    );
}

function Total({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={strong ? "fxc:flex fxc:justify-between fxc:font-semibold" : "fxc:text-muted-foreground fxc:flex fxc:justify-between"}>
            <span>{label}</span>
            <span className="fxc:tabular-nums">{value}</span>
        </div>
    );
}

/** Products and their variations; a variation row carries its product, which the search lists just before it. */
function EditorProductSearch({ onPick, disabled }: { onPick: (productId: number, variantId: number, name: string) => void; disabled: boolean }) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);
    const search = useDebounced(text.trim(), 250);
    const results = useTargetSearch("product", search, open && !disabled);
    let parent = 0;
    const rows: Row[] = (results.data ?? []).map((t) => {
        parent = t.type === "product" ? t.id : parent;
        return { key: `${t.type}:${t.id}:${parent}`, label: t.label, hint: t.sku, indent: t.type === "variant" };
    });
    return (
        <SearchBox
            id="fxc-editor-products"
            label={__("Add a product")}
            placeholder={disabled ? sprintf(__("At most %d lines"), MAX_LINES) : __("Add a product by name or SKU")}
            text={text}
            onText={setText}
            rows={rows}
            fetching={results.isFetching}
            onPick={(row) => {
                const [type, id, product] = row.key.split(":");
                onPick(Number(product), type === "variant" ? Number(id) : 0, row.label);
            }}
            open={open && !disabled}
            onOpen={setOpen}
        />
    );
}

function EditorCustomerSearch({ onPick }: { onPick: (customer: { id: number; label: string }) => void }) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);
    const search = useDebounced(text.trim(), 250);
    const results = useCustomerSearch(search, open);
    const rows: Row[] = (results.data ?? []).map((c) => ({ key: String(c.id), label: `${c.name} (${c.email})`, hint: c.roles.join(", ") }));
    return (
        <SearchBox
            id="fxc-editor-customer"
            label={__("Customer")}
            placeholder={__("Guest. Search for an account by name or email")}
            text={text}
            onText={setText}
            rows={rows}
            fetching={results.isFetching}
            onPick={(row) => onPick({ id: Number(row.key), label: row.label })}
            open={open}
            onOpen={setOpen}
        />
    );
}

/** The variation whose fixed values all match the choice; `*` takes any value. */
function matchVariant(form: ProductForm, attributes: Record<string, string>): number {
    const found = form.variants.find((v) =>
        form.attributes.every((a) => {
            const fixed = v.attributes[a.key] ?? "*";
            return fixed === "*" ? (attributes[a.key] ?? "") !== "" : fixed === attributes[a.key];
        }),
    );
    return found?.id ?? 0;
}

function LineEditor({ index, line, onChange, onRemove }: { index: number; line: EditorLine; onChange: (next: Partial<EditorLine>) => void; onRemove: () => void }) {
    const form = useProductForm(line.product_id);
    const seeded = useRef(false);
    const variable = form.data?.type === "variable";

    // A variation picked from the search, or stored without its choices, fills in its own values.
    useEffect(() => {
        if (!form.data || seeded.current || !variable || line.variant_id === 0) {
            return;
        }
        seeded.current = true;
        const variant = form.data.variants.find((v) => v.id === line.variant_id);
        if (variant) {
            const fixed = Object.fromEntries(Object.entries(variant.attributes).filter(([, value]) => value !== "*"));
            onChange({ attributes: { ...fixed, ...line.attributes } });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data]);

    const setAttribute = (key: string, value: string) => {
        const attributes = { ...line.attributes, [key]: value };
        if (value === "") {
            delete attributes[key];
        }
        onChange({ attributes, variant_id: form.data ? matchVariant(form.data, attributes) : 0 });
    };
    const setOption = (key: string, value: OptionValue) => {
        const options = { ...line.options, [key]: value };
        if (value === "" || (Array.isArray(value) && value.length === 0)) {
            delete options[key];
        }
        onChange({ options });
    };
    const variant = form.data?.variants.find((v) => v.id === line.variant_id);
    const field = (name: string) => `fxc-line-${line.uid}-${name}`;

    return (
        <li className="fxc:border-border fxc:space-y-3 fxc:rounded-md fxc:border fxc:p-3">
            <div className="fxc:flex fxc:items-start fxc:gap-3">
                <div className="fxc:min-w-0 fxc:flex-1">
                    <div className="fxc:text-sm fxc:font-medium">{form.data?.name ?? line.name}</div>
                    <div className="fxc:text-muted-foreground fxc:text-xs">
                        {[variant?.sku ?? form.data?.sku ?? "", variant ? variant.price : ""].filter(Boolean).join(" · ")}
                        {variant && !variant.in_stock && <span className="fxc:text-destructive"> · {__("Out of stock")}</span>}
                    </div>
                </div>
                <div className="fxc:w-20">
                    <Label htmlFor={field("qty")} className="fxc:sr-only">
                        {sprintf(__("Quantity of line %d"), index + 1)}
                    </Label>
                    <Input
                        id={field("qty")}
                        type="number"
                        min={1}
                        value={line.quantity}
                        onChange={(e) => onChange({ quantity: Math.max(1, Number.parseInt(e.target.value, 10) || 1) })}
                    />
                </div>
                <Button variant="ghost" size="icon" aria-label={sprintf(__("Remove line %d"), index + 1)} onClick={onRemove}>
                    <Trash2 aria-hidden className="fxc:size-4" />
                </Button>
            </div>
            {form.isError && <p className="fxc:text-destructive fxc:text-xs">{form.error.message}</p>}
            {variable && form.data && (
                <div className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-2">
                    {form.data.attributes.map((attribute) => (
                        <div key={attribute.key}>
                            <Label htmlFor={field(attribute.key)} className="fxc:mb-1 fxc:block fxc:text-xs">
                                {attribute.name}
                            </Label>
                            <Select
                                id={field(attribute.key)}
                                className="fxc:w-full"
                                value={line.attributes[attribute.key] ?? ""}
                                onChange={(e) => setAttribute(attribute.key, e.target.value)}
                                options={[{ value: "", label: __("Choose an option") }, ...attribute.options.map((o) => ({ value: o.key, label: o.label }))]}
                            />
                        </div>
                    ))}
                </div>
            )}
            {form.data?.options.map((set) => (
                <div key={set.id} className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-2">
                    {set.fields.map((f) => (
                        <OptionInput key={f.key} id={field(f.key)} field={f} value={line.options[f.key]} onChange={(v) => setOption(f.key, v)} />
                    ))}
                </div>
            ))}
        </li>
    );
}

const INPUT_TYPE: Record<string, string> = { number: "number", range: "number", date: "date", time: "time", datetime: "datetime-local", color: "color" };

function OptionInput({ id, field, value, onChange }: { id: string; field: OptionFieldDef; value: OptionValue | undefined; onChange: (v: OptionValue) => void }) {
    if (field.type === "heading") {
        return <h4 className="fxc:text-foreground fxc:text-sm fxc:font-semibold fxc:sm:col-span-2">{field.label}</h4>;
    }
    const label = (
        <Label htmlFor={id} className="fxc:mb-1 fxc:block fxc:text-xs">
            {field.label}
            {field.required && <span className="fxc:text-destructive"> *</span>}
        </Label>
    );
    if (field.type === "checkboxes") {
        const picked = Array.isArray(value) ? value : [];
        return (
            <fieldset className="fxc:sm:col-span-2">
                <legend className="fxc:mb-1 fxc:text-xs fxc:font-medium">{field.label}</legend>
                <div className="fxc:flex fxc:flex-wrap fxc:gap-x-4 fxc:gap-y-1">
                    {field.choices.map((choice) => (
                        <label key={choice.id} className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                            <input
                                type="checkbox"
                                className="flexacommerce-check"
                                checked={picked.includes(String(choice.id))}
                                onChange={(e) =>
                                    onChange(e.target.checked ? [...picked, String(choice.id)] : picked.filter((p) => p !== String(choice.id)))
                                }
                            />
                            {choice.label}
                        </label>
                    ))}
                </div>
            </fieldset>
        );
    }
    const text = typeof value === "string" ? value : "";
    if (["select", "radio", "swatch", "button"].includes(field.type)) {
        return (
            <div>
                {label}
                <Select
                    id={id}
                    className="fxc:w-full"
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    options={[{ value: "", label: __("None") }, ...field.choices.map((c) => ({ value: String(c.id), label: c.label }))]}
                />
            </div>
        );
    }
    if (field.type === "textarea") {
        return (
            <div className="fxc:sm:col-span-2">
                {label}
                <Textarea id={id} value={text} placeholder={field.placeholder} onChange={(e) => onChange(e.target.value)} />
            </div>
        );
    }
    return (
        <div>
            {label}
            <Input
                id={id}
                type={INPUT_TYPE[field.type] ?? "text"}
                value={text}
                min={field.type === "number" || field.type === "range" ? (field.min ?? undefined) : undefined}
                max={field.type === "number" || field.type === "range" ? (field.max ?? undefined) : undefined}
                step={field.type === "number" || field.type === "range" ? (field.step ?? undefined) : undefined}
                placeholder={field.type === "color" ? undefined : field.placeholder}
                onChange={(e) => onChange(e.target.value)}
            />
        </div>
    );
}

const FIELDS: { key: keyof OrderAddress; label: string; wide?: boolean }[] = [
    { key: "first_name", label: __("First name") },
    { key: "last_name", label: __("Last name") },
    { key: "company", label: __("Company"), wide: true },
    { key: "address_1", label: __("Address"), wide: true },
    { key: "address_2", label: __("Apartment, suite, etc."), wide: true },
    { key: "city", label: __("City") },
    { key: "postcode", label: __("Postcode") },
    { key: "state", label: __("State code") },
];

export function AddressFields({
    prefix,
    title,
    value,
    onChange,
    countries,
    errors,
    email = false,
}: {
    prefix: "billing" | "shipping";
    title: string;
    value: OrderAddress;
    onChange: (next: OrderAddress) => void;
    countries: { value: string; label: string }[];
    errors: Record<string, string>;
    email?: boolean;
}) {
    const id = (key: string) => `fxc-${prefix}-${key}`;
    const set = (key: keyof OrderAddress, v: string) => onChange({ ...value, [key]: v });
    const error = (key: string) => errors[`${prefix}.${key}`];
    return (
        <fieldset className="fxc:space-y-3">
            <legend className="fxc:text-foreground fxc:mb-2 fxc:text-sm fxc:font-semibold">{title}</legend>
            <div className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-2">
                {FIELDS.map((f) => (
                    <div key={f.key} className={f.wide ? "fxc:sm:col-span-2" : undefined}>
                        <Label htmlFor={id(f.key)} className="fxc:mb-1 fxc:block fxc:text-xs">
                            {f.label}
                        </Label>
                        <Input id={id(f.key)} value={value[f.key] ?? ""} onChange={(e) => set(f.key, e.target.value)} />
                    </div>
                ))}
                <div>
                    <Label htmlFor={id("country")} className="fxc:mb-1 fxc:block fxc:text-xs">
                        {__("Country")}
                    </Label>
                    <Select
                        id={id("country")}
                        className="fxc:w-full"
                        value={value.country}
                        aria-invalid={error("country") !== undefined}
                        onChange={(e) => set("country", e.target.value)}
                        options={[{ value: "", label: __("Not chosen yet") }, ...countries]}
                    />
                    {error("country") !== undefined && <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{error("country")}</p>}
                </div>
                <div>
                    <Label htmlFor={id("phone")} className="fxc:mb-1 fxc:block fxc:text-xs">
                        {__("Phone")}
                    </Label>
                    <Input id={id("phone")} type="tel" value={value.phone ?? ""} onChange={(e) => set("phone", e.target.value)} />
                </div>
                {email && (
                    <div className="fxc:sm:col-span-2">
                        <Label htmlFor={id("email")} className="fxc:mb-1 fxc:block fxc:text-xs">
                            {__("Email")}
                        </Label>
                        <Input
                            id={id("email")}
                            type="email"
                            value={value.email ?? ""}
                            aria-invalid={error("email") !== undefined}
                            onChange={(e) => set("email", e.target.value)}
                        />
                        {error("email") !== undefined && <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{error("email")}</p>}
                    </div>
                )}
            </div>
        </fieldset>
    );
}
