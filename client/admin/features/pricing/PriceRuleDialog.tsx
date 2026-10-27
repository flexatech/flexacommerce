import { useEffect, useId, useState, type ReactNode } from "react";
import { Plus, X } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { ApiError, getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { formatMoney, parseDecimal } from "@/shared/lib/units";
import { MoneyInput } from "../../components/MoneyInput";
import { ConditionsEditor } from "../conditions/ConditionsEditor";
import { NO_CONDITIONS, type ConditionBlock } from "../conditions/useConditions";
import { TargetPicker, type Pick as Choice } from "../coupons/TargetPicker";
import { changed, changeText, feeOn, feeText } from "./ruleText";
import {
    useSavePriceRule,
    type PriceRule,
    type PriceRuleInput,
    type Role,
    type RuleCount,
    type RuleCustomers,
    type RuleMode,
    type RuleSaleItems,
    type RuleScope,
    type RuleType,
    type Tier,
} from "./usePriceRules";

export interface RulePreset {
    key: string;
    name: string;
    description: string;
    /** Money (a fixed amount, a cart subtotal) is written for a currency with 2 decimals and scaled to the store's. */
    values: Pick<PriceRuleInput, "type" | "mode" | "amount" | "tiers" | "applies_to" | "customers"> & Partial<Pick<PriceRuleInput, "conditions">>;
}

export const RULE_PRESETS: RulePreset[] = [
    {
        key: "category",
        name: __("Category sale"),
        description: __("A percent off every product in the categories you pick."),
        values: { type: "adjust", mode: "percent", amount: 2000, tiers: [], applies_to: "categories", customers: "all" },
    },
    {
        key: "bulk",
        name: __("Bulk discount"),
        description: __("A lower unit price from 5 and from 10 units."),
        values: {
            type: "bulk",
            mode: "percent",
            amount: 0,
            tiers: [
                { min: 5, mode: "percent", amount: 500 },
                { min: 10, mode: "percent", amount: 1000 },
            ],
            applies_to: "all",
            customers: "all",
        },
    },
    {
        key: "graduated",
        name: __("Graduated tiers"),
        description: __("Units 5 to 9 cost less, and units from 10 cost less again."),
        values: {
            type: "graduated",
            mode: "percent",
            amount: 0,
            tiers: [
                { min: 5, mode: "percent", amount: 500 },
                { min: 10, mode: "percent", amount: 1000 },
            ],
            applies_to: "all",
            customers: "all",
        },
    },
    {
        key: "wholesale",
        name: __("Wholesale price"),
        description: __("A percent off for customers with the roles you pick."),
        values: { type: "adjust", mode: "percent", amount: 1500, tiers: [], applies_to: "all", customers: "roles" },
    },
    {
        key: "small_order",
        name: __("Small order fee"),
        description: __("A fixed fee when the cart subtotal is below an amount you pick."),
        values: {
            type: "fee",
            mode: "fixed",
            amount: 300,
            tiers: [],
            applies_to: "all",
            customers: "all",
            conditions: { match: "all", items: [{ type: "cart_subtotal", op: "lte", value: 2000 }] },
        },
    },
    {
        key: "payment_fee",
        name: __("Payment fee"),
        description: __("A percent of the products when the shopper pays with a method you pick."),
        values: {
            type: "fee",
            mode: "percent",
            amount: 200,
            tiers: [],
            applies_to: "all",
            customers: "all",
            conditions: { match: "all", items: [{ type: "payment_method", op: "in", value: [] }] },
        },
    },
    {
        key: "custom",
        name: __("Custom"),
        description: "",
        values: { type: "adjust", mode: "percent", amount: 1000, tiers: [], applies_to: "all", customers: "all" },
    },
];

/** A change as the form edits it: a percent as typed, or an amount in minor units. */
interface ChangeDraft {
    mode: RuleMode;
    percent: string;
    money: number;
}

interface TierDraft extends ChangeDraft {
    key: number;
    min: string;
}

interface Draft extends ChangeDraft {
    name: string;
    /** A percent fee's largest amount, in minor units; 0 for no limit. */
    cap: number;
    enabled: boolean;
    type: RuleType;
    tiers: TierDraft[];
    count: RuleCount;
    applies_to: RuleScope;
    products: Choice[];
    categories: Choice[];
    exclude_products: Choice[];
    customers: RuleCustomers;
    roles: string[];
    sale_items: RuleSaleItems;
    starts_at: string;
    ends_at: string;
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

const picks = (type: Choice["type"], ids: number[]): Choice[] => ids.map((id) => ({ type, id }));

const ids = (list: Choice[]): number[] => list.map((p) => p.id);

let nextKey = 1;

function changeDraft(mode: RuleMode, amount: number): ChangeDraft {
    return { mode, percent: mode === "percent" ? String(amount / 100) : "", money: mode === "percent" ? 0 : amount };
}

function amountOf(change: ChangeDraft): number {
    if (change.mode !== "percent") {
        return change.money;
    }
    const percent = parseDecimal(change.percent);
    return percent === null || Number.isNaN(percent) ? 0 : Math.round(percent * 100);
}

function tierDraft(tier: Tier): TierDraft {
    return { key: nextKey++, min: String(tier.min), ...changeDraft(tier.mode, tier.amount) };
}

const isTiered = (type: RuleType) => type === "bulk" || type === "graduated";

/** A preset's money, written for 2 decimals, in the store currency's minor units. */
function scaled(values: RulePreset["values"]): RulePreset["values"] {
    const { currency } = getPluginGlobal();
    const money = (minor: number) => Math.round((minor * 10 ** currency.exponent) / 100);
    return {
        ...values,
        amount: values.mode === "percent" ? values.amount : money(values.amount),
        conditions: values.conditions && {
            ...values.conditions,
            items: values.conditions.items.map((c) => (c.type === "cart_subtotal" ? { ...c, value: money(c.value) } : c)),
        },
    };
}

function draftOf(rule: PriceRule | null, preset: RulePreset | undefined): Draft {
    const start = rule ?? scaled({ ...RULE_PRESETS[RULE_PRESETS.length - 1].values, ...preset?.values });
    return {
        name: rule?.name ?? (preset !== undefined && preset.key !== "custom" ? preset.name : ""),
        enabled: rule?.enabled ?? true,
        type: start.type,
        ...changeDraft(start.mode, isTiered(start.type) ? 1000 : start.amount),
        cap: rule?.cap ?? 0,
        tiers: start.tiers.map(tierDraft),
        count: rule?.count ?? "product",
        applies_to: start.applies_to,
        products: picks("product", rule?.product_ids ?? []),
        categories: picks("category", rule?.category_ids ?? []),
        exclude_products: picks("product", rule?.exclude_product_ids ?? []),
        customers: start.customers,
        roles: rule?.roles ?? [],
        sale_items: rule?.sale_items ?? "apply",
        starts_at: toLocalInput(rule?.starts_at ?? null),
        ends_at: toLocalInput(rule?.ends_at ?? null),
        conditions: rule?.conditions ?? start.conditions ?? NO_CONDITIONS,
    };
}

function inputOf(draft: Draft): PriceRuleInput {
    const bulk = isTiered(draft.type);
    const fee = draft.type === "fee";
    return {
        name: draft.name.trim(),
        enabled: draft.enabled,
        type: draft.type,
        mode: bulk ? "percent" : draft.mode,
        amount: bulk ? 0 : amountOf(draft),
        cap: fee && draft.mode === "percent" ? draft.cap : 0,
        tiers: bulk ? draft.tiers.map((t) => ({ min: Number(t.min) || 0, mode: t.mode, amount: amountOf(t) })).sort((a, b) => a.min - b.min) : [],
        count: bulk ? draft.count : "product",
        applies_to: draft.applies_to,
        product_ids: draft.applies_to === "products" ? ids(draft.products) : [],
        category_ids: draft.applies_to === "categories" ? ids(draft.categories) : [],
        exclude_product_ids: ids(draft.exclude_products),
        customers: draft.customers,
        roles: draft.customers === "roles" ? draft.roles : [],
        sale_items: fee ? "apply" : draft.sale_items,
        starts_at: toUtc(draft.starts_at),
        ends_at: toUtc(draft.ends_at),
        conditions: draft.conditions,
    };
}

/** Only the fields that differ from the stored rule. */
function changes(input: PriceRuleInput, base: PriceRuleInput | null): Partial<PriceRuleInput> {
    if (base === null) {
        return input;
    }
    const out: Record<string, unknown> = {};
    for (const key of Object.keys(input) as (keyof PriceRuleInput)[]) {
        if (JSON.stringify(input[key]) !== JSON.stringify(base[key])) {
            out[key] = input[key];
        }
    }
    return out as Partial<PriceRuleInput>;
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

const MODE_OPTIONS: { value: RuleMode; label: string }[] = [
    { value: "percent", label: __("Percent off") },
    { value: "fixed", label: __("Amount off each") },
    { value: "price", label: __("Set the unit price") },
];

const FEE_MODE_OPTIONS: { value: RuleMode; label: string }[] = [
    { value: "fixed", label: __("A fixed amount") },
    { value: "percent", label: __("A percent of the products") },
];

/** The mode and its value side by side: a percent field or a money field. */
function ChangeInputs({
    value,
    onChange,
    label,
    options = MODE_OPTIONS,
}: {
    value: ChangeDraft;
    onChange: (v: ChangeDraft) => void;
    label: string;
    options?: { value: RuleMode; label: string }[];
}) {
    const { currency } = getPluginGlobal();
    return (
        <div className="fxc:flex fxc:items-center fxc:gap-2">
            <Select
                aria-label={sprintf(__("%s: kind of change"), label)}
                className="fxc:w-44"
                value={value.mode}
                onChange={(e) => onChange({ ...value, mode: e.target.value as RuleMode })}
                options={options}
            />
            {value.mode === "percent" ? (
                <div className="fxc:flex fxc:items-center fxc:gap-1">
                    <Input
                        aria-label={sprintf(__("%s: percent"), label)}
                        inputMode="decimal"
                        className="fxc:w-24"
                        value={value.percent}
                        onChange={(e) => onChange({ ...value, percent: e.target.value })}
                    />
                    <span className="fxc:text-muted-foreground fxc:text-sm">%</span>
                </div>
            ) : (
                <div className="fxc:flex fxc:items-center fxc:gap-1">
                    <span className="fxc:text-muted-foreground fxc:text-sm">{currency.symbol}</span>
                    <MoneyInput
                        aria-label={sprintf(__("%s: amount"), label)}
                        className="fxc:w-28"
                        value={value.money}
                        exponent={currency.exponent}
                        onChange={(minor) => onChange({ ...value, money: minor })}
                    />
                </div>
            )}
        </div>
    );
}

function rangeText(from: number, to: number): string {
    return from === to ? String(from) : sprintf(__("%1$d to %2$d"), from, to);
}

/** Units 1 to `quantity`, each at the price of the tier its position reaches (ADR-042). */
function graduatedTotal(price: number, tiers: Tier[], quantity: number): number {
    let total = 0;
    for (let unit = 1; unit <= quantity; unit++) {
        const tier = tiers.filter((t) => t.min <= unit).pop();
        total += tier === undefined ? price : changed(price, tier.mode, tier.amount);
    }
    return total;
}

/** What a fee adds to carts of 50 and 200, so the percent and the cap are easy to check. */
function FeePreview({ draft }: { draft: Draft }) {
    const { currency } = getPluginGlobal();
    const money = (minor: number) => formatMoney(minor, currency);
    const amount = amountOf(draft);
    const cap = draft.mode === "percent" ? draft.cap : 0;
    return (
        <div aria-hidden className="fxc:space-y-3">
            <p className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium fxc:uppercase">{__("Example")}</p>
            <table className="fxc:w-full fxc:text-sm">
                <tbody>
                    {[50, 200].map((whole) => {
                        const base = whole * 10 ** currency.exponent;
                        return (
                            <tr key={whole} className="fxc:border-b fxc:last:border-b-0">
                                <td className="fxc:py-1">{sprintf(__("Products %s"), money(base))}</td>
                                <td className="fxc:py-1 fxc:text-end fxc:font-medium">+{money(feeOn(base, draft.mode, amount, cap))}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            <p className="fxc:text-muted-foreground fxc:text-xs">
                {__("Shoppers see the fee as its own line in the cart and at checkout. Fees add up, and they carry no tax.")}
            </p>
        </div>
    );
}

/** What a product at 100 would cost, so the numbers are easy to check. */
function Preview({ draft }: { draft: Draft }) {
    const { currency } = getPluginGlobal();
    const price = 100 * 10 ** currency.exponent;
    const money = (minor: number) => formatMoney(minor, currency);
    if (draft.type === "fee") {
        return <FeePreview draft={draft} />;
    }
    const rows = isTiered(draft.type)
        ? [...draft.tiers]
              .map((t) => ({ min: Number(t.min) || 0, mode: t.mode, amount: amountOf(t) }))
              .filter((t) => t.min > 0)
              .sort((a, b) => a.min - b.min)
        : [];
    const graduated = draft.type === "graduated";
    const sample = rows.length > 0 ? rows[rows.length - 1].min + 2 : 0;
    return (
        <div aria-hidden className="fxc:space-y-3">
            <p className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium fxc:uppercase">{__("Example")}</p>
            <p className="fxc:text-muted-foreground fxc:text-xs">{sprintf(__("A product priced %s:"), money(price))}</p>
            {draft.type === "adjust" ? (
                <p className="fxc:text-lg">
                    <del className="fxc:text-muted-foreground fxc:me-2 fxc:text-sm">{money(price)}</del>
                    <span className="fxc:font-semibold">{money(changed(price, draft.mode, amountOf(draft)))}</span>
                </p>
            ) : rows.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:text-sm">{__("Add a tier to see prices.")}</p>
            ) : (
                <table className="fxc:w-full fxc:text-sm">
                    <tbody>
                        {graduated && rows[0].min > 1 && (
                            <tr className="fxc:border-b">
                                <td className="fxc:py-1">{rangeText(1, rows[0].min - 1)}</td>
                                <td className="fxc:py-1 fxc:text-end fxc:font-medium">{money(price)}</td>
                            </tr>
                        )}
                        {rows.map((t, index) => (
                            <tr key={t.min} className="fxc:border-b fxc:last:border-b-0">
                                <td className="fxc:py-1">
                                    {graduated && index + 1 < rows.length ? rangeText(t.min, rows[index + 1].min - 1) : sprintf(__("%d or more"), t.min)}
                                </td>
                                <td className="fxc:py-1 fxc:text-end fxc:font-medium">{money(changed(price, t.mode, t.amount))}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {graduated && rows.length > 0 && (
                <p className="fxc:text-sm">
                    {sprintf(__("%1$d units cost %2$s in all."), sample, money(graduatedTotal(price, rows, sample)))}
                </p>
            )}
            {isTiered(draft.type) && rows.length > 0 && (
                <p className="fxc:text-muted-foreground fxc:text-xs">
                    {graduated
                        ? __("Each price applies only to the units in its range. The product page lists the ranges under the Add to cart button.")
                        : __("The product page lists these tiers under the Add to cart button.")}
                </p>
            )}
        </div>
    );
}

interface Props {
    open: boolean;
    rule: PriceRule | null;
    preset?: RulePreset;
    roles: Role[];
    onOpenChange: (open: boolean) => void;
}

export function PriceRuleDialog({ open, rule, preset, roles, onOpenChange }: Props) {
    const [draft, setDraft] = useState<Draft>(() => draftOf(rule, preset));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [chosen, setChosen] = useState<string>(preset?.key ?? "custom");
    const save = useSavePriceRule();

    useEffect(() => {
        if (open) {
            setDraft(draftOf(rule, preset));
            setChosen(preset?.key ?? "custom");
            setErrors({});
        }
    }, [open, rule, preset]);

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));

    const applyPreset = (p: RulePreset) => {
        setChosen(p.key);
        setDraft((d) => {
            const next = draftOf(null, p);
            // A name the merchant typed survives; a preset's name follows the preset.
            const typed = d.name.trim() !== "" && !RULE_PRESETS.some((q) => q.name === d.name);
            return typed ? { ...next, name: d.name } : next;
        });
        setErrors({});
    };

    const setTier = (key: number, value: Partial<TierDraft>) => set("tiers", draft.tiers.map((t) => (t.key === key ? { ...t, ...value } : t)));

    const addTier = () => {
        const last = draft.tiers.reduce((max, t) => Math.max(max, Number(t.min) || 0), 0);
        set("tiers", [...draft.tiers, { key: nextKey++, min: String(last > 0 ? last * 2 : 5), mode: "percent", percent: "", money: 0 }]);
    };

    const submit = () => {
        const body = changes(inputOf(draft), rule !== null ? inputOf(draftOf(rule, undefined)) : null);
        if (rule !== null && Object.keys(body).length === 0) {
            onOpenChange(false);
            return;
        }
        save.mutate(
            { id: rule?.id ?? 0, input: body },
            {
                onSuccess: () => {
                    showToast.success(rule !== null ? __("Rule saved.") : __("Rule added."));
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
    const fee = draft.type === "fee";

    return (
        <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
            <DialogContent className="fxc:max-h-[90vh] fxc:max-w-3xl fxc:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{rule !== null ? __("Edit pricing rule") : __("New pricing rule")}</DialogTitle>
                    <DialogDescription>
                        {fee
                            ? __("Set the fee, which carts and customers pay it, and when. Dates use your browser's time zone.")
                            : __("Set the new price, which products and customers get it, and when. Dates use your browser's time zone.")}
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="fxc-price-rule-form"
                    className="fxc:grid fxc:gap-6 fxc:md:grid-cols-[minmax(0,1fr)_12rem]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div className="fxc:space-y-6">
                        {rule === null && (
                            <fieldset>
                                <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Start from")}</legend>
                                <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                                    {RULE_PRESETS.map((p) => (
                                        <button
                                            key={p.key}
                                            type="button"
                                            aria-pressed={chosen === p.key}
                                            className={cn(
                                                "fxc:rounded-full fxc:border fxc:px-3 fxc:py-1 fxc:text-sm fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                                                chosen === p.key ? "fxc:border-foreground fxc:bg-foreground fxc:text-background" : "fxc:hover:bg-muted",
                                            )}
                                            onClick={() => applyPreset(p)}
                                        >
                                            {p.name}
                                        </button>
                                    ))}
                                </div>
                            </fieldset>
                        )}

                        <section className="fxc:space-y-4">
                            <h3 className="fxc:text-sm fxc:font-semibold">{fee ? __("Fee") : __("Price")}</h3>
                            <Field
                                label={__("Name")}
                                help={fee ? __("Shoppers see it next to the fee, in the cart, at checkout and on the order.") : __("Only you see it, in this list.")}
                                error={errors.name}
                            >
                                {(id) => <Input id={id} maxLength={60} value={draft.name} onChange={(e) => set("name", e.target.value)} />}
                            </Field>
                            <Field label={__("Kind of rule")}>
                                {(id) => (
                                    <Select
                                        id={id}
                                        className="fxc:sm:w-72"
                                        value={draft.type}
                                        onChange={(e) => {
                                            const type = e.target.value as RuleType;
                                            setDraft((d) => ({
                                                ...d,
                                                type,
                                                // A fee is a fixed amount or a percent; it never sets a price.
                                                mode: type === "fee" && d.mode === "price" ? "fixed" : d.mode,
                                                tiers: isTiered(type) && d.tiers.length === 0 ? RULE_PRESETS[1].values.tiers.map(tierDraft) : d.tiers,
                                            }));
                                        }}
                                        options={[
                                            { value: "adjust", label: __("The same for any quantity") },
                                            { value: "bulk", label: __("By quantity bought (bulk)") },
                                            { value: "graduated", label: __("Each unit at its tier (graduated)") },
                                            { value: "fee", label: __("A fee added at checkout") },
                                        ]}
                                    />
                                )}
                            </Field>

                            {fee ? (
                                <div className="fxc:space-y-4">
                                    <div>
                                        <ChangeInputs
                                            label={__("Fee")}
                                            value={draft}
                                            options={FEE_MODE_OPTIONS}
                                            onChange={(v) => setDraft((d) => ({ ...d, ...v }))}
                                        />
                                        {(errors.amount || errors.mode) && <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{errors.amount || errors.mode}</p>}
                                    </div>
                                    {draft.mode === "percent" && (
                                        <Field label={__("Largest fee")} help={__("0 for no limit.")} error={errors.cap}>
                                            {(id) => (
                                                <div className="fxc:flex fxc:items-center fxc:gap-1">
                                                    <span className="fxc:text-muted-foreground fxc:text-sm">{getPluginGlobal().currency.symbol}</span>
                                                    <MoneyInput
                                                        id={id}
                                                        className="fxc:w-28"
                                                        value={draft.cap}
                                                        exponent={getPluginGlobal().currency.exponent}
                                                        onChange={(minor) => set("cap", minor)}
                                                    />
                                                </div>
                                            )}
                                        </Field>
                                    )}
                                </div>
                            ) : draft.type === "adjust" ? (
                                <div>
                                    <ChangeInputs label={__("Price change")} value={draft} onChange={(v) => setDraft((d) => ({ ...d, ...v }))} />
                                    {errors.amount && <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{errors.amount}</p>}
                                </div>
                            ) : (
                                <div className="fxc:space-y-3">
                                    <div className="fxc:space-y-2">
                                        <div className="fxc:text-muted-foreground fxc:grid fxc:grid-cols-[6rem_minmax(0,1fr)_2.25rem] fxc:gap-2 fxc:text-xs fxc:font-medium">
                                            <span>{draft.type === "graduated" ? __("From unit") : __("From quantity")}</span>
                                            <span>{__("Unit price")}</span>
                                        </div>
                                        {draft.tiers.map((tier, index) => (
                                            <div key={tier.key} className="fxc:grid fxc:grid-cols-[6rem_minmax(0,1fr)_2.25rem] fxc:items-center fxc:gap-2">
                                                <Input
                                                    aria-label={sprintf(__("Tier %d: from quantity"), index + 1)}
                                                    type="number"
                                                    min={1}
                                                    value={tier.min}
                                                    onChange={(e) => setTier(tier.key, { min: e.target.value })}
                                                />
                                                <ChangeInputs label={sprintf(__("Tier %d"), index + 1)} value={tier} onChange={(v) => setTier(tier.key, v)} />
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={sprintf(__("Remove tier %d"), index + 1)}
                                                    onClick={() => set("tiers", draft.tiers.filter((t) => t.key !== tier.key))}
                                                >
                                                    <X aria-hidden className="fxc:size-4" />
                                                </Button>
                                            </div>
                                        ))}
                                        {errors.tiers && <p className="fxc:text-destructive fxc:text-xs">{errors.tiers}</p>}
                                        <Button variant="outline" size="sm" disabled={draft.tiers.length >= 10} onClick={addTier}>
                                            <Plus aria-hidden className="fxc:size-4" />
                                            {__("Add tier")}
                                        </Button>
                                    </div>
                                    <Field
                                        label={__("Count")}
                                        help={draft.count === "product" ? __("2 red and 3 blue shirts count as 5.") : __("2 red and 3 blue shirts count as 2 and 3.")}
                                    >
                                        {(id) => (
                                            <Select
                                                id={id}
                                                className="fxc:sm:w-72"
                                                value={draft.count}
                                                onChange={(e) => set("count", e.target.value as RuleCount)}
                                                options={[
                                                    { value: "product", label: __("All variations of a product together") },
                                                    { value: "variant", label: __("Each variation on its own") },
                                                ]}
                                            />
                                        )}
                                    </Field>
                                </div>
                            )}
                        </section>

                        <section className="fxc:space-y-4 fxc:border-t fxc:pt-5">
                            <h3 className="fxc:text-sm fxc:font-semibold">{__("Products")}</h3>
                            <Field
                                label={__("Applies to")}
                                help={fee ? __("The fee needs one of these products in the cart. A percent is taken from these products only.") : undefined}
                            >
                                {(id) => (
                                    <Select
                                        id={id}
                                        className="fxc:sm:w-64"
                                        value={draft.applies_to}
                                        onChange={(e) => set("applies_to", e.target.value as RuleScope)}
                                        options={[
                                            { value: "all", label: __("All products") },
                                            { value: "products", label: __("Chosen products") },
                                            { value: "categories", label: __("Products in chosen categories") },
                                        ]}
                                    />
                                )}
                            </Field>
                            {draft.applies_to === "products" && (
                                <Field label={__("Products")} error={errors.product_ids}>
                                    {(id) => (
                                        <TargetPicker
                                            id={id}
                                            kind="product"
                                            variants={false}
                                            value={draft.products}
                                            onChange={(v) => set("products", v)}
                                            placeholder={__("Search by name or SKU")}
                                        />
                                    )}
                                </Field>
                            )}
                            {draft.applies_to === "categories" && (
                                <Field label={__("Categories")} help={__("Subcategories count too.")} error={errors.category_ids}>
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
                            )}
                            <Field label={__("Except these products")} error={errors.exclude_product_ids}>
                                {(id) => (
                                    <TargetPicker
                                        id={id}
                                        kind="product"
                                        variants={false}
                                        value={draft.exclude_products}
                                        onChange={(v) => set("exclude_products", v)}
                                        placeholder={__("None")}
                                    />
                                )}
                            </Field>
                            {!fee && (
                                <Field label={__("Products on sale")}>
                                    {(id) => (
                                        <Select
                                            id={id}
                                            className="fxc:sm:w-72"
                                            value={draft.sale_items}
                                            onChange={(e) => set("sale_items", e.target.value as RuleSaleItems)}
                                            options={[
                                                { value: "apply", label: __("Apply the rule to the sale price") },
                                                { value: "skip", label: __("Leave them at their sale price") },
                                            ]}
                                        />
                                    )}
                                </Field>
                            )}
                        </section>

                        <section className="fxc:space-y-4 fxc:border-t fxc:pt-5">
                            <h3 className="fxc:text-sm fxc:font-semibold">{__("Customers and dates")}</h3>
                            <Field
                                label={__("Customers")}
                                help={
                                    draft.customers === "all"
                                        ? undefined
                                        : fee
                                          ? __("Guests and other customers pay no fee.")
                                          : __("Guests and other customers see the normal price.")
                                }
                            >
                                {(id) => (
                                    <Select
                                        id={id}
                                        className="fxc:sm:w-64"
                                        value={draft.customers}
                                        onChange={(e) => set("customers", e.target.value as RuleCustomers)}
                                        options={[
                                            { value: "all", label: __("Everyone") },
                                            { value: "logged_in", label: __("Logged-in customers") },
                                            { value: "roles", label: __("Customers with chosen roles") },
                                        ]}
                                    />
                                )}
                            </Field>
                            {draft.customers === "roles" && (
                                <fieldset>
                                    <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Roles")}</legend>
                                    <div className="fxc:grid fxc:gap-2 fxc:sm:grid-cols-2">
                                        {roles.map((role) => (
                                            <label key={role.value} className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                                                <input
                                                    type="checkbox"
                                                    className="flexacommerce-check"
                                                    checked={draft.roles.includes(role.value)}
                                                    onChange={(e) =>
                                                        set("roles", e.target.checked ? [...draft.roles, role.value] : draft.roles.filter((r) => r !== role.value))
                                                    }
                                                />
                                                {role.label}
                                            </label>
                                        ))}
                                    </div>
                                    {errors.roles && <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{errors.roles}</p>}
                                </fieldset>
                            )}
                            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                                <Field label={__("Starts")} help={__("Empty: right away.")} error={errors.starts_at}>
                                    {(id) => <Input id={id} type="datetime-local" value={draft.starts_at} onChange={(e) => set("starts_at", e.target.value)} />}
                                </Field>
                                <Field
                                    label={__("Ends")}
                                    help={
                                        draft.ends_at && !fee
                                            ? __("Shoppers see a countdown on the product page in the last days (Settings > Storefront).")
                                            : __("Empty: never.")
                                    }
                                    error={errors.ends_at}
                                >
                                    {(id) => <Input id={id} type="datetime-local" value={draft.ends_at} onChange={(e) => set("ends_at", e.target.value)} />}
                                </Field>
                            </div>
                        </section>

                        <section className="fxc:space-y-4 fxc:border-t fxc:pt-5">
                            <h3 className="fxc:text-sm fxc:font-semibold">{__("Conditions")}</h3>
                            <ConditionsEditor
                                value={draft.conditions}
                                onChange={(v) => set("conditions", v)}
                                error={errors.conditions}
                                fee={fee}
                                emptyText={
                                    fee
                                        ? __("No conditions: the fee applies whenever the products, customers and dates match. A fee that checks the payment or shipping method waits until the shopper picks one.")
                                        : __("No conditions: the rule applies whenever the products, customers and dates match. Cart conditions are checked in the cart, so the product page shows the normal price.")
                                }
                            />
                        </section>
                    </div>

                    <div className="fxc:md:sticky fxc:md:top-0 fxc:self-start">
                        <Preview draft={draft} />
                        {draft.type === "adjust" && (
                            <p className="fxc:text-muted-foreground fxc:mt-3 fxc:text-xs">{changeText(draft.mode, amountOf(draft))}</p>
                        )}
                        {fee && (
                            <p className="fxc:text-muted-foreground fxc:mt-3 fxc:text-xs">
                                {feeText(draft.mode, amountOf(draft), draft.mode === "percent" ? draft.cap : 0)}
                            </p>
                        )}
                    </div>
                </form>

                <DialogFooter>
                    <Button variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>
                        {__("Cancel")}
                    </Button>
                    <Button type="submit" form="fxc-price-rule-form" disabled={busy}>
                        {busy ? __("Saving…") : rule !== null ? __("Save rule") : __("Add rule")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
