import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ArrowDown, ArrowUp, X } from "lucide-react";
import { useState } from "react";

import { SearchBox, type Row } from "../simulator/SearchBox";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { api } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { Field } from "./fields";
import type { FieldErrors } from "./RowFields";
import type { GroupChild, ProductType } from "./types";

const MAX_CHILDREN = 100;

const TYPE_LABELS: Record<ProductType, string> = {
    simple: __("Simple"),
    variable: __("Variable"),
    grouped: __("Grouped"),
    external: __("External"),
    bundle: __("Bundle"),
};

/**
 * The products a grouped product lists (ADR-062): found by name, shown in
 * the order the shop lists them. A grouped product cannot list another
 * grouped product or itself.
 */
export function GroupedFields({ productId, items, onChange, errors }: { productId: number; items: GroupChild[]; onChange: (items: GroupChild[]) => void; errors: FieldErrors }) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);
    const search = useDebounced(text.trim(), 250);
    const full = items.length >= MAX_CHILDREN;
    const results = useQuery({
        queryKey: ["products", "group-search", search],
        queryFn: () => api.get<GroupChild[]>("admin/products", { search, per_page: 20, orderby: search === "" ? "date" : "title", order: search === "" ? "desc" : "asc" }),
        enabled: open && !full,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
    });
    const taken = new Set(items.map((item) => item.id));
    const found = (results.data ?? []).filter((p) => p.type !== "grouped" && p.id !== productId && !taken.has(p.id));
    const rows: Row[] = found.map((p) => ({ key: String(p.id), label: p.title || __("(no title)"), hint: [TYPE_LABELS[p.type], p.sku ?? ""].filter(Boolean).join(" · ") }));

    const move = (index: number, by: number) => {
        const next = [...items];
        const [item] = next.splice(index, 1);
        next.splice(index + by, 0, item);
        onChange(next);
    };

    return (
        <div className="fxc:space-y-4">
            <p className="fxc:text-muted-foreground fxc:text-sm">
                {__("A grouped product shows other products on one page. The shopper picks a quantity of each and adds them together. Its price is the range of the products it lists.")}
            </p>
            <SearchBox
                id="fxc-group-products"
                label={__("Add a product to the group")}
                placeholder={full ? sprintf(__("At most %d products"), MAX_CHILDREN) : __("Search products by name")}
                text={text}
                onText={setText}
                rows={rows}
                fetching={results.isFetching}
                onPick={(row) => {
                    const picked = found.find((p) => String(p.id) === row.key);
                    if (picked) {
                        onChange([...items, { id: picked.id, title: picked.title, sku: picked.sku, type: picked.type, status: picked.status }]);
                    }
                }}
                open={open && !full}
                onOpen={setOpen}
            />
            {errors.children && <p className="fxc:text-destructive fxc:text-sm" role="alert">{errors.children}</p>}
            {items.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:text-sm">{__("No products in this group yet.")}</p>
            ) : (
                <ol className="fxc:border-border fxc:divide-border fxc:divide-y fxc:rounded-md fxc:border" aria-label={__("Products in this group")}>
                    {items.map((item, index) => (
                        <li key={item.id} className="fxc:flex fxc:items-center fxc:gap-3 fxc:px-3 fxc:py-2 fxc:text-sm">
                            <span className="fxc:min-w-0 fxc:flex-1 fxc:truncate">
                                {item.title || __("(no title)")}
                                {item.status !== "publish" && <span className="fxc:text-muted-foreground"> ({__("not published, hidden in the shop")})</span>}
                            </span>
                            <span className="fxc:text-muted-foreground fxc:shrink-0 fxc:text-xs">{[TYPE_LABELS[item.type], item.sku ?? ""].filter(Boolean).join(" · ")}</span>
                            <Button variant="ghost" size="icon" className="fxc:size-7" disabled={index === 0} aria-label={sprintf(__("Move %s up"), item.title)} onClick={() => move(index, -1)}>
                                <ArrowUp aria-hidden className="fxc:size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" className="fxc:size-7" disabled={index === items.length - 1} aria-label={sprintf(__("Move %s down"), item.title)} onClick={() => move(index, 1)}>
                                <ArrowDown aria-hidden className="fxc:size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" className="fxc:size-7" aria-label={sprintf(__("Remove %s"), item.title)} onClick={() => onChange(items.filter((other) => other.id !== item.id))}>
                                <X aria-hidden className="fxc:size-4" />
                            </Button>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

/** Where an external product is sold, and its button (ADR-062). */
export function ExternalFields({ url, text, onChange, errors }: { url: string; text: string; onChange: (patch: { product_url?: string; button_text?: string }) => void; errors: FieldErrors }) {
    return (
        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
            <Field label={__("Product URL")} help={__("The shop button opens this address. Shoppers buy the product there.")} error={errors.product_url}>
                {(id) => <Input id={id} type="url" inputMode="url" placeholder="https://" value={url} onChange={(e) => onChange({ product_url: e.target.value })} />}
            </Field>
            <Field label={__("Button text")} help={__("Leave empty for “Buy product”.")} error={errors.button_text}>
                {(id) => <Input id={id} value={text} maxLength={100} placeholder={__("Buy product")} onChange={(e) => onChange({ button_text: e.target.value })} />}
            </Field>
        </div>
    );
}
