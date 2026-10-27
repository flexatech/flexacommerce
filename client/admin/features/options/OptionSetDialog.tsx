import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { AlignLeft, ArrowDown, ArrowUp, Calendar, CalendarClock, ChevronsUpDown, CircleDot, Clock, Hash, Heading, Palette, Plus, RectangleHorizontal, SlidersHorizontal, SquareCheck, SwatchBook, Trash2, Type, X } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { ApiError, getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { parseDecimal } from "@/shared/lib/units";
import { MoneyInput } from "../../components/MoneyInput";
import { TargetPicker, type Pick as Target } from "../coupons/TargetPicker";
import {
    CHOICE_TYPES,
    useSaveOptionSet,
    type Choice,
    type FieldType,
    type LogicOp,
    type LogicRule,
    type OptionField,
    type OptionSet,
    type OptionSetInput,
    type PriceMode,
    type SetScope,
} from "./useOptionSets";

export const FIELD_TYPES: { value: FieldType; label: string; icon: typeof Type }[] = [
    { value: "text", label: __("Short text"), icon: Type },
    { value: "textarea", label: __("Long text"), icon: AlignLeft },
    { value: "number", label: __("Number"), icon: Hash },
    { value: "range", label: __("Slider"), icon: SlidersHorizontal },
    { value: "date", label: __("Date"), icon: Calendar },
    { value: "time", label: __("Time"), icon: Clock },
    { value: "datetime", label: __("Date and time"), icon: CalendarClock },
    { value: "color", label: __("Color picker"), icon: Palette },
    { value: "select", label: __("Dropdown"), icon: ChevronsUpDown },
    { value: "radio", label: __("Radio buttons"), icon: CircleDot },
    { value: "checkboxes", label: __("Checkboxes"), icon: SquareCheck },
    { value: "swatch", label: __("Color swatches"), icon: SwatchBook },
    { value: "button", label: __("Buttons"), icon: RectangleHorizontal },
    { value: "heading", label: __("Heading"), icon: Heading },
];

export const typeOf = (type: FieldType) => FIELD_TYPES.find((t) => t.value === type) ?? FIELD_TYPES[0];

const isChoice = (type: FieldType) => CHOICE_TYPES.includes(type);

function blankField(id: number, type: FieldType): OptionField {
    return {
        id,
        type,
        label: "",
        help: "",
        required: false,
        placeholder: "",
        max_length: 0,
        // A slider needs both ends.
        min: type === "range" ? 0 : null,
        max: type === "range" ? 100 : null,
        step: type === "range" ? 1 : null,
        price_mode: "none",
        price: 0,
        choices: isChoice(type) ? [blankChoice(1, type), blankChoice(2, type)] : [],
        logic: null,
        formula: "",
    };
}

/** A swatch starts black; the other choice types keep no color. */
const START = "#000000";

function blankChoice(id: number, type: FieldType): Choice {
    return { id, label: "", price_mode: "none", price: 0, default: false, color: type === "swatch" ? START : "", formula: "", stock: null };
}

const nextId = (list: { id: number }[]) => list.reduce((max, item) => Math.max(max, item.id), 0) + 1;

export interface SetPreset {
    key: string;
    name: string;
    description: string;
    fields: () => OptionField[];
}

const unit = () => 10 ** getPluginGlobal().currency.exponent;

export const SET_PRESETS: SetPreset[] = [
    {
        key: "gift",
        name: __("Gift wrap"),
        description: __("A paid gift wrap box, and a message field that shows once it is ticked."),
        fields: () => [
            {
                ...blankField(1, "checkboxes"),
                label: __("Gift wrap"),
                choices: [{ ...blankChoice(1, "checkboxes"), label: __("Wrap it as a gift"), price_mode: "fixed", price: 5 * unit() }],
            },
            {
                ...blankField(2, "textarea"),
                label: __("Gift message"),
                max_length: 200,
                logic: { action: "show", match: "all", rules: [{ field: 1, op: "not_empty", value: "" }] },
            },
        ],
    },
    {
        key: "engraving",
        name: __("Engraving"),
        description: __("A short text to engrave, for a fixed extra charge."),
        fields: () => [{ ...blankField(1, "text"), label: __("Engraving text"), help: __("Up to 20 characters."), max_length: 20, price_mode: "fixed", price: 10 * unit() }],
    },
    {
        key: "size",
        name: __("Custom size"),
        description: __("Width and height typed by the customer, both required."),
        fields: () => [
            { ...blankField(1, "number"), label: __("Width (cm)"), required: true, min: 10, max: 300, step: 1 },
            { ...blankField(2, "number"), label: __("Height (cm)"), required: true, min: 10, max: 300, step: 1 },
        ],
    },
    {
        key: "area",
        name: __("Price by area"),
        description: __("Width and height in cm, charged per square metre with a formula."),
        fields: () => [
            { ...blankField(1, "number"), label: __("Width (cm)"), required: true, min: 10, max: 300, step: 1 },
            {
                ...blankField(2, "number"),
                label: __("Height (cm)"),
                required: true,
                min: 10,
                max: 300,
                step: 1,
                price_mode: "formula",
                formula: "round({1} * {2} / 10000 * 25, 2)",
            },
        ],
    },
    {
        key: "color",
        name: __("Color choice"),
        description: __("Three color swatches, the first picked at the start."),
        fields: () => [
            {
                ...blankField(1, "swatch"),
                label: __("Color"),
                required: true,
                choices: [
                    { ...blankChoice(1, "swatch"), label: __("Black"), color: "#000000", default: true },
                    { ...blankChoice(2, "swatch"), label: __("White"), color: "#ffffff" },
                    { ...blankChoice(3, "swatch"), label: __("Red"), color: "#c62828" },
                ],
            },
        ],
    },
    {
        key: "delivery",
        name: __("Delivery date"),
        description: __("A required date, from tomorrow up to 60 days ahead."),
        fields: () => [{ ...blankField(1, "date"), label: __("Delivery date"), required: true, min: 1, max: 60 }],
    },
    {
        key: "pickup",
        name: __("Pickup slot"),
        description: __("A day in the next two weeks and a half-hour slot from 9:00 to 17:00."),
        fields: () => [
            { ...blankField(1, "date"), label: __("Pickup day"), required: true, min: 0, max: 14 },
            { ...blankField(2, "time"), label: __("Pickup time"), required: true, min: 9 * 60, max: 17 * 60, step: 30 },
        ],
    },
    {
        key: "custom",
        name: __("Custom"),
        description: "",
        fields: () => [blankField(1, "text")],
    },
];

interface Draft {
    name: string;
    enabled: boolean;
    applies_to: SetScope;
    products: Target[];
    categories: Target[];
    exclude_products: Target[];
    fields: OptionField[];
}

const picks = (type: Target["type"], ids: number[]): Target[] => ids.map((id) => ({ type, id }));

const ids = (list: Target[]): number[] => list.map((p) => p.id);

function draftOf(set: OptionSet | null, preset: SetPreset | undefined): Draft {
    return {
        name: set?.name ?? (preset !== undefined && preset.key !== "custom" ? preset.name : ""),
        enabled: set?.enabled ?? true,
        applies_to: set?.applies_to ?? "all",
        products: picks("product", set?.product_ids ?? []),
        categories: picks("category", set?.category_ids ?? []),
        exclude_products: picks("product", set?.exclude_product_ids ?? []),
        fields:
            set?.fields.map((f) => ({ ...f, choices: f.choices.map((c) => ({ ...c, stock: c.stock ?? null, stock_was: c.stock ?? null })) })) ??
            (preset ?? SET_PRESETS[SET_PRESETS.length - 1]).fields(),
    };
}

function inputOf(draft: Draft): OptionSetInput {
    return {
        name: draft.name.trim(),
        enabled: draft.enabled,
        applies_to: draft.applies_to,
        product_ids: draft.applies_to === "products" ? ids(draft.products) : [],
        category_ids: draft.applies_to === "categories" ? ids(draft.categories) : [],
        exclude_product_ids: ids(draft.exclude_products),
        fields: draft.fields.map((f) => ({ ...f, label: f.label.trim(), choices: isChoice(f.type) ? f.choices : [] })),
    };
}

/** Only the keys that differ from the stored set. */
function changes(input: OptionSetInput, base: OptionSetInput | null): Partial<OptionSetInput> {
    if (base === null) {
        return input;
    }
    const out: Record<string, unknown> = {};
    for (const key of Object.keys(input) as (keyof OptionSetInput)[]) {
        if (JSON.stringify(input[key]) !== JSON.stringify(base[key])) {
            out[key] = input[key];
        }
    }
    return out as Partial<OptionSetInput>;
}

function Field({ label, help, error, children, className }: { label: string; help?: string; error?: string; className?: string; children: (id: string) => ReactNode }) {
    const id = useId();
    return (
        <div className={className}>
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

/** A percent typed as a decimal, reported in hundredths; unparseable text reports NaN. */
function PercentInput({ value, onChange, label }: { value: number; onChange: (v: number) => void; label: string }) {
    const [text, setText] = useState(() => (value > 0 ? String(value / 100) : ""));
    return (
        <div className="fxc:flex fxc:items-center fxc:gap-1">
            <Input
                aria-label={label}
                inputMode="decimal"
                className="fxc:w-20"
                value={text}
                onChange={(e) => {
                    setText(e.target.value);
                    const percent = parseDecimal(e.target.value);
                    onChange(percent === null ? 0 : Math.round(percent * 100));
                }}
            />
            <span className="fxc:text-muted-foreground fxc:text-sm">%</span>
        </div>
    );
}

/** No charge, a fixed amount per item or once per cart line, a percent of the unit price, or a formula (see FormulaInput). */
function PriceInputs({ mode, price, onChange, label }: { mode: PriceMode; price: number; onChange: (mode: PriceMode, price: number) => void; label: string }) {
    const { currency } = getPluginGlobal();
    return (
        <div className="fxc:flex fxc:items-center fxc:gap-2">
            <Select
                aria-label={sprintf(__("%s: extra charge"), label)}
                className="fxc:w-40"
                value={mode}
                onChange={(e) => onChange(e.target.value as PriceMode, 0)}
                options={[
                    { value: "none", label: __("No charge") },
                    { value: "fixed", label: __("Fixed amount") },
                    { value: "once", label: __("Once per order line") },
                    { value: "percent", label: __("Percent of price") },
                    { value: "formula", label: __("Formula") },
                ]}
            />
            {(mode === "fixed" || mode === "once") && (
                <div className="fxc:flex fxc:items-center fxc:gap-1">
                    <span className="fxc:text-muted-foreground fxc:text-sm">{currency.symbol}</span>
                    <MoneyInput
                        aria-label={sprintf(__("%s: amount"), label)}
                        className="fxc:w-24"
                        value={price}
                        exponent={currency.exponent}
                        onChange={(minor) => onChange(mode, minor)}
                    />
                </div>
            )}
            {mode === "once" && <span className="fxc:text-muted-foreground fxc:text-xs">{__("whatever the quantity")}</span>}
            {mode === "percent" && <PercentInput key={mode} label={sprintf(__("%s: percent"), label)} value={price} onChange={(v) => onChange(mode, v)} />}
        </div>
    );
}

/**
 * A formula price (ADR-039): what one item costs extra, worked out from the
 * fields of the set. The buttons put a field, base or qty at the cursor.
 */
function FormulaInput({ value, fields, label, onChange }: { value: string; fields: OptionField[]; label: string; onChange: (formula: string) => void }) {
    const { currency } = getPluginGlobal();
    const input = useRef<HTMLInputElement>(null);
    const hintId = useId();
    const insert = (text: string) => {
        const el = input.current;
        const start = el?.selectionStart ?? value.length;
        const end = el?.selectionEnd ?? value.length;
        onChange(value.slice(0, start) + text + value.slice(end));
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + text.length, start + text.length);
        });
    };
    const refs = fields.filter((f) => f.type !== "heading");
    return (
        <div className="fxc:space-y-2">
            <div className="fxc:flex fxc:items-center fxc:gap-2">
                <span className="fxc:text-muted-foreground fxc:text-sm">{currency.symbol}</span>
                <Input
                    ref={input}
                    aria-label={sprintf(__("%s: formula"), label)}
                    aria-describedby={hintId}
                    className="fxc:font-mono"
                    maxLength={300}
                    placeholder="{1} * {2} * 0.05"
                    spellCheck={false}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            </div>
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-1" role="group" aria-label={__("Insert into the formula")}>
                {refs.map((f) => (
                    <Button key={f.id} variant="outline" size="sm" className="fxc:h-7 fxc:px-2 fxc:text-xs" onClick={() => insert(`{${f.id}}`)}>
                        {f.label.trim() || __("Untitled field")} <code className="fxc:text-muted-foreground fxc:font-mono">{`{${f.id}}`}</code>
                    </Button>
                ))}
                <Button variant="outline" size="sm" className="fxc:h-7 fxc:px-2 fxc:text-xs" onClick={() => insert("base")}>
                    {__("Unit price")} <code className="fxc:text-muted-foreground fxc:font-mono">base</code>
                </Button>
                <Button variant="outline" size="sm" className="fxc:h-7 fxc:px-2 fxc:text-xs" onClick={() => insert("qty")}>
                    {__("Quantity")} <code className="fxc:text-muted-foreground fxc:font-mono">qty</code>
                </Button>
            </div>
            <p id={hintId} className="fxc:text-muted-foreground fxc:text-xs">
                {__(
                    "The extra charge for one item, in the store currency. A number field gives its number, a text field its characters without spaces, a choice field how many choices are picked; an empty or hidden field gives 0. Use + - * / and brackets, and round(x, 2), ceil(x), floor(x), min(a, b) or max(a, b). A result below 0 charges nothing.",
                )}
            </p>
        </div>
    );
}

