import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from "react";
import { Boxes, Layers, Package, Tag, Truck } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";
import { ApiError } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { AttributesPanel } from "./AttributesPanel";
import { attributesSignature, toPayload, type Draft } from "./draft";
import { Field, InvalidFields, Toggle } from "./fields";
import { InventoryFields, PricingFields, ShippingFields, type FieldErrors } from "./RowFields";
import type { ProductType, VariantRow, Visibility } from "./types";
import { useGenerateVariants, useProduct, useSaveProduct } from "./useProduct";
import { editorErrorNotice, useSaveWithPost } from "./useSaveWithPost";
import { VariationsPanel } from "./VariationsPanel";

type Tab = "general" | "inventory" | "shipping" | "attributes" | "variations";

const VISIBILITY_OPTIONS: { value: Visibility; label: string }[] = [
    { value: "visible", label: __("Shop and search results") },
    { value: "catalog", label: __("Shop only") },
    { value: "search", label: __("Search results only") },
    { value: "hidden", label: __("Hidden") },
];

function errorsFrom(error: unknown): FieldErrors {
    if (error instanceof ApiError && typeof error.data.field === "string" && error.data.field !== "") {
        return { [error.data.field]: error.message };
    }
    return {};
}

/** The tab that holds the field a server error points at. */
function tabFor(field: string): Tab | null {
    if (field.startsWith("variations")) {
        return "variations";
    }
    if (field.startsWith("attributes")) {
        return "attributes";
    }
    if (/^base\.(sku|manage_stock|stock_|backorders|low_stock)/.test(field)) {
        return "inventory";
    }
    if (/^base\.(weight|length|width|height)/.test(field)) {
        return "shipping";
    }
    return field.startsWith("base") || field === "type" ? "general" : null;
}

export function ProductEditor({ productId }: { productId: number }) {
    const query = useProduct(productId);
    const save = useSaveProduct(productId);
    const generate = useGenerateVariants(productId);

    const [draft, setDraft] = useState<Draft | null>(null);
    const [tab, setTab] = useState<Tab>("general");
    const [errors, setErrors] = useState<FieldErrors>({});
    const [invalid, setInvalid] = useState<Set<string>>(new Set());
    const draftRef = useRef<Draft | null>(null);
    draftRef.current = draft;
    const savedRef = useRef(query.data);
    savedRef.current = query.data;

    useEffect(() => {
        if (query.data) {
            setDraft(query.data);
        }
    }, [query.data]);

    const reportInvalid = useCallback((key: string, isInvalid: boolean) => {
        setInvalid((current) => {
            if (current.has(key) === isInvalid) {
                return current;
            }
            const next = new Set(current);
            if (isInvalid) {
                next.add(key);
            } else {
                next.delete(key);
            }
            return next;
        });
    }, []);
    const invalidRef = useRef(invalid);
    invalidRef.current = invalid;

    const attributesStale = useMemo(
        () => Boolean(draft && query.data && attributesSignature(draft.attributes) !== attributesSignature(query.data.attributes)),
        [draft, query.data],
    );

    const submit = useCallback(async (): Promise<boolean> => {
        const current = draftRef.current;
        if (!current) {
            return true;
        }
        if (invalidRef.current.size > 0) {
            const message = __("Product data was not saved: fix the highlighted fields first.");
            if (!editorErrorNotice(message)) {
                showToast.error(message);
            }
            return false;
        }
        try {
            await save.mutateAsync(toPayload(current, savedRef.current));
            setErrors({});
            return true;
        } catch (error) {
            const fieldErrors = errorsFrom(error);
            setErrors(fieldErrors);
            const field = Object.keys(fieldErrors)[0];
            const target = field ? tabFor(field) : null;
            if (target) {
                setTab(target);
            }
            const message = sprintf(__("Product data was not saved: %s"), error instanceof Error ? error.message : String(error));
            if (!editorErrorNotice(message)) {
                showToast.error(message);
            }
            return false;
        }
    }, [save]);

    useSaveWithPost(submit);

    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }
    if (!draft) {
        return <p className="fxc:text-muted-foreground">{__("Loading product data…")}</p>;
    }

    const set = (patch: Partial<Draft>) => setDraft({ ...draft, ...patch });
    const setBase = (patch: Partial<VariantRow>) => setDraft({ ...draft, base: { ...draft.base, ...patch } });
    const axes = (query.data ?? draft).attributes.filter((a) => a.variation);
    const busy = save.isPending || generate.isPending;

    const runGenerate = async () => {
        // Generation reads the stored product, so pending edits go first.
        if (!(await submit())) {
            return;
        }
        try {
            const result = await generate.mutateAsync({});
            showToast.success(
                result.limit_reached
                    ? __("The variation limit was reached; some combinations were not created.")
                    : sprintf(_n("%d variation created.", "%d variations created.", result.created), result.created),
            );
        } catch (error) {
            showToast.error(error instanceof Error ? error.message : String(error));
        }
    };

    const tabs: { id: Tab; label: string; icon: typeof Tag; hidden?: boolean }[] = [
        { id: "general", label: __("General"), icon: Tag },
        { id: "inventory", label: __("Inventory"), icon: Boxes },
        { id: "shipping", label: __("Shipping"), icon: Truck },
        { id: "attributes", label: __("Attributes"), icon: Package },
        { id: "variations", label: __("Variations"), icon: Layers, hidden: draft.type !== "variable" },
    ];
    const visibleTab = tab === "variations" && draft.type !== "variable" ? "general" : tab;
    const isVariable = draft.type === "variable";

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
                    <div className="fxc:pb-2">
                        <Toggle checked={draft.featured} onChange={(featured) => set({ featured })} label={__("Featured")} />
                    </div>
                    <div className="fxc:ml-auto fxc:flex fxc:items-center fxc:gap-3 fxc:pb-0.5">
                        {save.isPending && <span className="fxc:text-muted-foreground fxc:text-xs">{__("Saving…")}</span>}
                        <Button variant="outline" size="sm" className="fxc:h-9" disabled={busy} onClick={() => void submit().then((ok) => ok && showToast.success(__("Product data saved.")))}>
                            {__("Save product data")}
                        </Button>
                    </div>
                </div>

                {query.data?.type === "variable" && !isVariable && query.data.variations.length > 0 && (
                    <p className="fxc:bg-warning/15 fxc:rounded-md fxc:px-3 fxc:py-2 fxc:text-sm">
                        {sprintf(
                            _n(
                                "Saving as a simple product deletes its %d variation.",
                                "Saving as a simple product deletes its %d variations.",
                                query.data.variations.length,
                            ),
                            query.data.variations.length,
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
                        {visibleTab === "general" &&
                            (isVariable ? (
                                <p className="fxc:text-muted-foreground fxc:text-sm">
                                    {__("Prices of a variable product are set on each variation. The shop shows the range from the lowest to the highest price.")}
                                </p>
                            ) : (
                                <PricingFields row={draft.base} onChange={setBase} errors={errors} path="base" />
                            ))}
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
                        {visibleTab === "attributes" && (
                            <AttributesPanel attributes={draft.attributes} productType={draft.type} onChange={(attributes) => set({ attributes })} errors={errors} />
                        )}
                        {visibleTab === "variations" && (
                            <VariationsPanel
                                base={draft.base}
                                rows={draft.variations}
                                axes={axes}
                                stale={attributesStale || query.data?.type !== "variable"}
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
