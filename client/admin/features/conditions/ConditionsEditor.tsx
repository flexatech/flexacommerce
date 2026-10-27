import { useEffect, useId, useRef, useState } from "react";
import { Plus, X } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { gramsToInput, inputToGrams, inputToMinor, minorToInput } from "@/shared/lib/units";
import { TargetPicker } from "../coupons/TargetPicker";
import {
    isNumber,
    MAX_CONDITIONS,
    NUMBER_TYPES,
    useConditionChoices,
    type Choice,
    type Condition,
    type ConditionBlock,
    type ConditionType,
    type ListCondition,
    type NumberCondition,
} from "./useConditions";

const TYPE_OPTIONS: { value: ConditionType; label: string }[] = [
    { value: "cart_quantity", label: __("Items in the cart") },
    { value: "cart_weight", label: __("Cart weight") },
    { value: "cart_subtotal", label: __("Cart subtotal") },
    { value: "country", label: __("Country") },
    { value: "shipping_method", label: __("Shipping method") },
    { value: "payment_method", label: __("Payment method") },
    { value: "coupon", label: __("Coupon in the cart") },
    { value: "lifetime_spend", label: __("Spent before") },
    { value: "order_count", label: __("Orders placed before") },
    { value: "purchased", label: __("Bought before") },
];

const HELP: Partial<Record<ConditionType, string>> = {
    cart_subtotal: __("The items after coupons and pricing rules, before shipping and tax."),
    lifetime_spend: __("Paid orders, less refunds. Guests have spent 0."),
    order_count: __("Paid orders. At most 0 means a first order."),
    purchased: __("Any of these products in a paid order. Guests have bought nothing."),
    shipping_method: __("A method in one zone, or a kind of method in any zone."),
    coupon: __("Codes separated by commas."),
};

const isMoney = (type: ConditionType) => type === "lifetime_spend" || type === "cart_subtotal";

function fresh(type: ConditionType): Condition {
    if ((NUMBER_TYPES as string[]).includes(type)) {
        return { type, op: "gte", value: type === "order_count" ? 1 : 0 } as NumberCondition;
    }
    return { type, op: "in", value: [] } as ListCondition;
}

/** A whole number typed as the merchant reads it: a weight in the store unit, money as a decimal. */
function NumberValue({ item, label, onChange }: { item: NumberCondition; label: string; onChange: (value: number) => void }) {
    const { currency, units } = getPluginGlobal();
    const format = (v: number) =>
        item.type === "cart_weight" ? gramsToInput(String(v), units.weight) : isMoney(item.type) ? minorToInput(v, currency.exponent) : String(v);
    const parse = (text: string): number => {
        if (item.type === "cart_weight") {
            const grams = inputToGrams(text, units.weight);
            return grams === false ? NaN : Math.round(Number(grams ?? 0));
        }
        if (isMoney(item.type)) {
            return inputToMinor(text, currency.exponent) ?? 0;
        }
        return text.trim() === "" ? 0 : /^\d+$/.test(text.trim()) ? Number(text.trim()) : NaN;
    };
    const [text, setText] = useState(() => format(item.value));
    useEffect(() => {
        setText((current) => (parse(current) === item.value ? current : format(item.value)));
    }, [item.value, item.type]);
    const suffix = item.type === "cart_weight" ? units.weight : isMoney(item.type) ? currency.symbol : "";

    return (
        <div className="fxc:flex fxc:items-center fxc:gap-1">
            <Input
                aria-label={label}
                inputMode={item.type === "cart_weight" || isMoney(item.type) ? "decimal" : "numeric"}
                className="fxc:w-28"
                value={text}
                onChange={(e) => {
                    setText(e.target.value);
                    onChange(parse(e.target.value));
                }}
            />
            {suffix && <span className="fxc:text-muted-foreground fxc:text-sm">{suffix}</span>}
        </div>
    );
}