function ChoicesEditor({ field, fields, onChange }: { field: OptionField; fields: OptionField[]; onChange: (choices: Choice[]) => void }) {
    const many = field.type === "checkboxes";
    const swatch = field.type === "swatch";
    const grid = swatch ? "fxc:grid-cols-[2.5rem_minmax(0,1fr)_auto_5rem_4.5rem_2.25rem]" : "fxc:grid-cols-[minmax(0,1fr)_auto_5rem_4.5rem_2.25rem]";
    const set = (id: number, patch: Partial<Choice>) =>
        onChange(field.choices.map((c) => (c.id === id ? { ...c, ...patch } : patch.default && !many ? { ...c, default: false } : c)));
    return (
        <div className="fxc:space-y-2">
            <div className={cn("fxc:text-muted-foreground fxc:grid fxc:gap-2 fxc:text-xs fxc:font-medium", grid)}>
                {swatch && <span>{__("Color")}</span>}
                <span>{__("Choice")}</span>
                <span>{__("Extra charge")}</span>
                <span title={__("Leave empty for no limit. Orders take from it and cancellations put it back.")}>{__("Stock")}</span>
                <span>{many ? __("Ticked") : __("Default")}</span>
            </div>
            {field.choices.map((choice, index) => (
                <div key={choice.id} className={cn("fxc:grid fxc:items-center fxc:gap-2", grid)}>
                    {swatch && (
                        <input
                            type="color"
                            aria-label={sprintf(__("Choice %d: color"), index + 1)}
                            className="fxc:h-9 fxc:w-10 fxc:cursor-pointer fxc:rounded-md fxc:border fxc:bg-transparent fxc:p-0.5"
                            value={choice.color || START}
                            onChange={(e) => set(choice.id, { color: e.target.value })}
                        />
                    )}
                    <Input
                        aria-label={sprintf(__("Choice %d: name"), index + 1)}
                        maxLength={60}
                        value={choice.label}
                        onChange={(e) => set(choice.id, { label: e.target.value })}
                    />
                    <PriceInputs
                        label={sprintf(__("Choice %d"), index + 1)}
                        mode={choice.price_mode}
                        price={choice.price}
                        onChange={(price_mode, price) => set(choice.id, { price_mode, price })}
                    />
                    <Input
                        type="number"
                        min={0}
                        step={1}
                        inputMode="numeric"
                        placeholder="∞"
                        aria-label={sprintf(__("Choice %d: stock, empty for no limit"), index + 1)}
                        value={choice.stock ?? ""}
                        onChange={(e) => set(choice.id, { stock: e.target.value === "" ? null : Math.max(0, Math.trunc(Number(e.target.value))) })}
                    />
                    <label className="fxc:flex fxc:justify-center">
                        <input
                            type={many ? "checkbox" : "radio"}
                            className="flexacommerce-check"
                            aria-label={sprintf(__("Choice %d: picked at the start"), index + 1)}
                            checked={choice.default}
                            onChange={(e) => set(choice.id, { default: e.target.checked })}
                            onClick={() => !many && choice.default && set(choice.id, { default: false })}
                        />
                    </label>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={sprintf(__("Remove choice %d"), index + 1)}
                        disabled={field.choices.length <= 1}
                        onClick={() => onChange(field.choices.filter((c) => c.id !== choice.id))}
                    >
                        <X aria-hidden className="fxc:size-4" />
                    </Button>
                    {choice.price_mode === "formula" && (
                        <div className="fxc:col-span-full fxc:mb-2">
                            <FormulaInput
                                label={sprintf(__("Choice %d"), index + 1)}
                                value={choice.formula}
                                fields={fields}
                                onChange={(formula) => set(choice.id, { formula })}
                            />
                        </div>
                    )}
                </div>
            ))}
            <Button variant="outline" size="sm" disabled={field.choices.length >= 30} onClick={() => onChange([...field.choices, blankChoice(nextId(field.choices), field.type)])}>
                <Plus aria-hidden className="fxc:size-4" />
                {__("Add choice")}
            </Button>
        </div>
    );
}

