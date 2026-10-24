/**
 * Shapes of `admin/products/{id}` (Api\Admin\ProductsEndpoint). Prices are
 * integers in the currency's minor unit; weight is grams and dimensions are
 * millimetres, both as decimal strings.
 */

export type ProductType = "simple" | "variable";

export type Visibility = "visible" | "catalog" | "search" | "hidden";

export type StockStatus = "instock" | "outofstock" | "onbackorder";

export type Backorders = "no" | "notify" | "yes";

/** Combination key (`g:{term}` or `l:{slug}`) => value key, or `*` for any. */
export type Combination = Record<string, string>;

export interface VariantRow {
    id: number;
    product_id: number;
    is_base: boolean;
    attributes: Combination;
    enabled: boolean;
    menu_order: number;
    sku: string | null;
    regular_price: number | null;
    sale_price: number | null;
    sale_from: string | null;
    sale_to: string | null;
    manage_stock: boolean;
    stock_from_base: boolean;
    stock_quantity: number | null;
    backorders: Backorders;
    low_stock_threshold: number | null;
    stock_status: StockStatus;
    weight: string | null;
    length: string | null;
    width: string | null;
    height: string | null;
    image_id: number | null;
    tax_class: string;
    description: string | null;
}

export interface AttributeOption {
    key: string;
    label: string;
}

interface AttributeCommon {
    key: string;
    name: string;
    visible: boolean;
    variation: boolean;
    position: number;
    options: AttributeOption[];
}

export interface GlobalAttribute extends AttributeCommon {
    type: "global";
    attribute_term_id: number;
    value_term_ids: number[];
}

export interface LocalAttribute extends AttributeCommon {
    type: "local";
    values: string[];
}

export type ProductAttribute = GlobalAttribute | LocalAttribute;

export interface ProductData {
    id: number;
    type: ProductType;
    status: string;
    attributes: ProductAttribute[];
    base: VariantRow;
    variations: VariantRow[];
    gallery_ids: number[];
    visibility: Visibility;
    featured: boolean;
    price_min: number | null;
    price_max: number | null;
    stock_status: string;
    // Post fields, owned by WordPress. The metabox never sends them (the
    // block editor saves the post); a standalone editor app can.
    title?: string;
    slug?: string;
    content?: string;
    excerpt?: string;
    date?: string;
    modified?: string;
    menu_order?: number;
    categories?: number[];
    tags?: number[];
    featured_image?: number;
    permalink?: string;
}

export interface GenerateResult {
    created: number;
    skipped: number;
    limit_reached: boolean;
    product: ProductData;
}

/** A term of the `fxc_attribute` taxonomy as listed by `admin/attributes`. */
export interface AttributeTerm {
    id: number;
    name: string;
    slug: string;
    position: number;
}

export interface StoreAttribute extends AttributeTerm {
    values: AttributeTerm[];
    usage: number;
}
