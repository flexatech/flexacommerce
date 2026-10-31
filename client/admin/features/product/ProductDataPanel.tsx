import type { KeyboardEvent } from "react";
import { Boxes, Download, Images, Layers, Package, Tag, Truck } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/cn";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { AttributesPanel } from "./AttributesPanel";
import { DownloadFields } from "./DownloadFields";
import type { Draft } from "./draft";
import { Field, InvalidFields, Toggle } from "./fields";
import { GalleryPanel } from "./GalleryPanel";
import { InventoryFields, PricingFields, ShippingFields, TaxFields } from "./RowFields";
import type { ProductType, VariantRow, Visibility } from "./types";
import type { ProductDraftState, Tab } from "./useProductDraft";
import { VariationsPanel } from "./VariationsPanel";

const VISIBILITY_OPTIONS: { value: Visibility; label: string }[] = [
    { value: "visible", label: __("Shop and search results") },
    { value: "catalog", label: __("Shop only") },
    { value: "search", label: __("Search results only") },
    { value: "hidden", label: __("Hidden") },
];

/**
 * The commerce half of the product editor: type, visibility, prices, stock,
 * shipping, gallery, attributes and variations. It renders a draft and knows nothing
 * about where it is mounted, so the metabox and a standalone editor app can
 * both use it.
 */
