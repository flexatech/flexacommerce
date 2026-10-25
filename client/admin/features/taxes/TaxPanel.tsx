import { useEffect, useMemo, useState } from "react";
import { Calculator, Eye, Layers, MapPin, Percent, Receipt, Sigma, Truck } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { __ } from "@/shared/lib/i18n";
import { SettingRow } from "../../components/SettingRow";
import { useSaveSettings, useSettings, type Settings } from "../settings/useSettings";
import { ClassesCard } from "./ClassesCard";
import { MarketsCard } from "./MarketsCard";
import { RatesCard } from "./RatesCard";
import { useRefreshTaxes, useTaxOverview } from "./useTaxes";

const TAX_KEYS = [
    "tax_enabled",
    "prices_include_tax",
    "tax_based_on",
    "tax_default_location",
    "shipping_tax_class",
    "tax_round_at_subtotal",
    "tax_classes",
    "tax_display_shop",
    "tax_display_cart",
    "price_display_suffix",
    "tax_total_display",
    "tax_markets",
] as const;

type TaxKey = (typeof TAX_KEYS)[number];
export type TaxDraft = Pick<Settings, TaxKey>;

function pick(settings: Settings): TaxDraft {
    return Object.fromEntries(TAX_KEYS.map((k) => [k, settings[k]])) as TaxDraft;
}

function changed(draft: TaxDraft, saved: TaxDraft): Partial<TaxDraft> {
    const out: Partial<Record<TaxKey, unknown>> = {};
    for (const key of TAX_KEYS) {
        if (JSON.stringify(draft[key]) !== JSON.stringify(saved[key])) {
            out[key] = draft[key];
        }
    }
    return out as Partial<TaxDraft>;
}

