import { useEffect, useId, useState, type ReactNode } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { Textarea } from "@/shared/components/ui/textarea";
import { ApiError, getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { inputToMinor, minorToInput, parseDecimal } from "@/shared/lib/units";
import { MoneyInput } from "../../components/MoneyInput";
import { TargetPicker, type Pick } from "./TargetPicker";
import { useSaveCoupon, type Coupon, type CouponInput, type CouponType } from "./useCoupons";

/** What the form edits; dates are `datetime-local` text in the browser's time zone. */
interface Draft {
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
    individual_use: boolean;
    exclude_sale_items: boolean;
    free_shipping: boolean;
    products: Pick[];
    exclude_products: Pick[];
    categories: Pick[];
    exclude_categories: Pick[];
    emails: string;
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
    return {
        code: coupon?.code ?? "",
        type: coupon?.type ?? "percent",
        percent: coupon?.type === "percent" ? String(coupon.amount / 100) : "",
        amount: coupon !== null && coupon.type !== "percent" ? coupon.amount : 0,
        status: coupon?.status ?? "active",
        description: coupon?.description ?? "",
        starts_at: toLocalInput(coupon?.starts_at ?? null),
        expires_at: toLocalInput(coupon?.expires_at ?? null),
        min_subtotal: coupon?.min_subtotal ?? null,
        max_subtotal: coupon?.max_subtotal ?? null,
        usage_limit: coupon !== null && coupon.usage_limit > 0 ? String(coupon.usage_limit) : "",
        per_customer_limit: coupon !== null && coupon.per_customer_limit > 0 ? String(coupon.per_customer_limit) : "",
        individual_use: coupon?.individual_use ?? false,
        exclude_sale_items: coupon?.exclude_sale_items ?? false,
        free_shipping: coupon?.free_shipping ?? false,
        products: [...picks("product", coupon?.product_ids), ...picks("variant", coupon?.variant_ids)],
        exclude_products: [...picks("product", coupon?.exclude_product_ids), ...picks("variant", coupon?.exclude_variant_ids)],
        categories: picks("category", coupon?.category_ids),
        exclude_categories: picks("category", coupon?.exclude_category_ids),
        emails: (coupon?.emails ?? []).join(", "),
    };
}

function inputOf(draft: Draft): CouponInput {
    const percent = parseDecimal(draft.percent);
    return {
        code: draft.code.trim(),
        type: draft.type,
        amount: draft.type === "percent" ? (percent === null || Number.isNaN(percent) ? 0 : Math.round(percent * 100)) : draft.amount,
        status: draft.status,
        description: draft.description,
        starts_at: toUtc(draft.starts_at),
        expires_at: toUtc(draft.expires_at),
        min_subtotal: draft.min_subtotal,
        max_subtotal: draft.max_subtotal,
        usage_limit: Number(draft.usage_limit) || 0,
        per_customer_limit: Number(draft.per_customer_limit) || 0,
        individual_use: draft.individual_use,
        exclude_sale_items: draft.exclude_sale_items,
        free_shipping: draft.free_shipping,
        product_ids: idsOf(draft.products, "product"),
        exclude_product_ids: idsOf(draft.exclude_products, "product"),
        variant_ids: idsOf(draft.products, "variant"),
        exclude_variant_ids: idsOf(draft.exclude_products, "variant"),
        category_ids: idsOf(draft.categories, "category"),
        exclude_category_ids: idsOf(draft.exclude_categories, "category"),
        emails: draft.emails
            .split(/[\s,]+/)
            .map((e) => e.trim())
            .filter(Boolean),
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

interface Props {
    open: boolean;
    coupon: Coupon | null;
    onOpenChange: (open: boolean) => void;
}

export function CouponDialog({ open, coupon, onOpenChange }: Props) {
    const { currency } = getPluginGlobal();
    const [draft, setDraft] = useState<Draft>(() => draftOf(coupon));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const save = useSaveCoupon();

    useEffect(() => {
        if (open) {
            setDraft(draftOf(coupon));
            setErrors({});
        }
    }, [open, coupon]);

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
                    showToast.success(coupon !== null ? __("Coupon saved.") : sprintf(__("Coupon %s created."), saved.code));
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
                    <DialogTitle>{coupon !== null ? __("Edit coupon") : __("New coupon")}</DialogTitle>
                    <DialogDescription>
                        {__("Shoppers enter the code in the cart. Codes are not case sensitive. Dates use your browser's time zone.")}
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
                    <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                        <Field label={__("Code")} error={errors.code}>
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
                                    ]}
                                />
                            )}
                        </Field>
                        <Field
                            label={draft.type === "percent" ? __("Percentage") : sprintf(__("Amount (%s)"), currency.code)}
                            help={
                                draft.free_shipping
                                    ? __("May be 0 when the coupon only gives free shipping.")
                                    : draft.type === "fixed_product"
                                      ? __("Taken off every unit of the items it applies to.")
                                      : undefined
                            }
                            error={errors.amount}
                        >
                            {(id) =>
                                draft.type === "percent" ? (
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
                    </div>

                    <div className="fxc:space-y-3">
                        <Check
                            checked={draft.individual_use}
                            onChange={(v) => set("individual_use", v)}
                            label={__("Use on its own")}
                            help={__("Applying it removes the other coupons in the cart, and no other coupon can be added with it.")}
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
                        <Field
                            label={__("Allowed emails")}
                            help={__("Billing emails that may use it, comma separated; * matches anything, as in *@example.com. Empty: everyone.")}
                            error={errors.emails}
                        >
                            {(id) => <Textarea id={id} rows={2} value={draft.emails} onChange={(e) => set("emails", e.target.value)} />}
                        </Field>
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
                        {busy ? __("Saving…") : coupon !== null ? __("Save coupon") : __("Create coupon")}
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
