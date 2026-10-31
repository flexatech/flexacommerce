/**
 * Shapes of `admin/products/{id}` (Api\Admin\ProductsEndpoint). Prices are
 * integers in the currency's minor unit; weight is grams and dimensions are
 * millimetres, both as decimal strings.
 */

export type ProductType = "simple" | "variable";

export type Visibility = "visible" | "catalog" | "search" | "hidden";

export type StockStatus = "instock" | "outofstock" | "onbackorder";

export type TaxStatus = "taxable" | "shipping" | "none";

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
    /** A variation's `parent` means "same as the product". */
    tax_class: string;
    /** Only the base row's value is used; WooCommerce reads it from the parent too. */
    tax_status: TaxStatus;
    description: string | null;
    /** Nothing to ship: no shipping address or rate at checkout. */
    virtual: boolean;
    /** Sells the files in `downloads` once the order is paid (ADR-058). */
    downloadable: boolean;
    downloads: Downloads;
}

export interface DownloadFile {
    /** Stable id the order permissions point at; empty for a new file. */
    id: string;
    name: string;
    /** A URL or an absolute path on the server. */
    file: string;
}

export interface Downloads {
    files: DownloadFile[];
    /** Downloads per bought unit; null for no limit. */
    limit: number | null;
    /** Days after payment; null for no expiry. */
    expiry_days: number | null;
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

export interface GalleryImage {
    id: number;
    thumb: string;
    alt: string;
}

export type VideoProvider = "youtube" | "vimeo" | "file";

/** Where the video sits in the gallery: before the product image, right after it, or after the gallery images. */
export type VideoPosition = "first" | "second" | "last";

export interface ProductVideo {
    provider: VideoProvider;
    url: string;
    attachment_id: number;
    position: VideoPosition;
}

export interface ProductData {
    id: number;
    type: ProductType;
    status: string;
    attributes: ProductAttribute[];
    base: VariantRow;
    variations: VariantRow[];
    gallery_ids: number[];
    /** The gallery images with thumbnails, in order: what the editor shows and sends back. */
    gallery: GalleryImage[];
    video: ProductVideo | null;
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

/** How the product page shows an attribute; "" is the dropdown. */
export type SwatchType = "" | "color" | "image" | "button";

export interface AttributeValueTerm extends AttributeTerm {
    /** `#rrggbb`, or "" when none is set. */
    color: string;
    image_id: number;
    /** Thumbnail URL of `image_id`, "" when none. */
    image_url: string;
}

export interface StoreAttribute extends AttributeTerm {
    swatch: SwatchType;
    values: AttributeValueTerm[];
    usage: number;
}
