import { useEffect, useMemo, useState } from "react";
import { ArrowDown, ArrowUp, ChevronDown, Globe, MapPin, Plus, Trash2, X } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Switch } from "@/shared/components/ui/switch";
import { Textarea } from "@/shared/components/ui/textarea";
import { ApiError } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { FieldRow, type FieldValues } from "../../components/FieldControl";
import { useSaveZones, useShipping, type Country, type MethodInstance, type MethodType, type Zone } from "./useShipping";

/** A zone in the editor; `key` is local so new zones (id 0) have a stable identity. */
interface DraftZone extends Zone {
    key: string;
}

let nextKey = 0;
const withKey = (zone: Zone): DraftZone => ({ ...zone, key: `z${++nextKey}` });

function strip(zones: DraftZone[]): Zone[] {
    return zones.map(({ key: _key, ...zone }) => zone);
}

function defaults(type: MethodType): FieldValues {
    return Object.fromEntries(type.fields.map((f) => [f.id, f.default]));
}

export function ShippingPanel() {
    const query = useShipping();
    const save = useSaveZones();
    const [draft, setDraft] = useState<DraftZone[] | null>(null);
    const [invalid, setInvalid] = useState<{ index: number; message: string } | null>(null);

    const saved = query.data?.zones;
    useEffect(() => {
        if (saved) {
            setDraft(saved.map(withKey));
        }
    }, [saved]);

    const dirty = useMemo(() => draft !== null && saved !== undefined && JSON.stringify(strip(draft)) !== JSON.stringify(saved), [draft, saved]);

    if (query.isPending || draft === null) {
        return <p className="fxc:text-muted-foreground">{__("Loading shipping zones…")}</p>;
    }
    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }

    const { methods, countries } = query.data;
    // The server always returns "everywhere else" last.
    const zones = draft.slice(0, -1);
    const rest = draft[draft.length - 1];

    const update = (key: string, patch: Partial<Zone>) => setDraft(draft.map((z) => (z.key === key ? { ...z, ...patch } : z)));
    const move = (index: number, by: number) => {
        const next = [...zones];
        const [zone] = next.splice(index, 1);
        next.splice(index + by, 0, zone);
        setDraft([...next, rest]);
    };
    const remove = (key: string) => setDraft(draft.filter((z) => z.key !== key));
    const add = () => setDraft([...zones, withKey({ id: 0, name: "", regions: [], postcodes: [], methods: [] }), rest]);

    const submit = () => {
        setInvalid(null);
        save.mutate(strip(draft), {
            onSuccess: () => showToast.success(__("Shipping zones saved.")),
            onError: (e) => {
                const field = e instanceof ApiError && typeof e.data.field === "string" ? e.data.field : "";
                const match = /^zones\.(\d+)\./.exec(field);
                if (match) {
                    setInvalid({ index: Number(match[1]), message: e.message });
                }
                showToast.error(e.message);
            },
        });
    };

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:items-start fxc:justify-between fxc:gap-4">
                <p className="fxc:text-muted-foreground fxc:text-sm">
                    {__("An address uses the first zone that matches it, from the top. Addresses no zone matches use “Everywhere else”.")}
                </p>
                <div className="fxc:flex fxc:shrink-0 fxc:items-center fxc:gap-2">
                    {dirty && (
                        <Button variant="ghost" onClick={() => saved && setDraft(saved.map(withKey))} disabled={save.isPending}>
                            {__("Discard")}
                        </Button>
                    )}
                    <Button onClick={submit} disabled={!dirty || save.isPending}>
                        {save.isPending ? __("Saving…") : __("Save zones")}
                    </Button>
                </div>
            </div>

            {zones.map((zone, index) => (
                <ZoneCard
                    key={zone.key}
                    zone={zone}
                    types={methods}
                    countries={countries}
                    error={invalid?.index === index ? invalid.message : ""}
                    onChange={(patch) => update(zone.key, patch)}
                    onRemove={() => remove(zone.key)}
                    onUp={index > 0 ? () => move(index, -1) : undefined}
                    onDown={index < zones.length - 1 ? () => move(index, 1) : undefined}
                />
            ))}

            <Button variant="outline" onClick={add}>
                <Plus aria-hidden />
                {__("Add zone")}
            </Button>

            <ZoneCard
                zone={rest}
                rest
                types={methods}
                countries={countries}
                error={invalid?.index === draft.length - 1 ? invalid.message : ""}
                onChange={(patch) => update(rest.key, patch)}
            />
        </div>
    );
}

interface ZoneCardProps {
    zone: DraftZone;
    rest?: boolean;
    types: MethodType[];
    countries: Country[];
    error: string;
    onChange: (patch: Partial<Zone>) => void;
    onRemove?: () => void;
    onUp?: () => void;
    onDown?: () => void;
}