export function ProductDataPanel({ editor }: { editor: ProductDraftState }) {
    const { query, saved, draft, setDraft, tab, setTab, errors, reportInvalid, attributesStale, submit, runGenerate, saving, busy } = editor;

    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }
    if (!draft) {
        return <p className="fxc:text-muted-foreground">{__("Loading product data…")}</p>;
    }

    const set = (patch: Partial<Draft>) => setDraft({ ...draft, ...patch });
    const setBase = (patch: Partial<VariantRow>) => setDraft({ ...draft, base: { ...draft.base, ...patch } });
    const axes = (saved ?? draft).attributes.filter((a) => a.variation);

    const isVariable = draft.type === "variable";
    // A variable product sets these on each variation; the shipping tab then holds the defaults.
    const noShipping = !isVariable && draft.base.virtual;
    const sellsFiles = !isVariable && draft.base.downloadable;
    const tabs: { id: Tab; label: string; icon: typeof Tag; hidden?: boolean }[] = [
        { id: "general", label: __("General"), icon: Tag },
        { id: "inventory", label: __("Inventory"), icon: Boxes },
        { id: "shipping", label: __("Shipping"), icon: Truck, hidden: noShipping },
        { id: "downloads", label: __("Downloads"), icon: Download, hidden: !sellsFiles },
        { id: "gallery", label: __("Gallery"), icon: Images },
        { id: "attributes", label: __("Attributes"), icon: Package },
        { id: "variations", label: __("Variations"), icon: Layers, hidden: !isVariable },
    ];
    const visibleTab = tabs.some((t) => t.id === tab && !t.hidden) ? tab : "general";

    // Enter inside the metabox would submit the post form and reload the editor.
    const swallowEnter = (event: KeyboardEvent) => {
        if (event.key === "Enter" && event.target instanceof HTMLInputElement) {
            event.preventDefault();
        }
    };

    return (
        <InvalidFields.Provider value={reportInvalid}>
            <div onKeyDown={swallowEnter} className="fxc:space-y-4">
                <div className="fxc:flex fxc:flex-wrap fxc:items-end fxc:gap-4">
                    <Field label={__("Product type")}>
                        {(id) => (
                            <Select
                                id={id}
                                value={draft.type}
                                options={[
                                    { value: "simple", label: __("Simple product") },
                                    { value: "variable", label: __("Variable product") },
                                ]}
                                onChange={(event) => set({ type: event.target.value as ProductType })}
                            />
                        )}
                    </Field>
                    <Field label={__("Catalog visibility")}>
                        {(id) => (
                            <Select
                                id={id}
                                value={draft.visibility}
                                options={VISIBILITY_OPTIONS}
                                onChange={(event) => set({ visibility: event.target.value as Visibility })}
                            />
                        )}
                    </Field>
                    <div className="fxc:flex fxc:flex-wrap fxc:gap-4 fxc:pb-2">
                        <Toggle checked={draft.featured} onChange={(featured) => set({ featured })} label={__("Featured")} />
                        {!isVariable && (
                            <>
                                <Toggle checked={draft.base.virtual} onChange={(virtual) => setBase({ virtual })} label={__("Virtual")} />
                                <Toggle checked={draft.base.downloadable} onChange={(downloadable) => setBase({ downloadable })} label={__("Downloadable")} />
                            </>
                        )}
                    </div>
                    <div className="fxc:ml-auto fxc:flex fxc:items-center fxc:gap-3 fxc:pb-0.5">
                        {saving && <span className="fxc:text-muted-foreground fxc:text-xs">{__("Saving…")}</span>}
                        <Button variant="outline" size="sm" className="fxc:h-9" disabled={busy} onClick={() => void submit().then((ok) => ok && showToast.success(__("Product data saved.")))}>
                            {__("Save product data")}
                        </Button>
                    </div>
                </div>

                {saved?.type === "variable" && !isVariable && saved.variations.length > 0 && (
                    <p className="fxc:bg-warning/15 fxc:rounded-md fxc:px-3 fxc:py-2 fxc:text-sm">
                        {sprintf(
                            _n(
                                "Saving as a simple product deletes its %d variation.",
                                "Saving as a simple product deletes its %d variations.",
                                saved.variations.length,
                            ),
                            saved.variations.length,
                        )}
                    </p>
                )}

                <div className="fxc:border-border fxc:grid fxc:overflow-hidden fxc:rounded-lg fxc:border fxc:sm:grid-cols-[11rem_1fr]">
                    <nav className="fxc:bg-muted fxc:border-border fxc:flex fxc:flex-row fxc:flex-wrap fxc:border-b fxc:sm:flex-col fxc:sm:border-r fxc:sm:border-b-0" aria-label={__("Product data sections")}>
                        {tabs
                            .filter((t) => !t.hidden)
                            .map(({ id, label, icon: Icon }) => (
                                <button
                                    key={id}
                                    type="button"
                                    aria-current={visibleTab === id ? "true" : undefined}
                                    onClick={() => setTab(id)}
                                    className={cn(
                                        "fxc:flex fxc:cursor-pointer fxc:items-center fxc:gap-2 fxc:bg-transparent fxc:px-4 fxc:py-2.5 fxc:text-left fxc:text-sm fxc:outline-none fxc:hover:text-primary fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                                        visibleTab === id && "fxc:bg-background fxc:text-primary fxc:font-semibold",
                                    )}
                                >
                                    <Icon aria-hidden className="fxc:size-4" />
                                    {label}
                                </button>
                            ))}
                    </nav>

                    <div className="fxc:min-w-0 fxc:p-5">
                        {visibleTab === "general" && (
                            <div className="fxc:space-y-6">
                                {isVariable ? (
                                    <p className="fxc:text-muted-foreground fxc:text-sm">
                                        {__("Prices of a variable product are set on each variation. The shop shows the range from the lowest to the highest price.")}
                                    </p>
                                ) : (
                                    <PricingFields row={draft.base} onChange={setBase} errors={errors} path="base" />
                                )}
                                <TaxFields row={draft.base} onChange={setBase} errors={errors} path="base" />
                            </div>
                        )}
                        {visibleTab === "inventory" && (
                            <>
                                {isVariable && (
                                    <p className="fxc:text-muted-foreground fxc:mb-4 fxc:text-sm">
                                        {__("Stock tracked here is shared by variations that use the product's stock.")}
                                    </p>
                                )}
                                <InventoryFields row={draft.base} onChange={setBase} errors={errors} path="base" />
                            </>
                        )}
                        {visibleTab === "shipping" && <ShippingFields row={draft.base} onChange={setBase} errors={errors} path="base" />}
                        {visibleTab === "downloads" && <DownloadFields row={draft.base} onChange={setBase} errors={errors} path="base" />}
                        {visibleTab === "gallery" && (
                            <GalleryPanel gallery={draft.gallery} video={draft.video} errors={errors} onGallery={(gallery) => set({ gallery })} onVideo={(video) => set({ video })} />
                        )}
                        {visibleTab === "attributes" && (
                            <AttributesPanel attributes={draft.attributes} productType={draft.type} onChange={(attributes) => set({ attributes })} errors={errors} />
                        )}
                        {visibleTab === "variations" && (
                            <VariationsPanel
                                base={draft.base}
                                rows={draft.variations}
                                axes={axes}
                                stale={attributesStale || saved?.type !== "variable"}
                                busy={busy}
                                errors={errors}
                                onChange={(variations) => set({ variations })}
                                onGenerate={() => void runGenerate()}
                                onSaveAttributes={() => void submit()}
                            />
                        )}
                    </div>
                </div>
            </div>
        </InvalidFields.Provider>
    );
}