export function TaxPanel() {
    const settings = useSettings();
    const overview = useTaxOverview();
    const save = useSaveSettings();
    const refresh = useRefreshTaxes();
    const [draft, setDraft] = useState<TaxDraft | null>(null);

    const saved = useMemo(() => (settings.data ? pick(settings.data.settings) : null), [settings.data]);
    useEffect(() => {
        if (saved) {
            setDraft(saved);
        }
    }, [saved]);

    const changes = useMemo(() => (draft && saved ? changed(draft, saved) : {}), [draft, saved]);
    const dirty = Object.keys(changes).length > 0;

    if (settings.isError || overview.isError) {
        return <p className="fxc:text-destructive">{(settings.error ?? overview.error)?.message}</p>;
    }
    if (!draft || !saved || !overview.data) {
        return <p className="fxc:text-muted-foreground">{__("Loading tax settings…")}</p>;
    }

    const set = <K extends TaxKey>(key: K, value: TaxDraft[K]) => setDraft({ ...draft, [key]: value });

    const removed = saved.tax_classes.filter((c) => !draft.tax_classes.some((d) => d.slug === c.slug));
    const submit = () => {
        if (removed.length > 0 && !window.confirm(__("The rates of the removed tax classes will be deleted. Continue?"))) {
            return;
        }
        save.mutate(changes, {
            onSuccess: () => {
                showToast.success(__("Tax settings saved."));
                void refresh();
            },
            onError: (error) => showToast.error(error.message),
        });
    };

    const classOptions = [{ value: "", label: __("Standard") }, ...draft.tax_classes.map((c) => ({ value: c.slug, label: c.name }))];
    const disabled = !draft.tax_enabled;

    return (
        <div className="fxc:space-y-6">
            <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3">
                {dirty && <span className="fxc:text-muted-foreground fxc:text-xs">{__("Unsaved changes")}</span>}
                {dirty && (
                    <Button variant="ghost" onClick={() => setDraft(saved)} disabled={save.isPending}>
                        {__("Discard")}
                    </Button>
                )}
                <Button onClick={submit} disabled={!dirty || save.isPending}>
                    {save.isPending ? __("Saving…") : __("Save tax settings")}
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Tax options")}</CardTitle>
                    <CardDescription>{__("The rates below apply only while taxes are on. Orders keep the tax they were placed with.")}</CardDescription>
                </CardHeader>
                <SettingRow
                    icon={Calculator}
                    label={__("Calculate taxes")}
                    description={__("Adds tax to carts and orders from the rate table.")}
                    control={<Switch aria-label={__("Calculate taxes")} checked={draft.tax_enabled} onCheckedChange={(v) => set("tax_enabled", v)} />}
                />
                <SettingRow
                    icon={Receipt}
                    label={__("Prices entered with tax")}
                    description={__("On: product prices you type already include tax. Off: tax is added on top.")}
                    control={
                        <Switch
                            aria-label={__("Prices entered with tax")}
                            disabled={disabled}
                            checked={draft.prices_include_tax}
                            onCheckedChange={(v) => set("prices_include_tax", v)}
                        />
                    }
                />
                <SettingRow
                    icon={MapPin}
                    label={__("Calculate tax based on")}
                    htmlFor="fxc-tax-based-on"
                    control={
                        <Select
                            id="fxc-tax-based-on"
                            disabled={disabled}
                            value={draft.tax_based_on}
                            onChange={(e) => set("tax_based_on", e.target.value as TaxDraft["tax_based_on"])}
                            options={[
                                { value: "shipping", label: __("Customer shipping address") },
                                { value: "billing", label: __("Customer billing address") },
                                { value: "base", label: __("Store address") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={MapPin}
                    label={__("Before the address is known")}
                    htmlFor="fxc-tax-default"
                    description={__("What the cart shows until the shopper enters an address.")}
                    control={
                        <Select
                            id="fxc-tax-default"
                            disabled={disabled}
                            value={draft.tax_default_location}
                            onChange={(e) => set("tax_default_location", e.target.value as TaxDraft["tax_default_location"])}
                            options={[
                                { value: "base", label: __("Use the store address") },
                                { value: "none", label: __("No tax") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={Truck}
                    label={__("Shipping tax class")}
                    htmlFor="fxc-shipping-tax-class"
                    description={__("Based on cart items uses the class of the items in the cart.")}
                    control={
                        <Select
                            id="fxc-shipping-tax-class"
                            disabled={disabled}
                            value={draft.shipping_tax_class}
                            onChange={(e) => set("shipping_tax_class", e.target.value)}
                            options={[{ value: "inherit", label: __("Based on cart items") }, ...classOptions]}
                        />
                    }
                />
                <SettingRow
                    icon={Sigma}
                    label={__("Round tax at subtotal")}
                    description={__("Rounds once per order instead of once per line.")}
                    control={
                        <Switch
                            aria-label={__("Round tax at subtotal")}
                            disabled={disabled}
                            checked={draft.tax_round_at_subtotal}
                            onCheckedChange={(v) => set("tax_round_at_subtotal", v)}
                        />
                    }
                />
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Display")}</CardTitle>
                </CardHeader>
                <SettingRow
                    icon={Eye}
                    label={__("Prices in the shop")}
                    htmlFor="fxc-tax-shop"
                    control={
                        <Select
                            id="fxc-tax-shop"
                            disabled={disabled}
                            value={draft.tax_display_shop}
                            onChange={(e) => set("tax_display_shop", e.target.value as TaxDraft["tax_display_shop"])}
                            options={[
                                { value: "excl", label: __("Excluding tax") },
                                { value: "incl", label: __("Including tax") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={Eye}
                    label={__("Prices in the cart and checkout")}
                    htmlFor="fxc-tax-cart"
                    control={
                        <Select
                            id="fxc-tax-cart"
                            disabled={disabled}
                            value={draft.tax_display_cart}
                            onChange={(e) => set("tax_display_cart", e.target.value as TaxDraft["tax_display_cart"])}
                            options={[
                                { value: "excl", label: __("Excluding tax") },
                                { value: "incl", label: __("Including tax") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={Percent}
                    label={__("Price suffix")}
                    htmlFor="fxc-tax-suffix"
                    description={__("Text after shop prices, for example “incl. VAT”.")}
                    control={
                        <Input
                            id="fxc-tax-suffix"
                            className="fxc:w-48"
                            disabled={disabled}
                            value={draft.price_display_suffix}
                            onChange={(e) => set("price_display_suffix", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={Layers}
                    label={__("Tax totals")}
                    htmlFor="fxc-tax-totals"
                    control={
                        <Select
                            id="fxc-tax-totals"
                            disabled={disabled}
                            value={draft.tax_total_display}
                            onChange={(e) => set("tax_total_display", e.target.value as TaxDraft["tax_total_display"])}
                            options={[
                                { value: "itemized", label: __("One line per tax") },
                                { value: "single", label: __("A single total") },
                            ]}
                        />
                    }
                />
            </Card>

            <ClassesCard classes={draft.tax_classes} counts={overview.data.classes} onChange={(tax_classes) => set("tax_classes", tax_classes)} />

            <MarketsCard markets={draft.tax_markets} countries={overview.data.countries} onChange={(tax_markets) => set("tax_markets", tax_markets)} />

            <RatesCard overview={overview.data} />
        </div>
    );
}
