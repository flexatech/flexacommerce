import { useId } from "react";
import { X } from "lucide-react";

import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import { TargetPicker } from "../coupons/TargetPicker";
import { ALWAYS, MAX_TARGETS, type CartShipping, type Section, type When } from "./useCheckoutLayout";

interface Props {
    when: When | undefined;
    section: Section;
    countries: { code: string; name: string }[];
    error?: string;
    onChange: (when: When) => void;
}

const FOLLOWS: Record<Section, string> = {
    billing: __("Follows the billing country."),
    shipping: __("Follows the shipping country."),
    additional: __("Follows the country the order ships to."),
};

/** When one of the merchant's fields shows (doc 11 §11). Every part left empty means always. */
export function FieldConditions({ when = ALWAYS, section, countries, error, onChange }: Props) {
    const id = useId();
    const set = (patch: Partial<When>) => onChange({ ...when, ...patch });
    const name = (code: string) => countries.find((c) => c.code === code)?.name ?? code;

    return (
        <fieldset className="fxc:space-y-4 fxc:border-t fxc:pt-4">
            <legend className="fxc:mb-1 fxc:text-sm fxc:font-medium">{__("Show this field")}</legend>
            <p className="fxc:text-muted-foreground fxc:text-xs">{__("Leave these empty to show the field on every checkout.")}</p>

            <div className="fxc:space-y-2">
                <Label htmlFor={`${id}-country`} className="fxc:block">
                    {__("Only for these countries")}
                </Label>
                {when.countries.length > 0 && (
                    <ul className="fxc:flex fxc:flex-wrap fxc:gap-1.5">
                        {when.countries.map((code) => (
                            <li key={code} className="fxc:bg-muted fxc:flex fxc:items-center fxc:gap-1 fxc:rounded-full fxc:py-0.5 fxc:pr-1 fxc:pl-2.5 fxc:text-xs">
                                {name(code)}
                                <button
                                    type="button"
                                    aria-label={sprintf(__("Remove %s"), name(code))}
                                    className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:cursor-pointer fxc:rounded-full fxc:bg-transparent fxc:p-0.5"
                                    onClick={() => set({ countries: when.countries.filter((c) => c !== code) })}
                                >
                                    <X aria-hidden className="fxc:size-3" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <select
                    id={`${id}-country`}
                    className="flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:h-9 fxc:w-full fxc:rounded-md fxc:border fxc:px-3 fxc:text-sm"
                    value=""
                    disabled={when.countries.length >= MAX_TARGETS}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={`${id}-country-hint`}
                    onChange={(e) => e.target.value && set({ countries: [...when.countries, e.target.value] })}
                >
                    <option value="">{__("Add a country…")}</option>
                    {countries.map((c) => (
                        <option key={c.code} value={c.code} disabled={when.countries.includes(c.code)}>
                            {c.name}
                        </option>
                    ))}
                </select>
                <p id={`${id}-country-hint`} className={error ? "fxc:text-destructive fxc:text-xs" : "fxc:text-muted-foreground fxc:text-xs"}>
                    {error ?? (when.countries.length > 0 ? FOLLOWS[section] : __("Every country."))}
                </p>
            </div>

            <div>
                <Label htmlFor={`${id}-shipping`} className="fxc:mb-1.5 fxc:block">
                    {__("Cart")}
                </Label>
                <Select
                    id={`${id}-shipping`}
                    className="fxc:w-full"
                    value={when.shipping}
                    onChange={(e) => set({ shipping: e.target.value as CartShipping })}
                    options={[
                        { value: "any", label: __("Any cart") },
                        { value: "ships", label: __("Only when the order ships") },
                        { value: "no_shipping", label: __("Only when nothing ships") },
                    ]}
                />
            </div>

            <div className="fxc:space-y-3">
                <div>
                    <Label htmlFor={`${id}-products`} className="fxc:mb-1.5 fxc:block">
                        {__("Products in the cart")}
                    </Label>
                    <TargetPicker
                        id={`${id}-products`}
                        kind="product"
                        variants={false}
                        value={when.products.map((p) => ({ type: "product", id: p }))}
                        onChange={(picks) => set({ products: picks.map((p) => p.id).slice(0, MAX_TARGETS) })}
                        placeholder={__("Search products…")}
                        describedBy={`${id}-targets-hint`}
                    />
                </div>
                <div>
                    <Label htmlFor={`${id}-categories`} className="fxc:mb-1.5 fxc:block">
                        {__("Categories in the cart")}
                    </Label>
                    <TargetPicker
                        id={`${id}-categories`}
                        kind="category"
                        value={when.categories.map((c) => ({ type: "category", id: c }))}
                        onChange={(picks) => set({ categories: picks.map((p) => p.id).slice(0, MAX_TARGETS) })}
                        placeholder={__("Search categories…")}
                        describedBy={`${id}-targets-hint`}
                    />
                </div>
                <p id={`${id}-targets-hint`} className="fxc:text-muted-foreground fxc:text-xs">
                    {__("Shown when the cart holds one of these products, or a product in one of these categories. Subcategories count too.")}
                </p>
            </div>
        </fieldset>
    );
}
