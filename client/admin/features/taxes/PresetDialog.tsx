import { useEffect, useState } from "react";
import { AlertTriangle } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import { previewPreset, useApplyPreset, type PresetPreview, type TaxOverview, type TaxPreset } from "./useTaxes";

/** Readable names for the settings a preset can propose. */
const SETTING_LABELS: Record<string, string> = {
    prices_include_tax: __("Prices entered with tax"),
    tax_based_on: __("Calculate tax based on"),
    tax_default_location: __("Before the address is known"),
    tax_display_shop: __("Prices in the shop"),
    tax_display_cart: __("Prices in the cart and checkout"),
    tax_total_display: __("Tax totals"),
    tax_round_at_subtotal: __("Round tax at subtotal"),
    shipping_tax_class: __("Shipping tax class"),
    price_display_suffix: __("Price suffix"),
};

function show(value: unknown): string {
    if (typeof value === "boolean") {
        return value ? __("Yes") : __("No");
    }
    return String(value);
}

/**
 * A preset writes one base rate per picked state (where the store has
 * nexus) and can set tax options. Applying it again replaces only its own
 * rows; rates added by hand or imported stay.
 */
export function PresetDialog({ overview, onClose }: { overview: TaxOverview; onClose: () => void }) {
    const [presetId, setPresetId] = useState(overview.presets[0]?.id ?? "");
    const preset = overview.presets.find((p) => p.id === presetId);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="fxc:max-h-[90vh] fxc:max-w-2xl fxc:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{__("Apply a tax preset")}</DialogTitle>
                    <DialogDescription>{__("Pick the states where you must collect tax. The preset adds their base rate to the Standard class.")}</DialogDescription>
                </DialogHeader>
                {overview.presets.length > 1 && (
                    <div>
                        <Label htmlFor="fxc-preset" className="fxc:mb-2 fxc:block">
                            {__("Preset")}
                        </Label>
                        <Select id="fxc-preset" value={presetId} onChange={(e) => setPresetId(e.target.value)} options={overview.presets.map((p) => ({ value: p.id, label: p.name }))} />
                    </div>
                )}
                {preset && <PresetForm key={preset.id} preset={preset} overview={overview} onClose={onClose} />}
            </DialogContent>
        </Dialog>
    );
}

