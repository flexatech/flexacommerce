import { useEffect, useId, useState, type ReactNode } from "react";
import { Image } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { ApiError } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import { TargetPicker, type Pick as Choice } from "../coupons/TargetPicker";
import { BadgeChip } from "./BadgeChip";
import {
    useBadgeRoles,
    useSaveBadge,
    type BadgeCondition,
    type BadgeCustomers,
    type BadgeInput,
    type BadgePlaces,
    type BadgePosition,
    type BadgeScope,
    type BadgeShape,
    type ProductBadge,
} from "./useBadges";

export interface Preset {
    key: string;
    name: string;
    values: Pick<BadgeInput, "label" | "bg_color" | "text_color" | "condition">;
}

export const PRESETS: Preset[] = [
    { key: "sale", name: __("Sale"), values: { label: "-{percent}%", bg_color: "#d63638", text_color: "#ffffff", condition: "on_sale" } },
    { key: "new", name: __("New"), values: { label: __("New"), bg_color: "#2271b1", text_color: "#ffffff", condition: "new" } },
    { key: "featured", name: __("Featured"), values: { label: __("Featured"), bg_color: "#dba617", text_color: "#1e1e1e", condition: "featured" } },
    { key: "low", name: __("Low stock"), values: { label: __("Only a few left"), bg_color: "#e65054", text_color: "#ffffff", condition: "low_stock" } },
    { key: "out", name: __("Sold out"), values: { label: __("Sold out"), bg_color: "#50575e", text_color: "#ffffff", condition: "out_of_stock" } },
    { key: "custom", name: __("Custom"), values: { label: "", bg_color: "#1e1e1e", text_color: "#ffffff", condition: "always" } },
];

interface Draft {
    label: string;
    bg_color: string;
    text_color: string;
    position: BadgePosition;
    shape: BadgeShape;
    enabled: boolean;
    condition: BadgeCondition;
    new_days: string;
    applies_to: BadgeScope;
    products: Choice[];
    categories: Choice[];
    exclude_products: Choice[];
    places: BadgePlaces;
    starts_at: string;
    ends_at: string;
    customers: BadgeCustomers;
    roles: string[];
}

const HEX = /^#[0-9a-f]{6}$/i;

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

function draftOf(badge: ProductBadge | null, preset: Preset | undefined): Draft {
    const start = preset ?? PRESETS[0];
    return {
        label: badge?.label ?? start.values.label,
        bg_color: badge?.bg_color ?? start.values.bg_color,
        text_color: badge?.text_color ?? start.values.text_color,
        position: badge?.position ?? "top-left",
        shape: badge?.shape ?? "rounded",
        enabled: badge?.enabled ?? true,
        condition: badge?.condition ?? start.values.condition,
        new_days: String(badge?.new_days ?? 30),
        applies_to: badge?.applies_to ?? "all",
        products: picks("product", badge?.product_ids ?? []),
        categories: picks("category", badge?.category_ids ?? []),
        exclude_products: picks("product", badge?.exclude_product_ids ?? []),
        places: badge?.places ?? "all",
        starts_at: toLocalInput(badge?.starts_at ?? null),
        ends_at: toLocalInput(badge?.ends_at ?? null),
        customers: badge?.customers ?? "all",
        roles: badge?.roles ?? [],
    };
}

function inputOf(draft: Draft): BadgeInput {
    return {
        label: draft.label.trim(),
        bg_color: draft.bg_color,
        text_color: draft.text_color,
        position: draft.position,
        shape: draft.shape,
        enabled: draft.enabled,
        condition: draft.condition,
        new_days: Number(draft.new_days) || 0,
        applies_to: draft.applies_to,
        product_ids: draft.applies_to === "products" ? ids(draft.products) : [],
        category_ids: draft.applies_to === "categories" ? ids(draft.categories) : [],
        exclude_product_ids: ids(draft.exclude_products),
        places: draft.places,
        starts_at: toUtc(draft.starts_at),
        ends_at: toUtc(draft.ends_at),
        customers: draft.customers,
        roles: draft.customers === "roles" ? draft.roles : [],
    };
}

