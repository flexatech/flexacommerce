import { useState } from "react";
import { Globe, Plus, Trash2, X } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import type { Country } from "../shipping/useShipping";
import type { TaxMarket } from "./useTaxes";

/** The overrides a market can set (Support\TaxSettings::MARKET_OVERRIDES), as select choices. */
const CHOICES: { key: string; label: string; options: { value: string; label: string }[] }[] = [
    {
        key: "tax_display_shop",
        label: __("Prices in the shop"),
        options: [
            { value: "excl", label: __("Excluding tax") },
            { value: "incl", label: __("Including tax") },
        ],
    },
    {
        key: "tax_display_cart",
        label: __("Prices in the cart and checkout"),
        options: [
            { value: "excl", label: __("Excluding tax") },
            { value: "incl", label: __("Including tax") },
        ],
    },
    {
        key: "tax_total_display",
        label: __("Tax totals"),
        options: [
            { value: "itemized", label: __("One line per tax") },
            { value: "single", label: __("A single total") },
        ],
    },
    {
        key: "tax_round_at_subtotal",
        label: __("Round tax at subtotal"),
        options: [
            { value: "1", label: __("Yes") },
            { value: "0", label: __("No") },
        ],
    },
    {
        key: "tax_based_on",
        label: __("Calculate tax based on"),
        options: [
            { value: "shipping", label: __("Customer shipping address") },
            { value: "billing", label: __("Customer billing address") },
            { value: "base", label: __("Store address") },
        ],
    },
    {
        key: "intrastate_sourcing",
        label: __("Sales inside the store's state"),
        options: [
            { value: "destination", label: __("Tax at the customer address") },
            { value: "origin", label: __("Tax at the store address (origin states)") },
        ],
    },
];

const INHERIT = "__inherit";

function toChoice(value: unknown): string {
    if (value === undefined || value === null) {
        return INHERIT;
    }
    return typeof value === "boolean" ? (value ? "1" : "0") : String(value);
}

function fromChoice(key: string, value: string): unknown {
    return key === "tax_round_at_subtotal" ? value === "1" : value;
}

function marketId(name: string, taken: string[]): string {
    const base =
        name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, "_")
            .replace(/^_+|_+$/g, "")
            .slice(0, 24) || "market";
    let id = base;
    for (let n = 2; taken.includes(id); n++) {
        id = `${base}_${n}`;
    }
    return id;
}

/**
 * Markets change how tax works for a group of countries without a second
 * copy of the rate table (doc 02 §7.12.6). The first market that lists the
 * customer's country wins; an override left on "Store setting" keeps the
 * global option.
 */
export function MarketsCard({ markets, countries, onChange }: { markets: TaxMarket[]; countries: Country[]; onChange: (markets: TaxMarket[]) => void }) {
    const [name, setName] = useState("");

    const update = (index: number, patch: Partial<TaxMarket>) => onChange(markets.map((m, i) => (i === index ? { ...m, ...patch } : m)));
    const add = () => {
        const id = marketId(name, markets.map((m) => m.id));
        onChange([...markets, { id, name: name.trim(), countries: [], overrides: {} }]);
        setName("");
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Markets")}</CardTitle>
                <CardDescription>{__("Optional. Use a market when a group of countries needs different tax display or sourcing than the store default.")}</CardDescription>
            </CardHeader>
            {markets.map((market, index) => (
                <MarketRow
                    key={market.id}
                    market={market}
                    countries={countries}
                    onChange={(patch) => update(index, patch)}
                    onRemove={() => onChange(markets.filter((_, i) => i !== index))}
                />
            ))}
            <form
                className="fxc:flex fxc:items-center fxc:gap-2 fxc:px-5 fxc:py-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (name.trim() !== "") {
                        add();
                    }
                }}
            >
                <Input
                    aria-label={__("New market name")}
                    placeholder={__("New market name, for example European Union")}
                    className="fxc:max-w-xs"
                    maxLength={100}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
                <Button type="submit" variant="outline" disabled={name.trim() === ""}>
                    <Plus aria-hidden />
                    {__("Add market")}
                </Button>
            </form>
        </Card>
    );
}

