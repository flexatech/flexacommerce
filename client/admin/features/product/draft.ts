import type { ProductAttribute, ProductData, VariantRow } from "./types";

/**
 * The editor keeps the loaded product as a draft and sends it back whole:
 * the server deletes variations that are missing from the list, so a partial
 * list would drop rows.
 */
export type Draft = ProductData;

/** Fields the editor sends for a row. Ids, product ids and the combination of the base row are server-owned. */
const ROW_FIELDS = [
    "enabled",
    "sku",
    "regular_price",
    "sale_price",
    "sale_from",
    "sale_to",
    "manage_stock",
    "stock_quantity",
    "backorders",
    "low_stock_threshold",
    "stock_status",
    "weight",
    "length",
    "width",
    "height",
    "image_id",
    "virtual",
    "downloadable",
    "downloads",
] as const satisfies readonly (keyof VariantRow)[];

function rowFields(row: VariantRow): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const key of ROW_FIELDS) {
        out[key] = row[key];
    }
    return out;
}

function attributePayload(attribute: ProductAttribute): Record<string, unknown> {
    const common = { visible: attribute.visible, variation: attribute.variation };
    if (attribute.type === "global") {
        return {
            type: "global",
            attribute_term_id: attribute.attribute_term_id,
            value_term_ids: attribute.value_term_ids,
            ...common,
        };
    }
    return { type: "local", name: attribute.name, values: attribute.values, ...common };
}

function sameCombination(a: Record<string, string>, b: Record<string, string>): boolean {
    const keys = Object.keys(a);
    return keys.length === Object.keys(b).length && keys.every((key) => a[key] === b[key]);
}

/**
 * `saved` is the product as last loaded. An existing variation only sends its
 * attributes when the merchant changed them: the server then treats rows
 * whose values were removed from an attribute as stale (deleted, or disabled
 * when they hold stock) instead of rejecting the save.
 */
export function toPayload(draft: Draft, saved?: ProductData): Record<string, unknown> {
    const before = new Map((saved?.variations ?? []).map((row) => [row.id, row.attributes]));
    const payload: Record<string, unknown> = {
        type: draft.type,
        visibility: draft.visibility,
        featured: draft.featured,
        gallery_ids: draft.gallery.map((image) => image.id),
        video: draft.video === null ? null : { url: draft.video.attachment_id > 0 ? "" : draft.video.url, attachment_id: draft.video.attachment_id, position: draft.video.position },
        attributes: draft.attributes.map(attributePayload),
        base: rowFields(draft.base),
    };
    if (draft.type === "variable") {
        payload.variations = draft.variations.map((row, index) => ({
            ...(row.id > 0 ? { id: row.id } : {}),
            ...(row.id > 0 && sameCombination(row.attributes, before.get(row.id) ?? {}) ? {} : { attributes: row.attributes }),
            ...rowFields(row),
            stock_from_base: row.stock_from_base,
            description: row.description,
            menu_order: index,
        }));
    }
    return payload;
}

/** Attribute definitions as the server compares them, to tell whether variation options are stale. */
export function attributesSignature(attributes: ProductAttribute[]): string {
    return JSON.stringify(attributes.map(attributePayload));
}

export function newVariation(base: VariantRow, axes: ProductAttribute[]): VariantRow {
    const attributes: Record<string, string> = {};
    for (const axis of axes) {
        attributes[axis.key] = "*";
    }
    return {
        ...base,
        id: 0,
        is_base: false,
        attributes,
        enabled: true,
        sku: null,
        regular_price: base.regular_price,
        sale_price: null,
        sale_from: null,
        sale_to: null,
        manage_stock: false,
        stock_from_base: false,
        stock_quantity: null,
        low_stock_threshold: null,
        stock_status: "instock",
        weight: null,
        length: null,
        width: null,
        height: null,
        image_id: null,
        description: null,
        virtual: base.virtual,
        downloadable: base.downloadable,
        downloads: { files: [], limit: base.downloads.limit, expiry_days: base.downloads.expiry_days },
    };
}

/** "Size: M, Color: Any" */
export function combinationLabel(row: VariantRow, axes: ProductAttribute[], anyLabel: string): string {
    return axes
        .map((axis) => {
            const value = row.attributes[axis.key];
            const label = value === undefined || value === "*" ? anyLabel : axis.options.find((o) => o.key === value)?.label ?? value;
            return `${axis.name}: ${label}`;
        })
        .join(", ");
}