function PresetForm({ preset, overview, onClose }: { preset: TaxPreset; overview: TaxOverview; onClose: () => void }) {
    const apply = useApplyPreset();
    const [states, setStates] = useState<Set<string>>(new Set());
    const [settings, setSettings] = useState<Set<string>>(new Set());
    const [preview, setPreview] = useState<PresetPreview | null>(null);
    const [checking, setChecking] = useState(false);

    const country = overview.countries.find((c) => c.code === preset.country);
    const stateName = (code: string) => country?.states.find((s) => s.code === code)?.name ?? code;
    const taxed = preset.regions.filter((r) => r.rate !== null);
    const untaxed = preset.regions.filter((r) => r.rate === null);

    // The preview goes stale when the picked states change.
    useEffect(() => setPreview(null), [states]);

    const toggle = (set: Set<string>, value: string, update: (next: Set<string>) => void) => {
        const next = new Set(set);
        if (next.has(value)) {
            next.delete(value);
        } else {
            next.add(value);
        }
        update(next);
    };

    const check = async () => {
        setChecking(true);
        try {
            setPreview(await previewPreset(preset.id, [...states]));
        } catch (e) {
            showToast.error(e instanceof Error ? e.message : String(e));
        } finally {
            setChecking(false);
        }
    };

    const submit = () =>
        apply.mutate(
            { id: preset.id, states: [...states], settings: [...settings] },
            {
                onSuccess: (result) => {
                    showToast.success(sprintf(__("%d tax rates written."), result.written));
                    onClose();
                },
                onError: (e) => showToast.error(e.message),
            },
        );

    const proposed = Object.entries(preset.settings);

    return (
        <div className="fxc:space-y-5">
            <div className="fxc:flex fxc:gap-3 fxc:rounded-md fxc:border fxc:border-amber-300 fxc:bg-amber-50 fxc:p-3 fxc:text-sm fxc:text-amber-900 fxc:dark:border-amber-700 fxc:dark:bg-amber-950 fxc:dark:text-amber-100">
                <AlertTriangle aria-hidden className="fxc:mt-0.5 fxc:size-4 fxc:shrink-0" />
                <div className="fxc:space-y-1">
                    <p>{sprintf(__("Rates as of %s. They are a starting point: check them with each state's revenue department."), preset.as_of)}</p>
                    <p>{__("You are responsible for the tax you collect. Most states add county and city taxes on top of the base rate; add those as more rates or import them from a CSV.")}</p>
                    {preset.notes !== "" && <p className="fxc:text-xs">{preset.notes}</p>}
                </div>
            </div>

            <fieldset>
                <div className="fxc:mb-2 fxc:flex fxc:items-center fxc:justify-between">
                    <legend className="fxc:text-sm fxc:font-medium">{__("States where you collect tax")}</legend>
                    <div className="fxc:flex fxc:gap-1">
                        <Button variant="link" size="sm" onClick={() => setStates(new Set(taxed.map((r) => r.state)))}>
                            {__("Select all")}
                        </Button>
                        <Button variant="link" size="sm" onClick={() => setStates(new Set())}>
                            {__("Clear")}
                        </Button>
                    </div>
                </div>
                <div className="fxc:grid fxc:max-h-64 fxc:grid-cols-2 fxc:gap-x-4 fxc:gap-y-1.5 fxc:overflow-y-auto fxc:rounded-md fxc:border fxc:p-3 fxc:sm:grid-cols-3">
                    {taxed.map((region) => (
                        <label key={region.state} className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                            <input
                                type="checkbox"
                                className="flexacommerce-check"
                                checked={states.has(region.state)}
                                onChange={() => toggle(states, region.state, setStates)}
                            />
                            <span className="fxc:truncate">{stateName(region.state)}</span>
                            <span className="fxc:text-muted-foreground fxc:ml-auto fxc:text-xs fxc:tabular-nums">{region.rate}%</span>
                        </label>
                    ))}
                </div>
                {untaxed.length > 0 && (
                    <p className="fxc:text-muted-foreground fxc:mt-2 fxc:text-xs">
                        {sprintf(__("No statewide sales tax: %s."), untaxed.map((r) => stateName(r.state)).join(", "))}
                    </p>
                )}
            </fieldset>

            {(proposed.length > 0 || preset.market) && (
                <fieldset>
                    <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Also change these options")}</legend>
                    <div className="fxc:space-y-1.5">
                        {proposed.map(([key, value]) => (
                            <label key={key} className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                                <input type="checkbox" className="flexacommerce-check" checked={settings.has(key)} onChange={() => toggle(settings, key, setSettings)} />
                                <span>
                                    {SETTING_LABELS[key] ?? key}: <strong>{show(value)}</strong>
                                </span>
                            </label>
                        ))}
                        {preset.market && (
                            <label className="fxc:flex fxc:items-start fxc:gap-2 fxc:text-sm">
                                <input
                                    type="checkbox"
                                    className="flexacommerce-check fxc:mt-0.5"
                                    checked={settings.has("market")}
                                    onChange={() => toggle(settings, "market", setSettings)}
                                />
                                <span>
                                    {sprintf(__("Add the “%s” market"), preset.market.name || preset.market.id)}
                                    {preset.origin_states.length > 0 && (
                                        <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">
                                            {sprintf(__("Sales inside the store's state use the store address in: %s."), preset.origin_states.join(", "))}
                                        </span>
                                    )}
                                </span>
                            </label>
                        )}
                    </div>
                </fieldset>
            )}

            {preview && (
                <div className="fxc:bg-muted fxc:rounded-md fxc:p-3 fxc:text-sm">
                    <p>
                        {sprintf(__("Adds %1$d rates and removes %2$d rates written by this preset before."), preview.add.length, preview.remove.length)}
                    </p>
                    <p className="fxc:text-muted-foreground fxc:text-xs">{__("Rates you added by hand or imported are not touched.")}</p>
                </div>
            )}

            <DialogFooter>
                <Button variant="ghost" onClick={onClose} disabled={apply.isPending}>
                    {__("Cancel")}
                </Button>
                {preview ? (
                    <Button onClick={submit} disabled={apply.isPending}>
                        {apply.isPending ? __("Applying…") : __("Apply preset")}
                    </Button>
                ) : (
                    <Button onClick={() => void check()} disabled={checking}>
                        {checking ? __("Checking…") : __("Preview changes")}
                    </Button>
                )}
            </DialogFooter>
        </div>
    );
}
