import { useState } from "react";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { api, getPluginGlobal } from "@/shared/lib/api";
import { __ } from "@/shared/lib/i18n";
import { Field, Toggle } from "./fields";
import type { FieldErrors } from "./RowFields";
import type { ProductAttribute, ProductType, StoreAttribute } from "./types";
import { ATTRIBUTES_KEY, useStoreAttributes } from "./useProduct";

const LOCAL = "local";

/** Local values are typed as one line separated by "|", the same as WooCommerce. */
function splitValues(text: string): string[] {
    return text
        .split("|")
        .map((value) => value.trim())
        .filter((value) => value !== "");
}

function globalFromStore(store: StoreAttribute, position: number): ProductAttribute {
    return {
        type: "global",
        key: `g:${store.id}`,
        name: store.name,
        attribute_term_id: store.id,
        value_term_ids: [],
        visible: true,
        variation: false,
        position,
        options: [],
    };
}

export function AttributesPanel({
    attributes,
    productType,
    onChange,
    errors,
}: {
    attributes: ProductAttribute[];
    productType: ProductType;
    onChange: (attributes: ProductAttribute[]) => void;
    errors: FieldErrors;
}) {
    const store = useStoreAttributes();
    const [adding, setAdding] = useState(LOCAL);
    const { canManageTerms, attributesAdminUrl } = getPluginGlobal();

    const used = new Set(attributes.filter((a) => a.type === "global").map((a) => (a.type === "global" ? a.attribute_term_id : 0)));
    const addOptions = [
        { value: LOCAL, label: __("Custom attribute for this product") },
        ...(store.data ?? []).filter((a) => !used.has(a.id)).map((a) => ({ value: String(a.id), label: a.name })),
    ];

    const update = (index: number, next: ProductAttribute) => onChange(attributes.map((a, i) => (i === index ? next : a)));
    const remove = (index: number) => onChange(attributes.filter((_, i) => i !== index));
    const move = (index: number, delta: number) => {
        const target = index + delta;
        if (target < 0 || target >= attributes.length) {
            return;
        }
        const next = [...attributes];
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    const add = () => {
        if (adding === LOCAL) {
            onChange([
                ...attributes,
                { type: "local", key: "", name: "", values: [], visible: true, variation: productType === "variable", position: attributes.length, options: [] },
            ]);
            return;
        }
        const chosen = store.data?.find((a) => String(a.id) === adding);
        if (chosen) {
            onChange([...attributes, { ...globalFromStore(chosen, attributes.length), variation: productType === "variable" }]);
            setAdding(LOCAL);
        }
    };

    return (
        <div className="fxc:space-y-4">
            {attributes.length === 0 && (
                <p className="fxc:text-muted-foreground fxc:text-sm">
                    {__("Attributes describe the product, such as size or material. Variable products use them to define variations.")}
                </p>
            )}

            {attributes.map((attribute, index) => (
                <div key={attribute.key || `new-${index}`} className="fxc:border-border fxc:rounded-lg fxc:border fxc:p-4">
                    <div className="fxc:mb-3 fxc:flex fxc:items-center fxc:justify-between fxc:gap-2">
                        <span className="fxc:text-sm fxc:font-semibold">
                            {attribute.type === "global" ? attribute.name : attribute.name || __("New attribute")}
                            {attribute.type === "global" && <span className="fxc:text-muted-foreground fxc:ml-2 fxc:text-xs fxc:font-normal">{__("Store attribute")}</span>}
                        </span>
                        <div className="fxc:flex fxc:items-center fxc:gap-1">
                            <Button variant="ghost" size="icon" aria-label={__("Move up")} disabled={index === 0} onClick={() => move(index, -1)}>
                                <ArrowUp aria-hidden />
                            </Button>
                            <Button variant="ghost" size="icon" aria-label={__("Move down")} disabled={index === attributes.length - 1} onClick={() => move(index, 1)}>
                                <ArrowDown aria-hidden />
                            </Button>
                            <Button variant="ghost" size="icon" aria-label={__("Remove attribute")} onClick={() => remove(index)}>
                                <Trash2 aria-hidden />
                            </Button>
                        </div>
                    </div>

                    {attribute.type === "local" ? (
                        <LocalEditor attribute={attribute} onChange={(next) => update(index, next)} errors={errors} path={`attributes.${index}`} />
                    ) : (
                        <GlobalEditor
                            attribute={attribute}
                            store={store.data?.find((a) => a.id === attribute.attribute_term_id)}
                            canCreate={Boolean(canManageTerms)}
                            onChange={(next) => update(index, next)}
                            error={errors[`attributes.${index}`] ?? errors[`attributes.${index}.value_term_ids`]}
                        />
                    )}

                    <div className="fxc:mt-3 fxc:flex fxc:flex-wrap fxc:gap-x-6 fxc:gap-y-2">
                        <Toggle checked={attribute.visible} onChange={(visible) => update(index, { ...attribute, visible })} label={__("Show on the product page")} />
                        {productType === "variable" && (
                            <Toggle checked={attribute.variation} onChange={(variation) => update(index, { ...attribute, variation })} label={__("Used for variations")} />
                        )}
                    </div>
                </div>
            ))}

            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                <Select options={addOptions} value={adding} onChange={(event) => setAdding(event.target.value)} aria-label={__("Attribute to add")} />
                <Button variant="outline" onClick={add}>
                    <Plus aria-hidden />
                    {__("Add attribute")}
                </Button>
                {canManageTerms && attributesAdminUrl && (
                    <a href={attributesAdminUrl} className="fxc:text-primary fxc:text-sm">
                        {__("Manage store attributes")}
                    </a>
                )}
            </div>
        </div>
    );
}

function LocalEditor({
    attribute,
    onChange,
    errors,
    path,
}: {
    attribute: Extract<ProductAttribute, { type: "local" }>;
    onChange: (next: ProductAttribute) => void;
    errors: FieldErrors;
    path: string;
}) {
    const [text, setText] = useState(attribute.values.join(" | "));

    return (
        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-[1fr_2fr]">
            <Field label={__("Name")} error={errors[`${path}.name`] ?? errors[path]}>
                {(id) => <Input id={id} value={attribute.name} onChange={(event) => onChange({ ...attribute, name: event.target.value })} />}
            </Field>
            <Field label={__("Values")} help={__("Separate values with a vertical bar, for example: S | M | L")} error={errors[`${path}.values`]}>
                {(id) => (
                    <Input
                        id={id}
                        value={text}
                        onChange={(event) => {
                            setText(event.target.value);
                            onChange({ ...attribute, values: splitValues(event.target.value) });
                        }}
                    />
                )}
            </Field>
        </div>
    );
}

function GlobalEditor({
    attribute,
    store,
    canCreate,
    onChange,
    error,
}: {
    attribute: Extract<ProductAttribute, { type: "global" }>;
    store: StoreAttribute | undefined;
    canCreate: boolean;
    onChange: (next: ProductAttribute) => void;
    error?: string;
}) {
    const qc = useQueryClient();
    const [newValue, setNewValue] = useState("");
    const [creating, setCreating] = useState(false);
    const selected = new Set(attribute.value_term_ids);
    const values = store?.values ?? [];

    const toggle = (id: number, on: boolean) =>
        onChange({
            ...attribute,
            value_term_ids: on ? [...attribute.value_term_ids, id] : attribute.value_term_ids.filter((v) => v !== id),
        });

    const create = async () => {
        const name = newValue.trim();
        if (name === "" || !store) {
            return;
        }
        setCreating(true);
        try {
            const { items } = await api.post<{ items: StoreAttribute[] }>(`admin/attributes/${store.id}/values`, { name });
            qc.setQueryData(ATTRIBUTES_KEY, items);
            const created = items.find((a) => a.id === store.id)?.values.find((v) => !values.some((old) => old.id === v.id));
            if (created) {
                onChange({ ...attribute, value_term_ids: [...attribute.value_term_ids, created.id] });
            }
            setNewValue("");
        } catch (e) {
            showToast.error(e instanceof Error ? e.message : String(e));
        } finally {
            setCreating(false);
        }
    };

    return (
        <div className="fxc:space-y-3">
            {values.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:text-sm">{__("This attribute has no values yet.")}</p>
            ) : (
                <div className="fxc:flex fxc:flex-wrap fxc:gap-x-4 fxc:gap-y-2">
                    {values.map((value) => (
                        <Toggle key={value.id} checked={selected.has(value.id)} onChange={(on) => toggle(value.id, on)} label={value.name} />
                    ))}
                </div>
            )}
            <div className="fxc:flex fxc:gap-2">
                <Button variant="ghost" size="sm" onClick={() => onChange({ ...attribute, value_term_ids: values.map((v) => v.id) })} disabled={values.length === 0}>
                    {__("Select all")}
                </Button>
                <Button variant="ghost" size="sm" onClick={() => onChange({ ...attribute, value_term_ids: [] })} disabled={selected.size === 0}>
                    {__("Select none")}
                </Button>
            </div>
            {canCreate && store && (
                <div className="fxc:flex fxc:max-w-md fxc:gap-2">
                    <Input
                        value={newValue}
                        placeholder={__("New value")}
                        aria-label={__("New value")}
                        onChange={(event) => setNewValue(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === "Enter") {
                                event.preventDefault();
                                void create();
                            }
                        }}
                    />
                    <Button variant="outline" size="sm" className="fxc:h-9" onClick={() => void create()} disabled={creating || newValue.trim() === ""}>
                        {__("Add value")}
                    </Button>
                </div>
            )}
            {error && <p className="fxc:text-destructive fxc:text-xs">{error}</p>}
        </div>
    );
}