function MarketRow({
    market,
    countries,
    onChange,
    onRemove,
}: {
    market: TaxMarket;
    countries: Country[];
    onChange: (patch: Partial<TaxMarket>) => void;
    onRemove: () => void;
}) {
    const id = `fxc-market-${market.id}`;
    const setOverride = (key: string, value: unknown) => {
        const next = { ...market.overrides };
        if (value === undefined) {
            delete next[key];
        } else {
            next[key] = value;
        }
        onChange({ overrides: next });
    };
    const origin = Array.isArray(market.overrides.origin_states) ? (market.overrides.origin_states as string[]) : [];

    return (
        <div className="fxc:space-y-4 fxc:border-b fxc:px-5 fxc:py-4">
            <div className="fxc:flex fxc:items-center fxc:gap-3">
                <Globe aria-hidden className="fxc:text-primary fxc:size-4" />
                <Input aria-label={__("Market name")} className="fxc:max-w-xs" value={market.name} onChange={(e) => onChange({ name: e.target.value })} />
                <code className="fxc:text-muted-foreground fxc:text-xs">{market.id}</code>
                <Button variant="ghost" size="icon" className="fxc:ml-auto" aria-label={sprintf(__("Remove %s"), market.name)} onClick={onRemove}>
                    <Trash2 aria-hidden />
                </Button>
            </div>

            <div>
                <Label htmlFor={`${id}-country`} className="fxc:mb-2 fxc:block">
                    {__("Countries")}
                </Label>
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    {market.countries.map((code) => (
                        <Badge key={code} className="fxc:gap-1">
                            {countries.find((c) => c.code === code)?.name ?? code}
                            <button
                                type="button"
                                className="fxc:cursor-pointer fxc:bg-transparent"
                                aria-label={sprintf(__("Remove %s"), code)}
                                onClick={() => onChange({ countries: market.countries.filter((c) => c !== code) })}
                            >
                                <X aria-hidden className="fxc:size-3" />
                            </button>
                        </Badge>
                    ))}
                    <Select
                        id={`${id}-country`}
                        value=""
                        onChange={(e) => e.target.value !== "" && onChange({ countries: [...market.countries, e.target.value] })}
                        options={[
                            { value: "", label: __("Add a country…") },
                            ...countries.filter((c) => !market.countries.includes(c.code)).map((c) => ({ value: c.code, label: c.name })),
                        ]}
                    />
                </div>
            </div>

            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                {CHOICES.map((choice) => (
                    <div key={choice.key}>
                        <Label htmlFor={`${id}-${choice.key}`} className="fxc:mb-2 fxc:block">
                            {choice.label}
                        </Label>
                        <Select
                            id={`${id}-${choice.key}`}
                            className="fxc:w-full"
                            value={toChoice(market.overrides[choice.key])}
                            onChange={(e) => setOverride(choice.key, e.target.value === INHERIT ? undefined : fromChoice(choice.key, e.target.value))}
                            options={[{ value: INHERIT, label: __("Store setting") }, ...choice.options]}
                        />
                    </div>
                ))}
                <div>
                    <Label htmlFor={`${id}-suffix`} className="fxc:mb-2 fxc:block">
                        {__("Price suffix")}
                    </Label>
                    <Input
                        id={`${id}-suffix`}
                        placeholder={__("Store setting")}
                        value={typeof market.overrides.price_display_suffix === "string" ? market.overrides.price_display_suffix : ""}
                        onChange={(e) => setOverride("price_display_suffix", e.target.value === "" ? undefined : e.target.value)}
                    />
                </div>
                {market.overrides.intrastate_sourcing === "origin" && (
                    <div>
                        <Label htmlFor={`${id}-origin`} className="fxc:mb-2 fxc:block">
                            {__("Origin states")}
                        </Label>
                        <ListInput id={`${id}-origin`} placeholder="TX, PA" value={origin} onChange={(states) => setOverride("origin_states", states)} />
                        <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                            {__("States where a sale inside the state is taxed at the store address. Other states use the customer address.")}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

/** Comma-separated codes; the text is kept as typed so a trailing comma does not vanish. */
function ListInput({ id, placeholder, value, onChange }: { id: string; placeholder: string; value: string[]; onChange: (list: string[]) => void }) {
    const [text, setText] = useState(value.join(", "));
    return (
        <Input
            id={id}
            placeholder={placeholder}
            value={text}
            onChange={(e) => {
                setText(e.target.value);
                onChange(
                    e.target.value
                        .split(",")
                        .map((s) => s.trim().toUpperCase())
                        .filter((s) => s !== ""),
                );
            }}
        />
    );
}