function regionLabel(region: string, countries: Country[]): string {
    const [kind, country, state] = region.split(":");
    const match = countries.find((c) => c.code === country);
    if (kind === "state") {
        const stateName = match?.states.find((s) => s.code === state)?.name ?? state;
        return `${stateName}, ${match?.name ?? country}`;
    }
    return match?.name ?? country;
}

function ZoneCard({ zone, rest = false, types, countries, error, onChange, onRemove, onUp, onDown }: ZoneCardProps) {
    const id = `fxc-zone-${zone.key}`;
    const [addType, setAddType] = useState("");

    const addRegion = (region: string) => {
        if (region !== "" && !zone.regions.includes(region)) {
            onChange({ regions: [...zone.regions, region] });
        }
    };

    const addMethod = (type: string) => {
        const def = types.find((t) => t.type === type);
        if (def) {
            onChange({ methods: [...zone.methods, { instance_id: 0, type, enabled: true, settings: defaults(def) }] });
        }
        setAddType("");
    };

    const setMethod = (index: number, patch: Partial<MethodInstance>) =>
        onChange({ methods: zone.methods.map((m, i) => (i === index ? { ...m, ...patch } : m)) });

    return (
        <Card className={cn(error !== "" && "fxc:border-destructive")}>
            <div className="fxc:flex fxc:items-center fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-3">
                <div className="fxc:bg-muted fxc:text-primary fxc:flex fxc:h-9 fxc:w-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-lg">
                    {rest ? <Globe aria-hidden className="fxc:size-4" /> : <MapPin aria-hidden className="fxc:size-4" />}
                </div>
                {rest ? (
                    <div className="fxc:flex-1">
                        <div className="fxc:text-sm fxc:font-semibold">{__("Everywhere else")}</div>
                        <div className="fxc:text-muted-foreground fxc:text-xs">{__("Addresses that match no zone above.")}</div>
                    </div>
                ) : (
                    <Input
                        aria-label={__("Zone name")}
                        placeholder={__("Zone name")}
                        className="fxc:max-w-xs fxc:flex-1"
                        value={zone.name}
                        onChange={(e) => onChange({ name: e.target.value })}
                    />
                )}
                {!rest && (
                    <div className="fxc:ml-auto fxc:flex fxc:items-center fxc:gap-1">
                        <Button variant="ghost" size="icon" aria-label={__("Move up")} disabled={!onUp} onClick={onUp}>
                            <ArrowUp aria-hidden />
                        </Button>
                        <Button variant="ghost" size="icon" aria-label={__("Move down")} disabled={!onDown} onClick={onDown}>
                            <ArrowDown aria-hidden />
                        </Button>
                        <Button variant="ghost" size="icon" aria-label={__("Delete zone")} onClick={onRemove}>
                            <Trash2 aria-hidden />
                        </Button>
                    </div>
                )}
            </div>

            {error !== "" && (
                <p role="alert" className="fxc:text-destructive fxc:border-b fxc:px-5 fxc:py-2 fxc:text-sm">
                    {error}
                </p>
            )}

            {!rest && (
                <div className="fxc:grid fxc:gap-4 fxc:border-b fxc:px-5 fxc:py-4 fxc:md:grid-cols-2">
                    <div className="fxc:space-y-2">
                        <Label htmlFor={`${id}-region`} className="fxc:block">
                            {__("Regions")}
                        </Label>
                        {zone.regions.length > 0 && (
                            <ul className="fxc:flex fxc:flex-wrap fxc:gap-1.5">
                                {zone.regions.map((region) => (
                                    <li key={region} className="fxc:bg-muted fxc:flex fxc:items-center fxc:gap-1 fxc:rounded-full fxc:py-0.5 fxc:pr-1 fxc:pl-2.5 fxc:text-xs">
                                        {regionLabel(region, countries)}
                                        <button
                                            type="button"
                                            aria-label={sprintf(__("Remove %s"), regionLabel(region, countries))}
                                            className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:cursor-pointer fxc:rounded-full fxc:bg-transparent fxc:p-0.5"
                                            onClick={() => onChange({ regions: zone.regions.filter((r) => r !== region) })}
                                        >
                                            <X aria-hidden className="fxc:size-3" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <select
                            id={`${id}-region`}
                            className="flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:h-9 fxc:w-full fxc:rounded-md fxc:border fxc:px-3 fxc:text-sm"
                            value=""
                            onChange={(e) => addRegion(e.target.value)}
                        >
                            <option value="">{__("Add a country or state…")}</option>
                            <optgroup label={__("Countries")}>
                                {countries.map((c) => (
                                    <option key={c.code} value={`country:${c.code}`} disabled={zone.regions.includes(`country:${c.code}`)}>
                                        {c.name}
                                    </option>
                                ))}
                            </optgroup>
                            {countries
                                .filter((c) => c.states.length > 0)
                                .map((c) => (
                                    <optgroup key={c.code} label={c.name}>
                                        {c.states.map((s) => (
                                            <option
                                                key={s.code}
                                                value={`state:${c.code}:${s.code}`}
                                                disabled={zone.regions.includes(`state:${c.code}:${s.code}`)}
                                            >
                                                {s.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                        </select>
                    </div>
                    <div className="fxc:space-y-2">
                        <Label htmlFor={`${id}-postcodes`} className="fxc:block">
                            {__("Postcodes (optional)")}
                        </Label>
                        <Textarea
                            id={`${id}-postcodes`}
                            value={zone.postcodes.join("\n")}
                            onChange={(e) => onChange({ postcodes: e.target.value.split("\n") })}
                            onBlur={() => onChange({ postcodes: zone.postcodes.map((p) => p.trim()).filter(Boolean) })}
                        />
                        <p className="fxc:text-muted-foreground fxc:text-xs">
                            {__("One per line. Use * as a wildcard (90*) and ... for a range (10000...19999).")}
                        </p>
                    </div>
                </div>
            )}

            <div className="fxc:px-5 fxc:py-4">
                <div className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Shipping methods")}</div>
                {zone.methods.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:mb-3 fxc:text-sm">
                        {rest
                            ? __("No methods: addresses outside your zones cannot check out.")
                            : __("No methods yet. Shoppers in this zone cannot check out until you add one.")}
                    </p>
                ) : (
                    <ul className="fxc:mb-3 fxc:rounded-lg fxc:border">
                        {zone.methods.map((method, index) => (
                            <MethodRow
                                key={method.instance_id > 0 ? method.instance_id : `new-${index}`}
                                method={method}
                                type={types.find((t) => t.type === method.type)}
                                idPrefix={`${id}-m${index}`}
                                onChange={(patch) => setMethod(index, patch)}
                                onRemove={() => onChange({ methods: zone.methods.filter((_, i) => i !== index) })}
                            />
                        ))}
                    </ul>
                )}
                <select
                    aria-label={__("Add a shipping method")}
                    className="flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:h-9 fxc:rounded-md fxc:border fxc:px-3 fxc:text-sm"
                    value={addType}
                    onChange={(e) => addMethod(e.target.value)}
                >
                    <option value="">{__("Add a method…")}</option>
                    {types.map((t) => (
                        <option key={t.type} value={t.type}>
                            {t.title}
                        </option>
                    ))}
                </select>
            </div>
        </Card>
    );
}

interface MethodRowProps {
    method: MethodInstance;
    type: MethodType | undefined;
    idPrefix: string;
    onChange: (patch: Partial<MethodInstance>) => void;
    onRemove: () => void;
}

function MethodRow({ method, type, idPrefix, onChange, onRemove }: MethodRowProps) {
    const [open, setOpen] = useState(method.instance_id === 0);
    const title = typeof method.settings.title === "string" && method.settings.title !== "" ? method.settings.title : (type?.title ?? method.type);

    return (
        <li className="fxc:border-b fxc:last:border-b-0">
            <div className="fxc:flex fxc:items-center fxc:gap-3 fxc:px-3 fxc:py-2">
                <Switch aria-label={sprintf(__("Enable %s"), title)} checked={method.enabled} onCheckedChange={(enabled) => onChange({ enabled })} />
                <div className="fxc:min-w-0 fxc:flex-1">
                    <div className="fxc:text-sm fxc:font-medium">{title}</div>
                    <div className="fxc:text-muted-foreground fxc:text-xs">{type?.title ?? method.type}</div>
                </div>
                {method.missing && <Badge variant="destructive">{__("Plugin inactive")}</Badge>}
                {type && type.fields.length > 0 && (
                    <Button variant="ghost" size="sm" aria-expanded={open} onClick={() => setOpen(!open)}>
                        {__("Edit")}
                        <ChevronDown aria-hidden className={cn("fxc:transition-transform", open && "fxc:rotate-180")} />
                    </Button>
                )}
                <Button variant="ghost" size="icon" aria-label={sprintf(__("Remove %s"), title)} onClick={onRemove}>
                    <Trash2 aria-hidden />
                </Button>
            </div>
            {open && type && (
                <div className="fxc:bg-muted/30 fxc:space-y-4 fxc:border-t fxc:px-4 fxc:py-4">
                    {type.fields.map((field) => (
                        <FieldRow
                            key={field.id}
                            field={field}
                            id={`${idPrefix}-${field.id}`}
                            value={method.settings[field.id]}
                            onChange={(value) => onChange({ settings: { ...method.settings, [field.id]: value } })}
                        />
                    ))}
                </div>
            )}
        </li>
    );
}