/** Only the fields that differ from the stored badge. */
function changes(input: BadgeInput, base: BadgeInput | null): Partial<BadgeInput> {
    if (base === null) {
        return input;
    }
    const out: Record<string, unknown> = {};
    for (const key of Object.keys(input) as (keyof BadgeInput)[]) {
        if (JSON.stringify(input[key]) !== JSON.stringify(base[key])) {
            out[key] = input[key];
        }
    }
    return out as Partial<BadgeInput>;
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

function ColorField({ label, value, onChange, error }: { label: string; value: string; onChange: (v: string) => void; error?: string }) {
    return (
        <Field label={label} error={error}>
            {(id) => (
                <div className="fxc:flex fxc:items-center fxc:gap-2">
                    <input
                        type="color"
                        aria-label={label}
                        className="fxc:size-9 fxc:shrink-0 fxc:cursor-pointer fxc:rounded fxc:border-0 fxc:bg-transparent fxc:p-0"
                        value={HEX.test(value) ? value.toLowerCase() : "#000000"}
                        onChange={(e) => onChange(e.target.value)}
                    />
                    <Input id={id} className="fxc:w-28 fxc:font-mono" maxLength={7} value={value} onChange={(e) => onChange(e.target.value)} />
                </div>
            )}
        </Field>
    );
}

const CORNERS: { value: BadgePosition; label: string }[] = [
    { value: "top-left", label: __("Top left") },
    { value: "top-right", label: __("Top right") },
    { value: "bottom-left", label: __("Bottom left") },
    { value: "bottom-right", label: __("Bottom right") },
];

/** Four buttons laid out like the corners of an image. */
function CornerPicker({ value, onChange }: { value: BadgePosition; onChange: (v: BadgePosition) => void }) {
    const label = useId();
    return (
        <div>
            <span id={label} className="fxc:mb-2 fxc:block fxc:text-sm fxc:font-medium">
                {__("Corner")}
            </span>
            <div role="radiogroup" aria-labelledby={label} className="fxc:bg-muted fxc:grid fxc:size-20 fxc:grid-cols-2 fxc:gap-1 fxc:rounded-md fxc:p-1">
                {CORNERS.map((corner) => (
                    <button
                        key={corner.value}
                        type="button"
                        role="radio"
                        aria-checked={value === corner.value}
                        aria-label={corner.label}
                        title={corner.label}
                        className={cn(
                            "fxc:rounded fxc:border fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                            value === corner.value ? "fxc:border-foreground fxc:bg-foreground" : "fxc:bg-background fxc:hover:border-foreground/50",
                        )}
                        onClick={() => onChange(corner.value)}
                    />
                ))}
            </div>
        </div>
    );
}

const CORNER_CLASS: Record<BadgePosition, string> = {
    "top-left": "fxc:top-2 fxc:left-2",
    "top-right": "fxc:top-2 fxc:right-2",
    "bottom-left": "fxc:bottom-2 fxc:left-2",
    "bottom-right": "fxc:bottom-2 fxc:right-2",
};

/** A product card with the badge in its corner. */
function Preview({ draft }: { draft: Draft }) {
    return (
        <div aria-hidden className="fxc:space-y-2">
            <p className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium fxc:uppercase">{__("Preview")}</p>
            <div className="fxc:bg-muted fxc:relative fxc:flex fxc:aspect-square fxc:items-center fxc:justify-center fxc:rounded-md">
                <Image className="fxc:text-muted-foreground/40 fxc:size-12" />
                <BadgeChip badge={draft} className={cn("fxc:absolute", CORNER_CLASS[draft.position])} />
            </div>
            <div className="fxc:bg-muted fxc:h-3 fxc:w-3/4 fxc:rounded" />
            <div className="fxc:bg-muted fxc:h-3 fxc:w-1/3 fxc:rounded" />
            {draft.label.includes("{percent}") && (
                <p className="fxc:text-muted-foreground fxc:text-xs">{__("Shown here with a 20 % discount.")}</p>
            )}
        </div>
    );
}

interface Props {
    open: boolean;
    badge: ProductBadge | null;
    preset?: Preset;
    onOpenChange: (open: boolean) => void;
}

export function BadgeDialog({ open, badge, preset, onOpenChange }: Props) {
    const [draft, setDraft] = useState<Draft>(() => draftOf(badge, preset));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const roles = useBadgeRoles();
    const [chosen, setChosen] = useState<string>(preset?.key ?? PRESETS[0].key);
    const save = useSaveBadge();

    useEffect(() => {
        if (open) {
            setDraft(draftOf(badge, preset));
            setChosen(preset?.key ?? PRESETS[0].key);
            setErrors({});
        }
    }, [open, badge, preset]);

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));

    const applyPreset = (p: Preset) => {
        setChosen(p.key);
        setDraft((d) => ({ ...d, ...p.values }));
        setErrors({});
    };

    const submit = () => {
        const body = changes(inputOf(draft), badge !== null ? inputOf(draftOf(badge, undefined)) : null);
        if (badge !== null && Object.keys(body).length === 0) {
            onOpenChange(false);
            return;
        }
        save.mutate(
            { id: badge?.id ?? 0, input: body },
            {
                onSuccess: () => {
                    showToast.success(badge !== null ? __("Badge saved.") : __("Badge added."));
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
            <DialogContent className="fxc:max-h-[90vh] fxc:max-w-3xl fxc:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{badge !== null ? __("Edit badge") : __("New badge")}</DialogTitle>
                    <DialogDescription>{__("Set how the badge looks, which products get it, and where it shows. Dates use your browser's time zone.")}</DialogDescription>
                </DialogHeader>

                <form
                    id="fxc-badge-form"
                    className="fxc:grid fxc:gap-6 fxc:md:grid-cols-[minmax(0,1fr)_12rem]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div className="fxc:space-y-6">
                        {badge === null && (
                            <fieldset>
                                <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Start from")}</legend>
                                <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                                    {PRESETS.map((p) => (
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
                            <h3 className="fxc:text-sm fxc:font-semibold">{__("Look")}</h3>
                            <Field
                                label={__("Text")}
                                help={__("Up to 40 characters. {percent} becomes the sale discount, for example -{percent}% shows as -20%.")}
                                error={errors.label}
                            >
                                {(id) => <Input id={id} maxLength={40} value={draft.label} onChange={(e) => set("label", e.target.value)} />}
                            </Field>
                            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                                <ColorField label={__("Background")} value={draft.bg_color} onChange={(v) => set("bg_color", v)} error={errors.bg_color} />
                                <ColorField label={__("Text colour")} value={draft.text_color} onChange={(v) => set("text_color", v)} error={errors.text_color} />
                            </div>
                            <div className="fxc:flex fxc:flex-wrap fxc:items-start fxc:gap-6">
                                <div className="fxc:w-48">
                                    <Field label={__("Shape")}>
                                        {(id) => (
                                            <Select
                                                id={id}
                                                value={draft.shape}
                                                onChange={(e) => set("shape", e.target.value as BadgeShape)}
                                                options={[
                                                    { value: "rounded", label: __("Rounded") },
                                                    { value: "pill", label: __("Pill") },
                                                    { value: "square", label: __("Square") },
                                                    { value: "circle", label: __("Circle") },
                                                ]}
                                            />
                                        )}
                                    </Field>
                                </div>
                                <CornerPicker value={draft.position} onChange={(v) => set("position", v)} />
                            </div>
                        </section>

                        <section className="fxc:space-y-4 fxc:border-t fxc:pt-5">
                            <h3 className="fxc:text-sm fxc:font-semibold">{__("Products")}</h3>
                            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                                <Field label={__("Show when")} help={draft.condition === "low_stock" ? __("Uses the low stock threshold of the product, or the store default.") : undefined}>
                                    {(id) => (
                                        <Select
                                            id={id}
                                            value={draft.condition}
                                            onChange={(e) => set("condition", e.target.value as BadgeCondition)}
                                            options={[
                                                { value: "always", label: __("Always") },
                                                { value: "on_sale", label: __("The product is on sale") },
                                                { value: "new", label: __("The product is new") },
                                                { value: "featured", label: __("The product is featured") },
                                                { value: "low_stock", label: __("Stock is low") },
                                                { value: "out_of_stock", label: __("Out of stock") },
                                            ]}
                                        />
                                    )}
                                </Field>
                                {draft.condition === "new" && (
                                    <Field label={__("New for (days)")} help={__("Counted from the publish date.")} error={errors.new_days}>
                                        {(id) => (
                                            <Input id={id} type="number" min={1} max={365} className="fxc:w-28" value={draft.new_days} onChange={(e) => set("new_days", e.target.value)} />
                                        )}
                                    </Field>
                                )}
                            </div>
                            <Field label={__("Applies to")}>
                                {(id) => (
                                    <Select
                                        id={id}
                                        className="fxc:sm:w-64"
                                        value={draft.applies_to}
                                        onChange={(e) => set("applies_to", e.target.value as BadgeScope)}
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
                        </section>

                        <section className="fxc:space-y-4 fxc:border-t fxc:pt-5">
                            <h3 className="fxc:text-sm fxc:font-semibold">{__("Where, who and when")}</h3>
                            <Field label={__("Show on")}>
                                {(id) => (
                                    <Select
                                        id={id}
                                        className="fxc:sm:w-64"
                                        value={draft.places}
                                        onChange={(e) => set("places", e.target.value as BadgePlaces)}
                                        options={[
                                            { value: "all", label: __("Product page and lists") },
                                            { value: "single", label: __("Product page only") },
                                            { value: "loop", label: __("Product lists only") },
                                        ]}
                                    />
                                )}
                            </Field>
                            <Field
                                label={__("Customers")}
                                help={
                                    draft.customers === "all"
                                        ? undefined
                                        : draft.customers === "guests"
                                          ? __("Hidden once a customer logs in. Use it to invite guests to sign up.")
                                          : __("Guests never see it. Page caches keep logged-in pages apart from guest pages by default, so the badge stays with the right shoppers.")
                                }
                                error={errors.customers}
                            >
                                {(id) => (
                                    <Select
                                        id={id}
                                        className="fxc:sm:w-64"
                                        value={draft.customers}
                                        onChange={(e) => set("customers", e.target.value as BadgeCustomers)}
                                        options={[
                                            { value: "all", label: __("Everyone") },
                                            { value: "logged_in", label: __("Logged-in customers") },
                                            { value: "guests", label: __("Guests only") },
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
                                <Field label={__("Ends")} help={__("Empty: never.")} error={errors.ends_at}>
                                    {(id) => <Input id={id} type="datetime-local" value={draft.ends_at} onChange={(e) => set("ends_at", e.target.value)} />}
                                </Field>
                            </div>
                        </section>
                    </div>

                    <div className="fxc:md:sticky fxc:md:top-0 fxc:self-start">
                        <Preview draft={draft} />
                    </div>
                </form>

                <DialogFooter>
                    <Button variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>
                        {__("Cancel")}
                    </Button>
                    <Button type="submit" form="fxc-badge-form" disabled={busy}>
                        {busy ? __("Saving…") : badge !== null ? __("Save badge") : __("Add badge")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