const OPS: { value: LogicOp; label: string; choice: boolean }[] = [
    { value: "is", label: __("is"), choice: true },
    { value: "is_not", label: __("is not"), choice: true },
    { value: "not_empty", label: __("is filled in"), choice: false },
    { value: "empty", label: __("is empty"), choice: false },
];

/** "Show this field when…": rules name the fields above it. */
function LogicEditor({ field, above, onChange }: { field: OptionField; above: OptionField[]; onChange: (logic: OptionField["logic"]) => void }) {
    const targets = above.filter((f) => f.type !== "heading");
    if (field.logic === null) {
        return targets.length === 0 ? (
            <p className="fxc:text-muted-foreground fxc:text-xs">{__("Fields further down can show or hide depending on this one.")}</p>
        ) : (
            <Button variant="outline" size="sm" onClick={() => onChange({ action: "show", match: "all", rules: [ruleFor(targets[targets.length - 1])] })}>
                <Plus aria-hidden className="fxc:size-4" />
                {__("Show only when…")}
            </Button>
        );
    }
    const logic = field.logic;
    const setRule = (index: number, rule: LogicRule) => onChange({ ...logic, rules: logic.rules.map((r, i) => (i === index ? rule : r)) });
    return (
        <div className="fxc:bg-muted/50 fxc:space-y-2 fxc:rounded-md fxc:p-3">
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2 fxc:text-sm">
                <Select
                    aria-label={__("Show or hide")}
                    className="fxc:w-28"
                    value={logic.action}
                    onChange={(e) => onChange({ ...logic, action: e.target.value as "show" | "hide" })}
                    options={[
                        { value: "show", label: __("Show") },
                        { value: "hide", label: __("Hide") },
                    ]}
                />
                <span>{__("this field when")}</span>
                <Select
                    aria-label={__("Which conditions")}
                    className="fxc:w-24"
                    value={logic.match}
                    onChange={(e) => onChange({ ...logic, match: e.target.value as "any" | "all" })}
                    options={[
                        { value: "all", label: __("all") },
                        { value: "any", label: __("any") },
                    ]}
                />
                <span>{__("of these are true:")}</span>
            </div>
            {logic.rules.map((rule, index) => {
                const target = targets.find((t) => t.id === rule.field);
                const ops = OPS.filter((op) => !op.choice || (target !== undefined && isChoice(target.type)));
                return (
                    <div key={index} className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                        <Select
                            aria-label={sprintf(__("Condition %d: field"), index + 1)}
                            className="fxc:w-48"
                            value={String(rule.field)}
                            onChange={(e) => {
                                const next = targets.find((t) => t.id === Number(e.target.value));
                                if (next) {
                                    setRule(index, ruleFor(next));
                                }
                            }}
                            options={[
                                ...(target === undefined ? [{ value: String(rule.field), label: __("(field moved or removed)") }] : []),
                                ...targets.map((t) => ({ value: String(t.id), label: t.label.trim() || typeOf(t.type).label })),
                            ]}
                        />
                        <Select
                            aria-label={sprintf(__("Condition %d: test"), index + 1)}
                            className="fxc:w-32"
                            value={rule.op}
                            onChange={(e) => {
                                const op = e.target.value as LogicOp;
                                const choice = OPS.find((o) => o.value === op)?.choice;
                                setRule(index, { ...rule, op, value: choice ? rule.value || String(target?.choices[0]?.id ?? "") : "" });
                            }}
                            options={ops.map(({ value, label }) => ({ value, label }))}
                        />
                        {(rule.op === "is" || rule.op === "is_not") && target !== undefined && (
                            <Select
                                aria-label={sprintf(__("Condition %d: choice"), index + 1)}
                                className="fxc:w-40"
                                value={rule.value}
                                onChange={(e) => setRule(index, { ...rule, value: e.target.value })}
                                options={target.choices.map((c) => ({ value: String(c.id), label: c.label.trim() || sprintf(__("Choice %d"), c.id) }))}
                            />
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={sprintf(__("Remove condition %d"), index + 1)}
                            onClick={() => {
                                const rules = logic.rules.filter((_, i) => i !== index);
                                onChange(rules.length === 0 ? null : { ...logic, rules });
                            }}
                        >
                            <X aria-hidden className="fxc:size-4" />
                        </Button>
                    </div>
                );
            })}
            <Button variant="ghost" size="sm" disabled={logic.rules.length >= 10} onClick={() => onChange({ ...logic, rules: [...logic.rules, ruleFor(targets[targets.length - 1])] })}>
                <Plus aria-hidden className="fxc:size-4" />
                {__("Add condition")}
            </Button>
        </div>
    );
}

function ruleFor(target: OptionField): LogicRule {
    return isChoice(target.type) && target.choices.length > 0
        ? { field: target.id, op: "is", value: String(target.choices[0].id) }
        : { field: target.id, op: "not_empty", value: "" };
}

const numberText = (value: number | null) => (value === null ? "" : String(value));

/** Whole days from 0; empty text means no limit. */
const days = (text: string): number | null => (text.trim() === "" ? null : Math.max(0, Math.floor(Number(text) || 0)));

/** What `min`, `max` and `step` hold for a type: numbers, days from today or minutes from midnight. */
const limitsOf = (type: FieldType) => (type === "number" || type === "range" ? "number" : type === "date" || type === "datetime" ? "days" : type === "time" ? "time" : "none");

/** Types that keep a starting value in `placeholder`, or none at all. */
const startsOf = (type: FieldType) => (type === "color" || type === "range" ? type : type === "time" || type === "datetime" ? "none" : "text");

/** Minutes from midnight as the HH:MM of a time input. */
const clock = (minutes: number | null) => (minutes === null ? "" : `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`);

const minutesOf = (text: string): number | null => {
    const m = /^(\d{2}):(\d{2})/.exec(text);
    return m ? Number(m[1]) * 60 + Number(m[2]) : null;
};

const textNumber = (text: string): number | null => {
    const n = parseDecimal(text);
    return n === null ? null : n;
};

interface FieldCardProps {
    field: OptionField;
    index: number;
    count: number;
    above: OptionField[];
    /** Every field of the set, for formula prices. */
    fields: OptionField[];
    open: boolean;
    error?: string;
    onToggle: () => void;
    onChange: (patch: Partial<OptionField>) => void;
    onMove: (delta: number) => void;
    onRemove: () => void;
}

function FieldCard({ field, index, count, above, fields, open, error, onToggle, onChange, onMove, onRemove }: FieldCardProps) {
    const { icon: Icon, label: typeLabel } = typeOf(field.type);
    const heading = field.type === "heading";
    const typed = ["text", "textarea", "number", "range", "date", "time", "datetime", "color"].includes(field.type);
    const color = field.type === "color";
    const range = field.type === "range";
    const title = field.label.trim() || __("Untitled field");
    return (
        <li className={cn("fxc:rounded-md fxc:border", error && "fxc:border-destructive")}>
            <div className="fxc:flex fxc:items-center fxc:gap-3 fxc:px-3 fxc:py-2">
                <button
                    type="button"
                    aria-expanded={open}
                    className="fxc:flex fxc:min-w-0 fxc:flex-1 fxc:items-center fxc:gap-3 fxc:rounded fxc:text-start fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
                    onClick={onToggle}
                >
                    <span className="fxc:bg-muted fxc:text-muted-foreground fxc:flex fxc:size-8 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-md">
                        <Icon aria-hidden className="fxc:size-4" />
                    </span>
                    <span className="fxc:min-w-0">
                        <span className="fxc:block fxc:truncate fxc:text-sm fxc:font-medium">
                            {title}
                            {field.required && <span className="fxc:text-destructive"> *</span>}
                        </span>
                        <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">
                            {typeLabel}
                            {field.logic !== null && ` · ${__("shows on a condition")}`}
                        </span>
                    </span>
                </button>
                <Button variant="ghost" size="icon" aria-label={sprintf(__("Move %s up"), title)} disabled={index === 0} onClick={() => onMove(-1)}>
                    <ArrowUp aria-hidden className="fxc:size-4" />
                </Button>
                <Button variant="ghost" size="icon" aria-label={sprintf(__("Move %s down"), title)} disabled={index === count - 1} onClick={() => onMove(1)}>
                    <ArrowDown aria-hidden className="fxc:size-4" />
                </Button>
                <Button variant="ghost" size="icon" aria-label={sprintf(__("Remove %s"), title)} disabled={count <= 1} onClick={onRemove}>
                    <Trash2 aria-hidden className="fxc:size-4" />
                </Button>
            </div>
            {error && <p className="fxc:text-destructive fxc:px-3 fxc:pb-2 fxc:text-xs">{error}</p>}
            {open && (
                <div className="fxc:space-y-4 fxc:border-t fxc:px-3 fxc:py-4">
                    <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-[12rem_minmax(0,1fr)]">
                        <Field label={__("Type")}>
                            {(id) => (
                                <Select
                                    id={id}
                                    value={field.type}
                                    onChange={(e) => {
                                        const type = e.target.value as FieldType;
                                        const choices = isChoice(type) && field.choices.length === 0 ? [blankChoice(1, type), blankChoice(2, type)] : field.choices;
                                        // Limits and a starting value mean different things per type.
                                        const limits = limitsOf(type) !== limitsOf(field.type);
                                        const start = startsOf(type) !== startsOf(field.type);
                                        // A slider needs both ends, so it starts with the usual ones.
                                        const ends = type === "range" ? { min: field.min ?? 0, max: field.max ?? 100, step: field.step ?? 1 } : {};
                                        onChange({
                                            type,
                                            choices: type === "swatch" ? choices.map((c) => ({ ...c, color: c.color || START })) : choices,
                                            required: type === "heading" || type === "color" || type === "range" ? false : field.required,
                                            ...(limits ? { min: null, max: null, step: null } : {}),
                                            ...ends,
                                            ...(start ? { placeholder: "" } : {}),
                                        });
                                    }}
                                    options={FIELD_TYPES.map(({ value, label }) => ({ value, label }))}
                                />
                            )}
                        </Field>
                        <Field label={heading ? __("Heading text") : __("Label")} help={heading ? undefined : __("Shown above the field, and with the item in the cart and the order.")}>
                            {(id) => <Input id={id} maxLength={80} value={field.label} onChange={(e) => onChange({ label: e.target.value })} />}
                        </Field>
                    </div>
                    <Field label={__("Help text")} help={__("Optional, shown under the field.")}>
                        {(id) => <Input id={id} maxLength={200} value={field.help} onChange={(e) => onChange({ help: e.target.value })} />}
                    </Field>
                    {!heading && !color && !range && (
                        <label className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                            <input type="checkbox" className="flexacommerce-check" checked={field.required} onChange={(e) => onChange({ required: e.target.checked })} />
                            {__("Required: the product cannot go into the cart without it")}
                        </label>
                    )}

                    {(field.type === "text" || field.type === "textarea") && (
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                            <Field label={__("Placeholder")}>
                                {(id) => <Input id={id} maxLength={80} value={field.placeholder} onChange={(e) => onChange({ placeholder: e.target.value })} />}
                            </Field>
                            <Field label={__("Character limit")} help={__("Empty: no limit of your own.")}>
                                {(id) => (
                                    <Input
                                        id={id}
                                        type="number"
                                        min={1}
                                        max={1000}
                                        value={field.max_length > 0 ? String(field.max_length) : ""}
                                        onChange={(e) => onChange({ max_length: Math.max(0, Math.floor(Number(e.target.value) || 0)) })}
                                    />
                                )}
                            </Field>
                        </div>
                    )}

                    {field.type === "number" && (
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-3">
                            <Field label={__("Smallest")}>
                                {(id) => <Input id={id} inputMode="decimal" value={numberText(field.min)} onChange={(e) => onChange({ min: textNumber(e.target.value) })} />}
                            </Field>
                            <Field label={__("Largest")}>
                                {(id) => <Input id={id} inputMode="decimal" value={numberText(field.max)} onChange={(e) => onChange({ max: textNumber(e.target.value) })} />}
                            </Field>
                            <Field label={__("Step")} help={__("1 for whole numbers.")}>
                                {(id) => <Input id={id} inputMode="decimal" value={numberText(field.step)} onChange={(e) => onChange({ step: textNumber(e.target.value) })} />}
                            </Field>
                        </div>
                    )}

                    {range && (
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-4">
                            <Field label={__("Smallest")}>
                                {(id) => <Input id={id} inputMode="decimal" value={numberText(field.min)} onChange={(e) => onChange({ min: textNumber(e.target.value) })} />}
                            </Field>
                            <Field label={__("Largest")}>
                                {(id) => <Input id={id} inputMode="decimal" value={numberText(field.max)} onChange={(e) => onChange({ max: textNumber(e.target.value) })} />}
                            </Field>
                            <Field label={__("Step")}>
                                {(id) => <Input id={id} inputMode="decimal" value={numberText(field.step)} onChange={(e) => onChange({ step: textNumber(e.target.value) })} />}
                            </Field>
                            <Field label={__("Starts at")} help={__("Empty: the smallest.")}>
                                {(id) => <Input id={id} inputMode="decimal" value={field.placeholder} onChange={(e) => onChange({ placeholder: e.target.value.trim() })} />}
                            </Field>
                            <p className="fxc:text-muted-foreground fxc:text-xs fxc:sm:col-span-4">{__("A slider always sends a number, so it cannot be required and its charge always applies.")}</p>
                        </div>
                    )}

                    {field.type === "time" && (
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-3">
                            <Field label={__("Earliest")} help={__("Empty: any time.")}>
                                {(id) => <Input id={id} type="time" value={clock(field.min)} onChange={(e) => onChange({ min: minutesOf(e.target.value) })} />}
                            </Field>
                            <Field label={__("Latest")} help={__("Empty: no limit.")}>
                                {(id) => <Input id={id} type="time" value={clock(field.max)} onChange={(e) => onChange({ max: minutesOf(e.target.value) })} />}
                            </Field>
                            <Field label={__("Step, in minutes")} help={__("15 offers :00, :15, :30 and :45.")}>
                                {(id) => <Input id={id} type="number" min={1} max={720} value={numberText(field.step)} onChange={(e) => onChange({ step: days(e.target.value) || null })} />}
                            </Field>
                        </div>
                    )}

                    {(field.type === "date" || field.type === "datetime") && (
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                            <Field label={__("Earliest, in days from today")} help={__("0 is today. Empty: any date.")}>
                                {(id) => <Input id={id} type="number" min={0} max={3650} value={numberText(field.min)} onChange={(e) => onChange({ min: days(e.target.value) })} />}
                            </Field>
                            <Field label={__("Latest, in days from today")} help={__("Empty: no limit.")}>
                                {(id) => <Input id={id} type="number" min={0} max={3650} value={numberText(field.max)} onChange={(e) => onChange({ max: days(e.target.value) })} />}
                            </Field>
                        </div>
                    )}

                    {color && (
                        <Field label={__("Starting color")} help={__("What the picker shows before the customer changes it. The field always sends a color.")}>
                            {(id) => (
                                <input
                                    id={id}
                                    type="color"
                                    className="fxc:h-9 fxc:w-14 fxc:cursor-pointer fxc:rounded-md fxc:border fxc:bg-transparent fxc:p-0.5"
                                    value={field.placeholder || START}
                                    onChange={(e) => onChange({ placeholder: e.target.value })}
                                />
                            )}
                        </Field>
                    )}

                    {typed && (
                        <div>
                            <p className="fxc:mb-2 fxc:text-sm fxc:font-medium">{color || range ? __("Extra charge") : __("Extra charge when filled in")}</p>
                            <PriceInputs label={title} mode={field.price_mode} price={field.price} onChange={(price_mode, price) => onChange({ price_mode, price })} />
                            {field.price_mode === "formula" && (
                                <div className="fxc:mt-3">
                                    <FormulaInput label={title} value={field.formula} fields={fields} onChange={(formula) => onChange({ formula })} />
                                </div>
                            )}
                        </div>
                    )}

                    {isChoice(field.type) && <ChoicesEditor field={field} fields={fields} onChange={(choices) => onChange({ choices })} />}

                    <div className="fxc:border-t fxc:pt-4">
                        <p className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Conditions")}</p>
                        <LogicEditor field={field} above={above} onChange={(logic) => onChange({ logic })} />
                    </div>
                </div>
            )}
        </li>
    );
}

interface Props {
    open: boolean;
    set: OptionSet | null;
    preset?: SetPreset;
    onOpenChange: (open: boolean) => void;
}

export function OptionSetDialog({ open, set: stored, preset, onOpenChange }: Props) {
    const [draft, setDraft] = useState<Draft>(() => draftOf(stored, preset));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [fieldError, setFieldError] = useState<{ index: number; message: string } | null>(null);
    const [expanded, setExpanded] = useState<number | null>(null);
    const [chosen, setChosen] = useState<string>(preset?.key ?? "custom");
    const [adding, setAdding] = useState<FieldType>("text");
    const save = useSaveOptionSet();

    useEffect(() => {
        if (open) {
            const next = draftOf(stored, preset);
            setDraft(next);
            setChosen(preset?.key ?? "custom");
            setErrors({});
            setFieldError(null);
            setExpanded(stored === null ? (next.fields[0]?.id ?? null) : null);
        }
    }, [open, stored, preset]);

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));

    const setFields = (fields: OptionField[]) => {
        set("fields", fields);
        setFieldError(null);
    };

    const applyPreset = (p: SetPreset) => {
        setChosen(p.key);
        setDraft((d) => {
            const next = draftOf(null, p);
            const typed = d.name.trim() !== "" && !SET_PRESETS.some((q) => q.name === d.name);
            setExpanded(next.fields[0]?.id ?? null);
            return { ...next, name: typed ? d.name : next.name, applies_to: d.applies_to, products: d.products, categories: d.categories };
        });
        setErrors({});
        setFieldError(null);
    };

    const patchField = (id: number, patch: Partial<OptionField>) => setFields(draft.fields.map((f) => (f.id === id ? { ...f, ...patch } : f)));

    const moveField = (index: number, delta: number) => {
        const fields = [...draft.fields];
        const [field] = fields.splice(index, 1);
        fields.splice(index + delta, 0, field);
        setFields(fields);
    };

    /** Conditions that named the removed field go with it. */
    const removeField = (id: number) =>
        setFields(
            draft.fields
                .filter((f) => f.id !== id)
                .map((f) => {
                    if (f.logic === null) {
                        return f;
                    }
                    const rules = f.logic.rules.filter((r) => r.field !== id);
                    return { ...f, logic: rules.length === 0 ? null : { ...f.logic, rules } };
                }),
        );

    const addField = () => {
        const id = nextId(draft.fields);
        setFields([...draft.fields, blankField(id, adding)]);
        setExpanded(id);
    };

    const submit = () => {
        const body = changes(inputOf(draft), stored !== null ? inputOf(draftOf(stored, undefined)) : null);
        if (stored !== null && Object.keys(body).length === 0) {
            onOpenChange(false);
            return;
        }
        save.mutate(
            { id: stored?.id ?? 0, input: body },
            {
                onSuccess: () => {
                    showToast.success(stored !== null ? __("Option set saved.") : __("Option set added."));
                    onOpenChange(false);
                },
                onError: (error) => {
                    const data = error instanceof ApiError ? error.data : {};
                    const field = typeof data.field === "string" ? data.field : "";
                    const index = typeof data.index === "number" ? data.index : -1;
                    if (field === "fields" && index >= 0) {
                        setFieldError({ index, message: error.message });
                        setExpanded(draft.fields[index]?.id ?? null);
                    } else if (field !== "" && field !== "id") {
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
            <DialogContent className="fxc:max-h-[90vh] fxc:max-w-3xl fxc:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{stored !== null ? __("Edit option set") : __("New option set")}</DialogTitle>
                    <DialogDescription>{__("Fields the customer fills in on the product page, above the Add to cart button. Extra charges are added to the unit price.")}</DialogDescription>
                </DialogHeader>

                <form
                    id="fxc-option-set-form"
                    className="fxc:space-y-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    {stored === null && (
                        <fieldset>
                            <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Start from")}</legend>
                            <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                                {SET_PRESETS.map((p) => (
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

                    <Field label={__("Name")} help={__("Only you see it, in this list.")} error={errors.name}>
                        {(id) => <Input id={id} className="fxc:sm:w-96" maxLength={60} value={draft.name} onChange={(e) => set("name", e.target.value)} />}
                    </Field>

                    <section className="fxc:space-y-3 fxc:border-t fxc:pt-5">
                        <h3 className="fxc:text-sm fxc:font-semibold">{__("Fields")}</h3>
                        {errors.fields && <p className="fxc:text-destructive fxc:text-xs">{errors.fields}</p>}
                        <ul className="fxc:space-y-2">
                            {draft.fields.map((field, index) => (
                                <FieldCard
                                    key={field.id}
                                    field={field}
                                    index={index}
                                    count={draft.fields.length}
                                    above={draft.fields.slice(0, index)}
                                    fields={draft.fields}
                                    open={expanded === field.id}
                                    error={fieldError?.index === index ? fieldError.message : undefined}
                                    onToggle={() => setExpanded(expanded === field.id ? null : field.id)}
                                    onChange={(patch) => patchField(field.id, patch)}
                                    onMove={(delta) => moveField(index, delta)}
                                    onRemove={() => removeField(field.id)}
                                />
                            ))}
                        </ul>
                        <div className="fxc:flex fxc:items-center fxc:gap-2">
                            <Select
                                aria-label={__("Type of the new field")}
                                className="fxc:w-44"
                                value={adding}
                                onChange={(e) => setAdding(e.target.value as FieldType)}
                                options={FIELD_TYPES.map(({ value, label }) => ({ value, label }))}
                            />
                            <Button variant="outline" disabled={draft.fields.length >= 20} onClick={addField}>
                                <Plus aria-hidden className="fxc:size-4" />
                                {__("Add field")}
                            </Button>
                        </div>
                    </section>

                    <section className="fxc:space-y-4 fxc:border-t fxc:pt-5">
                        <h3 className="fxc:text-sm fxc:font-semibold">{__("Products")}</h3>
                        <Field label={__("Show on")}>
                            {(id) => (
                                <Select
                                    id={id}
                                    className="fxc:sm:w-64"
                                    value={draft.applies_to}
                                    onChange={(e) => set("applies_to", e.target.value as SetScope)}
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
                                    <TargetPicker id={id} kind="category" value={draft.categories} onChange={(v) => set("categories", v)} placeholder={__("Search categories")} />
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
                    </section>
                </form>

                <DialogFooter>
                    <Button variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>
                        {__("Cancel")}
                    </Button>
                    <Button type="submit" form="fxc-option-set-form" disabled={busy}>
                        {busy ? __("Saving…") : stored !== null ? __("Save set") : __("Add set")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