/** Chips with a select to add one more. */
function ChoiceList({ id, value, choices, label, onChange }: { id: string; value: string[]; choices: Choice[]; label: string; onChange: (value: string[]) => void }) {
    const title = (v: string) => choices.find((c) => c.id === v)?.title ?? v;
    return (
        <div className="fxc:space-y-2">
            {value.length > 0 && (
                <ul className="fxc:flex fxc:flex-wrap fxc:gap-1.5">
                    {value.map((v) => (
                        <li key={v} className="fxc:bg-muted fxc:flex fxc:items-center fxc:gap-1 fxc:rounded-full fxc:py-0.5 fxc:pr-1 fxc:pl-2.5 fxc:text-xs">
                            {title(v)}
                            <button
                                type="button"
                                aria-label={sprintf(__("Remove %s"), title(v))}
                                className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:cursor-pointer fxc:rounded-full fxc:bg-transparent fxc:p-0.5"
                                onClick={() => onChange(value.filter((x) => x !== v))}
                            >
                                <X aria-hidden className="fxc:size-3" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <select
                id={id}
                aria-label={label}
                className="flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:h-9 fxc:w-full fxc:rounded-md fxc:border fxc:px-3 fxc:text-sm"
                value=""
                onChange={(e) => e.target.value && onChange([...value, e.target.value])}
            >
                <option value="">{__("Add…")}</option>
                {choices.map((c) => (
                    <option key={c.id} value={c.id} disabled={value.includes(c.id)}>
                        {c.title}
                    </option>
                ))}
            </select>
        </div>
    );
}

function CodesValue({ value, label, onChange }: { value: string[]; label: string; onChange: (value: string[]) => void }) {
    const [text, setText] = useState(() => value.join(", "));
    return (
        <Input
            aria-label={label}
            value={text}
            placeholder="SUMMER, VIP"
            onChange={(e) => {
                setText(e.target.value);
                onChange(
                    e.target.value
                        .split(/[\s,]+/)
                        .map((c) => c.trim())
                        .filter((c) => c !== ""),
                );
            }}
        />
    );
}

interface Props {
    value: ConditionBlock;
    onChange: (value: ConditionBlock) => void;
    error?: string;
    /** What the conditions decide, for the empty state: "the rule applies" or "the coupon works". */
    emptyText: string;
    /** A checkout fee may also check the cart subtotal (ADR-043). */
    fee?: boolean;
}

/**
 * Extra conditions for a pricing rule or a coupon (ADR-041): the cart, the
 * country, the shipping and payment methods, other coupons, and what the
 * customer bought before.
 */
export function ConditionsEditor({ value, onChange, error, emptyText, fee = false }: Props) {
    const id = useId();
    const choices = useConditionChoices();
    const lists: Record<string, Choice[]> = {
        country: choices.data?.countries ?? [],
        payment_method: choices.data?.payment_methods ?? [],
        shipping_method: choices.data?.shipping_methods ?? [],
    };
    // Rows keep their own typed text, so each needs a key that survives removing the one above it.
    const keys = useRef<number[]>([]);
    const next = useRef(1);
    while (keys.current.length < value.items.length) {
        keys.current.push(next.current++);
    }
    keys.current.length = value.items.length;
    const setItem = (index: number, item: Condition) => onChange({ ...value, items: value.items.map((c, i) => (i === index ? item : c)) });
    const typeLabel = (type: ConditionType) => TYPE_OPTIONS.find((o) => o.value === type)?.label ?? type;
    const options = fee ? TYPE_OPTIONS : TYPE_OPTIONS.filter((o) => o.value !== "cart_subtotal");

    return (
        <div className="fxc:space-y-3">
            {value.items.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:text-xs">{emptyText}</p>
            ) : (
                value.items.length > 1 && (
                    <div className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                        <Select
                            aria-label={__("Which conditions must be met")}
                            className="fxc:w-56"
                            value={value.match}
                            onChange={(e) => onChange({ ...value, match: e.target.value as ConditionBlock["match"] })}
                            options={[
                                { value: "all", label: __("All of these conditions") },
                                { value: "any", label: __("Any one of these conditions") },
                            ]}
                        />
                    </div>
                )
            )}
            {value.items.map((item, index) => {
                const label = sprintf(__("Condition %d"), index + 1);
                const help = HELP[item.type];
                return (
                    <div key={keys.current[index]} className="fxc:space-y-1.5 fxc:rounded-md fxc:border fxc:p-3">
                        <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                            <Select
                                aria-label={sprintf(__("%s: what it checks"), label)}
                                className="fxc:w-48"
                                value={item.type}
                                onChange={(e) => setItem(index, fresh(e.target.value as ConditionType))}
                                options={options}
                            />
                            {isNumber(item) ? (
                                <>
                                    <Select
                                        aria-label={sprintf(__("%s: at least or at most"), label)}
                                        className="fxc:w-32"
                                        value={item.op}
                                        onChange={(e) => setItem(index, { ...item, op: e.target.value as NumberCondition["op"] })}
                                        options={[
                                            { value: "gte", label: __("at least") },
                                            { value: "lte", label: __("at most") },
                                        ]}
                                    />
                                    <NumberValue item={item} label={sprintf(__("%s: value"), label)} onChange={(v) => setItem(index, { ...item, value: v })} />
                                </>
                            ) : (
                                <Select
                                    aria-label={sprintf(__("%s: is or is not"), label)}
                                    className="fxc:w-32"
                                    value={item.op}
                                    onChange={(e) => setItem(index, { ...item, op: e.target.value as ListCondition["op"] })}
                                    options={[
                                        { value: "in", label: item.type === "purchased" || item.type === "coupon" ? __("any of") : __("is") },
                                        { value: "not_in", label: item.type === "purchased" || item.type === "coupon" ? __("none of") : __("is not") },
                                    ]}
                                />
                            )}
                            <Button
                                variant="ghost"
                                size="icon"
                                className="fxc:ms-auto"
                                aria-label={sprintf(__("Remove %s"), label)}
                                onClick={() => {
                                    keys.current.splice(index, 1);
                                    onChange({ ...value, items: value.items.filter((_, i) => i !== index) });
                                }}
                            >
                                <X aria-hidden className="fxc:size-4" />
                            </Button>
                        </div>
                        {!isNumber(item) &&
                            (item.type === "purchased" ? (
                                <TargetPicker
                                    id={`${id}-${index}`}
                                    kind="product"
                                    variants={false}
                                    value={item.value.map((p) => ({ type: "product" as const, id: Number(p) }))}
                                    onChange={(picks) => setItem(index, { ...item, value: picks.map((p) => p.id) })}
                                    placeholder={__("Search by name or SKU")}
                                />
                            ) : item.type === "coupon" ? (
                                <CodesValue value={item.value.map(String)} label={sprintf(__("%s: codes"), label)} onChange={(v) => setItem(index, { ...item, value: v })} />
                            ) : (
                                <ChoiceList
                                    id={`${id}-${index}`}
                                    value={item.value.map(String)}
                                    choices={lists[item.type] ?? []}
                                    label={sprintf(__("%1$s: add a value for %2$s"), label, typeLabel(item.type))}
                                    onChange={(v) => setItem(index, { ...item, value: v })}
                                />
                            ))}
                        {help && <p className="fxc:text-muted-foreground fxc:text-xs">{help}</p>}
                    </div>
                );
            })}
            {error && <p className="fxc:text-destructive fxc:text-xs">{error}</p>}
            <Button
                variant="outline"
                size="sm"
                disabled={value.items.length >= MAX_CONDITIONS}
                onClick={() => onChange({ ...value, items: [...value.items, fresh("cart_quantity")] })}
            >
                <Plus aria-hidden className="fxc:size-4" />
                {__("Add condition")}
            </Button>
        </div>
    );
}
