import { useEffect, useId, useState, type ReactNode } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { Textarea } from "@/shared/components/ui/textarea";
import { ApiError, getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { inputToMinor, minorToInput, parseDecimal } from "@/shared/lib/units";
import { MoneyInput } from "../../components/MoneyInput";
import { ConditionsEditor } from "../conditions/ConditionsEditor";
import { NO_CONDITIONS, type ConditionBlock } from "../conditions/useConditions";
import { TargetPicker, type Pick } from "./TargetPicker";
import { isPercentType, useSaveCoupon, type Coupon, type CouponInput, type CouponType } from "./useCoupons";

/** What the form edits; dates are `datetime-local` text in the browser's time zone. */
interface Draft {
    automatic: boolean;
    code: string;
    type: CouponType;
    percent: string;
    amount: number;
    status: Coupon["status"];
    description: string;
    starts_at: string;
    expires_at: string;
    min_subtotal: number | null;
    max_subtotal: number | null;
    usage_limit: string;
    per_customer_limit: string;
    item_limit: string;
    individual_use: boolean;
    exclude_sale_items: boolean;
    free_shipping: boolean;
    products: Pick[];
    exclude_products: Pick[];
    categories: Pick[];
    exclude_categories: Pick[];
    emails: string;
    buy_qty: string;
    get_qty: string;
    repeat_limit: string;
    get_products: Pick[];
    get_categories: Pick[];
    conditions: ConditionBlock;
}

const pad = (n: number) => String(n).padStart(2, "0");

function toLocalInput(iso: string | null): string {
    if (iso === null) {
        return "";
    }
    const d = new Date(iso);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toUtc(local: string): string | null {
    if (local === "") {
        return null;
    }
    const d = new Date(local);
    return Number.isNaN(d.getTime()) ? local : d.toISOString().slice(0, 19) + "Z";
}

const picks = (type: Pick["type"], ids: number[] | undefined): Pick[] => (ids ?? []).map((id) => ({ type, id }));

const idsOf = (list: Pick[], type: Pick["type"]): number[] => list.filter((p) => p.type === type).map((p) => p.id);

function draftOf(coupon: Coupon | null): Draft {
    const bogo = coupon?.type === "buy_x_get_y";
    return {
        automatic: coupon?.automatic ?? false,
        code: coupon?.code ?? "",
        type: coupon?.type ?? "percent",
        percent: coupon !== null && isPercentType(coupon.type) ? String(coupon.amount / 100) : "",
        amount: coupon !== null && !isPercentType(coupon.type) ? coupon.amount : 0,
        status: coupon?.status ?? "active",
        description: coupon?.description ?? "",
        starts_at: toLocalInput(coupon?.starts_at ?? null),
        expires_at: toLocalInput(coupon?.expires_at ?? null),
        min_subtotal: coupon?.min_subtotal ?? null,
        max_subtotal: coupon?.max_subtotal ?? null,
        usage_limit: coupon !== null && coupon.usage_limit > 0 ? String(coupon.usage_limit) : "",
        per_customer_limit: coupon !== null && coupon.per_customer_limit > 0 ? String(coupon.per_customer_limit) : "",
        item_limit: coupon !== null && coupon.item_limit > 0 ? String(coupon.item_limit) : "",
        individual_use: coupon?.individual_use ?? false,
        exclude_sale_items: coupon?.exclude_sale_items ?? false,
        free_shipping: coupon?.free_shipping ?? false,
        products: [...picks("product", coupon?.product_ids), ...picks("variant", coupon?.variant_ids)],
        exclude_products: [...picks("product", coupon?.exclude_product_ids), ...picks("variant", coupon?.exclude_variant_ids)],
        categories: picks("category", coupon?.category_ids),
        exclude_categories: picks("category", coupon?.exclude_category_ids),
        emails: (coupon?.emails ?? []).join(", "),
        buy_qty: bogo ? String(coupon.buy_qty) : "1",
        get_qty: bogo ? String(coupon.get_qty) : "1",
        repeat_limit: bogo && coupon.repeat_limit > 0 ? String(coupon.repeat_limit) : "",
        get_products: [...picks("product", coupon?.get_product_ids), ...picks("variant", coupon?.get_variant_ids)],
        get_categories: picks("category", coupon?.get_category_ids),
        conditions: coupon?.conditions ?? NO_CONDITIONS,
    };
}

function inputOf(draft: Draft): CouponInput {
    const percent = parseDecimal(draft.percent);
    const bogo = draft.type === "buy_x_get_y";
    return {
        automatic: draft.automatic,
        code: draft.code.trim(),
        type: draft.type,
        amount: isPercentType(draft.type) ? (percent === null || Number.isNaN(percent) ? 0 : Math.round(percent * 100)) : draft.amount,
        status: draft.status,
        description: draft.description,
        starts_at: toUtc(draft.starts_at),
        expires_at: toUtc(draft.expires_at),
        min_subtotal: draft.min_subtotal,
        max_subtotal: draft.max_subtotal,
        usage_limit: Number(draft.usage_limit) || 0,
        per_customer_limit: draft.automatic ? 0 : Number(draft.per_customer_limit) || 0,
        item_limit: draft.type === "fixed_cart" || bogo ? 0 : Number(draft.item_limit) || 0,
        individual_use: draft.individual_use,
        exclude_sale_items: draft.exclude_sale_items,
        free_shipping: draft.free_shipping,
        product_ids: idsOf(draft.products, "product"),
        exclude_product_ids: idsOf(draft.exclude_products, "product"),
        variant_ids: idsOf(draft.products, "variant"),
        exclude_variant_ids: idsOf(draft.exclude_products, "variant"),
        category_ids: idsOf(draft.categories, "category"),
        exclude_category_ids: idsOf(draft.exclude_categories, "category"),
        emails: draft.automatic
            ? []
            : draft.emails
                  .split(/[\s,]+/)
                  .map((e) => e.trim())
                  .filter(Boolean),
        buy_qty: bogo ? Number(draft.buy_qty) || 0 : 0,
        get_qty: bogo ? Number(draft.get_qty) || 0 : 0,
        repeat_limit: bogo ? Number(draft.repeat_limit) || 0 : 0,
        get_product_ids: bogo ? idsOf(draft.get_products, "product") : [],
        get_variant_ids: bogo ? idsOf(draft.get_products, "variant") : [],
        get_category_ids: bogo ? idsOf(draft.get_categories, "category") : [],
        conditions: draft.conditions,
    };
}

/** Only the fields that differ from the stored coupon, so a save does not overwrite a concurrent change to another field. */
function changes(input: CouponInput, base: CouponInput | null): Partial<CouponInput> {
    if (base === null) {
        return input;
    }
    const out: Record<string, unknown> = {};
    for (const key of Object.keys(input) as (keyof CouponInput)[]) {
        if (JSON.stringify(input[key]) !== JSON.stringify(base[key])) {
            out[key] = input[key];
        }
    }
    return out as Partial<CouponInput>;
}

function Field({ label, help, error, children }: { label: string; help?: string; error?: string; children: (id: string) => ReactNode }) {
    const id = useId();
    return (
        <div>
            <Label htmlFor={id} className="fxc:mb-2 fxc:block">
                {label}
            </Label>
            {children(id)}
            {error ? (
                <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{error}</p>
            ) : (
                help && <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{help}</p>
            )}
        </div>
    );
}

function Check({ checked, onChange, label, help }: { checked: boolean; onChange: (checked: boolean) => void; label: string; help: string }) {
    const id = useId();
    return (
        <div className="fxc:flex fxc:items-start fxc:gap-2">
            <input id={id} type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="flexacommerce-check fxc:mt-0.5" />
            <label htmlFor={id} className="fxc:text-sm">
                <span className="fxc:font-medium">{label}</span>
                <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{help}</span>
            </label>
        </div>
    );
}

/** Code or automatic: a pair of radio cards at the top of the form. */
function Method({ automatic, onChange }: { automatic: boolean; onChange: (automatic: boolean) => void }) {
    const name = useId();
    const options = [
        { value: false, label: __("Discount code"), help: __("Shoppers type the code in the cart.") },
        { value: true, label: __("Automatic discount"), help: __("Applies by itself to every cart that fits the rules.") },
    ];
    return (
        <fieldset>
            <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Method")}</legend>
            <div className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-2">
                {options.map((option) => (
                    <label
                        key={String(option.value)}
                        className={cn(
                            "fxc:flex fxc:cursor-pointer fxc:items-start fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-sm",
                            automatic === option.value && "fxc:border-primary fxc:bg-muted/50",
                        )}
                    >
                        <input
                            type="radio"
                            name={name}
                            checked={automatic === option.value}
                            onChange={() => onChange(option.value)}
                            className="flexacommerce-check fxc:mt-0.5"
                        />
                        <span>
                            <span className="fxc:block fxc:font-medium">{option.label}</span>
                            <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{option.help}</span>
                        </span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

/** Common buy X get Y offers; the percentage is in the form's units (100 = free). */
const offers = () => [
    { label: __("Buy 1, get 1 free"), buy: "1", get: "1", percent: "100" },
    { label: __("Buy 2, get 1 half off"), buy: "2", get: "1", percent: "50" },
    { label: __("Buy 2, get 1 free"), buy: "2", get: "1", percent: "100" },
];

interface Props {
    open: boolean;
    coupon: Coupon | null;
    /** For a new one: start as an automatic discount. */
    automatic?: boolean;
    onOpenChange: (open: boolean) => void;
}

export function CouponDialog({ open, coupon, automatic = false, onOpenChange }: Props) {
    const { currency } = getPluginGlobal();
    const [draft, setDraft] = useState<Draft>(() => draftOf(coupon));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const save = useSaveCoupon();

    useEffect(() => {
        if (open) {
            setDraft(coupon === null ? { ...draftOf(null), automatic } : draftOf(coupon));
            setErrors({});
        }
    }, [open, coupon, automatic]);

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));

    const submit = () => {
        const input = inputOf(draft);
        const body = changes(input, coupon !== null ? inputOf(draftOf(coupon)) : null);
        if (coupon !== null && Object.keys(body).length === 0) {
            onOpenChange(false);
            return;
        }
        save.mutate(
            { id: coupon?.id ?? 0, input: body },
            {
                onSuccess: (saved) => {
                    showToast.success(
                        coupon !== null
                            ? __("Coupon saved.")
                            : saved.automatic
                              ? sprintf(__("Automatic discount %s created."), saved.code)
                              : sprintf(__("Coupon %s created."), saved.code),
                    );
                    onOpenChange(false);
                },
                onError: (error) => {
                    const field = error instanceof ApiError && typeof error.data.field === "string" ? error.data.field : "";
                    if (field !== "" && field !== "id") {
                        setErrors({ [field]: error.message });
                    } else {
                        showToast.error(error.message);
                    }
                },
            },
        );
    };

    const busy = save.isPending;

    return (
        <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
            <DialogContent className="fxc:max-h-[90vh] fxc:max-w-2xl fxc:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {coupon !== null
                            ? draft.automatic
                                ? __("Edit automatic discount")
                                : __("Edit coupon")
                            : draft.automatic
                              ? __("New automatic discount")
                              : __("New coupon")}
                    </DialogTitle>
                    <DialogDescription>
                        {draft.automatic
                            ? __("Applies to every cart that meets the rules, after any codes the shopper entered. With a minimum spend, or as buy X get Y, the cart tells shoppers what they still need. Dates use your browser's time zone.")
                            : __("Shoppers enter the code in the cart. Codes are not case sensitive. Dates use your browser's time zone.")}
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="fxc-coupon-form"
                    className="fxc:space-y-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <Method automatic={draft.automatic} onChange={(v) => set("automatic", v)} />

                    <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                        <Field
                            label={draft.automatic ? __("Name") : __("Code")}
                            help={draft.automatic ? __("Shoppers see this name in the cart, as in Spring sale.") : undefined}
                            error={errors.code}
                        >
                            {(id) => <Input id={id} value={draft.code} maxLength={100} required onChange={(e) => set("code", e.target.value)} />}
                        </Field>
                        <Field label={__("Status")} error={errors.status}>
                            {(id) => (
                                <Select
                                    id={id}
                                    className="fxc:w-full"
                                    value={draft.status}
                                    onChange={(e) => set("status", e.target.value as Draft["status"])}
                                    options={[
                                        { value: "active", label: __("Active") },
                                        { value: "disabled", label: __("Disabled") },
                                    ]}
                                />
                            )}
                        </Field>
                        <Field label={__("Discount type")} error={errors.type}>
                            {(id) => (
                                <Select
                                    id={id}
                                    className="fxc:w-full"
                                    value={draft.type}
                                    onChange={(e) => set("type", e.target.value as CouponType)}
                                    options={[
                                        { value: "percent", label: __("Percentage of the items") },
                                        { value: "fixed_cart", label: __("Fixed amount off the cart") },
                                        { value: "fixed_product", label: __("Fixed amount off each item") },
                                        { value: "buy_x_get_y", label: __("Buy X, get Y") },
                                    ]}
                                />
                            )}
                        </Field>
                        <Field
                            label={
                                draft.type === "buy_x_get_y"
                                    ? __("Percentage off the Y items")
                                    : isPercentType(draft.type)
                                      ? __("Percentage")
                                      : sprintf(__("Amount (%s)"), currency.code)
                            }
                            help={
                                draft.free_shipping
                                    ? __("May be 0 when the coupon only gives free shipping.")
                                    : draft.type === "fixed_product"
                                      ? __("Taken off every unit of the items it applies to.")
                                      : draft.type === "buy_x_get_y"
                                        ? __("100 makes them free.")
                                        : undefined
                            }
                            error={errors.amount}
                        >
                            {(id) =>
                                isPercentType(draft.type) ? (
                                    <Input id={id} inputMode="decimal" value={draft.percent} onChange={(e) => set("percent", e.target.value)} />
                                ) : (
                                    <MoneyInput id={id} value={draft.amount} exponent={currency.exponent} onChange={(minor) => set("amount", minor)} />
                                )
                            }
                        </Field>
                        <Field label={__("Starts")} help={__("Empty: from now.")} error={errors.starts_at}>
                            {(id) => <Input id={id} type="datetime-local" value={draft.starts_at} onChange={(e) => set("starts_at", e.target.value)} />}
                        </Field>
                        <Field label={__("Expires")} help={__("Empty: never.")} error={errors.expires_at}>
                            {(id) => <Input id={id} type="datetime-local" value={draft.expires_at} onChange={(e) => set("expires_at", e.target.value)} />}
                        </Field>
                        <Field label={__("Minimum spend")} help={__("Items subtotal before coupons. Empty: none.")} error={errors.min_subtotal}>
                            {(id) => (
                                <OptionalMoney id={id} value={draft.min_subtotal} exponent={currency.exponent} onChange={(v) => set("min_subtotal", v)} />
                            )}
                        </Field>
                        <Field label={__("Maximum spend")} help={__("Empty: none.")} error={errors.max_subtotal}>
                            {(id) => (
                                <OptionalMoney id={id} value={draft.max_subtotal} exponent={currency.exponent} onChange={(v) => set("max_subtotal", v)} />
                            )}
                        </Field>
                        <Field label={__("Usage limit")} help={__("Orders that can use it in total. Empty: no limit.")} error={errors.usage_limit}>
                            {(id) => (
                                <Input id={id} type="number" min={0} step={1} value={draft.usage_limit} onChange={(e) => set("usage_limit", e.target.value)} />
                            )}
                        </Field>
                        {!draft.automatic && (
                            <Field
                                label={__("Limit per customer")}
                                help={__("Counted by account and by billing email. Empty: no limit.")}
                                error={errors.per_customer_limit}
                            >
                                {(id) => (
                                    <Input
                                        id={id}
                                        type="number"
                                        min={0}
                                        step={1}
                                        value={draft.per_customer_limit}
                                        onChange={(e) => set("per_customer_limit", e.target.value)}
                                    />
                                )}
                            </Field>
                        )}
                        {draft.type !== "fixed_cart" && draft.type !== "buy_x_get_y" && (
                            <Field
                                label={__("Limit to items")}
                                help={__("Units in the cart it discounts at most, the most expensive first. Empty: every unit.")}
                                error={errors.item_limit}
                            >
                                {(id) => (
                                    <Input id={id} type="number" min={0} step={1} value={draft.item_limit} onChange={(e) => set("item_limit", e.target.value)} />
                                )}
                            </Field>
                        )}
                    </div>

                    {draft.type === "buy_x_get_y" && (
                        <fieldset className="fxc:space-y-4">
                            <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Buy X, get Y")}</legend>
                            <p className="fxc:text-muted-foreground fxc:text-xs">
                                {__(
                                    "Each set is X items bought and Y items discounted. The cheapest items it may discount go first; the products and categories under Restrictions are what counts as bought.",
                                )}
                            </p>
                            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                                <span className="fxc:text-muted-foreground fxc:text-xs">{__("Quick fill:")}</span>
                                {offers().map((offer) => (
                                    <Button
                                        key={offer.label}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        aria-pressed={draft.buy_qty === offer.buy && draft.get_qty === offer.get && parseDecimal(draft.percent) === Number(offer.percent)}
                                        onClick={() =>
                                            setDraft((d) => ({
                                                ...d,
                                                buy_qty: offer.buy,
                                                get_qty: offer.get,
                                                percent: offer.percent,
                                                code: d.automatic && d.code.trim() === "" ? offer.label : d.code,
                                            }))
                                        }
                                    >
                                        {offer.label}
                                    </Button>
                                ))}
                            </div>
                            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-3">
                                <Field label={__("Buy (X)")} error={errors.buy_qty}>
                                    {(id) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            min={1}
                                            max={999}
                                            step={1}
                                            required
                                            value={draft.buy_qty}
                                            onChange={(e) => set("buy_qty", e.target.value)}
                                        />
                                    )}
                                </Field>
                                <Field label={__("Get (Y)")} error={errors.get_qty}>
                                    {(id) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            min={1}
                                            max={999}
                                            step={1}
                                            required
                                            value={draft.get_qty}
                                            onChange={(e) => set("get_qty", e.target.value)}
                                        />
                                    )}
                                </Field>
                                <Field label={__("Sets per order")} help={__("Empty: no limit.")} error={errors.repeat_limit}>
                                    {(id) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            min={0}
                                            step={1}
                                            value={draft.repeat_limit}
                                            onChange={(e) => set("repeat_limit", e.target.value)}
                                        />
                                    )}
                                </Field>
                            </div>
                            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                                <Field
                                    label={__("Y products")}
                                    help={__("What a set discounts. Empty: the same items that count as bought.")}
                                    error={errors.get_product_ids ?? errors.get_variant_ids}
                                >
                                    {(id) => (
                                        <TargetPicker
                                            id={id}
                                            kind="product"
                                            value={draft.get_products}
                                            onChange={(v) => set("get_products", v)}
                                            placeholder={__("Search products")}
                                        />
                                    )}
                                </Field>
                                <Field label={__("Y categories")} help={__("Subcategories are included.")} error={errors.get_category_ids}>
                                    {(id) => (
                                        <TargetPicker
                                            id={id}
                                            kind="category"
                                            value={draft.get_categories}
                                            onChange={(v) => set("get_categories", v)}
                                            placeholder={__("Search categories")}
                                        />
                                    )}
                                </Field>
                            </div>
                        </fieldset>
                    )}

                    <div className="fxc:space-y-3">
                        <Check
                            checked={draft.individual_use}
                            onChange={(v) => set("individual_use", v)}
                            label={__("Use on its own")}
                            help={
                                draft.automatic
                                    ? __("Applies only when the cart has no other discount.")
                                    : __("Applying it removes the other coupons in the cart, and no other coupon can be added with it.")
                            }
                        />
                        <Check
                            checked={draft.exclude_sale_items}
                            onChange={(v) => set("exclude_sale_items", v)}
                            label={__("Exclude sale items")}
                            help={__("Items with a sale price get no discount from this coupon.")}
                        />
                        <Check
                            checked={draft.free_shipping}
                            onChange={(v) => set("free_shipping", v)}
                            label={__("Free shipping")}
                            help={__("Turns on the free shipping methods set to need a coupon.")}
                        />
                    </div>

                    <fieldset className="fxc:space-y-4">
                        <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Restrictions")}</legend>
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                            <Field
                                label={__("Products")}
                                help={__("Search by name or SKU. A product covers all its variations. Empty: all.")}
                                error={errors.product_ids ?? errors.variant_ids}
                            >
                                {(id) => (
                                    <TargetPicker
                                        id={id}
                                        kind="product"
                                        value={draft.products}
                                        onChange={(v) => set("products", v)}
                                        placeholder={__("Search products")}
                                    />
                                )}
                            </Field>
                            <Field label={__("Excluded products")} error={errors.exclude_product_ids ?? errors.exclude_variant_ids}>
                                {(id) => (
                                    <TargetPicker
                                        id={id}
                                        kind="product"
                                        value={draft.exclude_products}
                                        onChange={(v) => set("exclude_products", v)}
                                        placeholder={__("Search products")}
                                    />
                                )}
                            </Field>
                            <Field label={__("Categories")} help={__("Subcategories are included. Empty: all.")} error={errors.category_ids}>
                                {(id) => (
                                    <TargetPicker
                                        id={id}
                                        kind="category"
                                        value={draft.categories}
                                        onChange={(v) => set("categories", v)}
                                        placeholder={__("Search categories")}
                                    />
                                )}
                            </Field>
                            <Field label={__("Excluded categories")} error={errors.exclude_category_ids}>
                                {(id) => (
                                    <TargetPicker
                                        id={id}
                                        kind="category"
                                        value={draft.exclude_categories}
                                        onChange={(v) => set("exclude_categories", v)}
                                        placeholder={__("Search categories")}
                                    />
                                )}
                            </Field>
                        </div>
                        {!draft.automatic && (
                            <Field
                                label={__("Allowed emails")}
                                help={__("Billing emails that may use it, comma separated; * matches anything, as in *@example.com. Empty: everyone.")}
                                error={errors.emails}
                            >
                                {(id) => <Textarea id={id} rows={2} value={draft.emails} onChange={(e) => set("emails", e.target.value)} />}
                            </Field>
                        )}
                    </fieldset>

                    <fieldset className="fxc:space-y-3 fxc:border-t fxc:pt-4">
                        <legend className="fxc:mb-1 fxc:text-sm fxc:font-medium">{__("Conditions")}</legend>
                        <ConditionsEditor
                            value={draft.conditions}
                            onChange={(v) => set("conditions", v)}
                            error={errors.conditions}
                            emptyText={
                                draft.automatic
                                    ? __("No conditions: the discount applies to every cart the rules above allow.")
                                    : __("No conditions: the coupon works on every cart the rules above allow. A condition on the country, the shipping or the payment method is checked once the shopper gives it at checkout.")
                            }
                        />
                    </fieldset>

                    <Field label={__("Description")} help={__("For your team; shoppers do not see it.")} error={errors.description}>
                        {(id) => <Textarea id={id} rows={2} value={draft.description} onChange={(e) => set("description", e.target.value)} />}
                    </Field>
                </form>

                <DialogFooter>
                    <Button variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>
                        {__("Cancel")}
                    </Button>
                    <Button type="submit" form="fxc-coupon-form" disabled={busy}>
                        {busy ? __("Saving…") : coupon !== null ? (draft.automatic ? __("Save discount") : __("Save coupon")) : draft.automatic ? __("Create discount") : __("Create coupon")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** A money field that may be left empty (null). */
function OptionalMoney({ id, value, exponent, onChange }: { id: string; value: number | null; exponent: number; onChange: (value: number | null) => void }) {
    const [text, setText] = useState(() => minorToInput(value, exponent));
    useEffect(() => {
        setText((current) => (inputToMinor(current, exponent) === value ? current : minorToInput(value, exponent)));
    }, [value, exponent]);
    return (
        <Input
            id={id}
            inputMode="decimal"
            value={text}
            onChange={(e) => {
                setText(e.target.value);
                const minor = inputToMinor(e.target.value, exponent);
                onChange(minor === null ? null : Number.isNaN(minor) ? -1 : minor);
            }}
        />
    );
}
