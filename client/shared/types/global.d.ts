/**
 * The `flexaCommerce` global published by Admin\Enqueue through
 * wp_localize_script.
 */

export type AppTheme = "light" | "dark";

export type AdminPage = "flexacommerce" | "flexacommerce-orders" | "flexacommerce-subscriptions" | "flexacommerce-customers" | "flexacommerce-recovery" | "flexacommerce-stock-alerts" | "flexacommerce-gift-cards" | "flexacommerce-promotions" | "flexacommerce-checkout" | "flexacommerce-settings" | "flexacommerce-attributes" | "flexacommerce-product-options" | "flexacommerce-migration" | "flexacommerce-product-csv" | "product-editor";

export type CurrencyPosition = "left" | "right" | "left_space" | "right_space";

export interface FlexaCommerceCurrency {
    code: string;
    symbol: string;
    exponent: number;
    position: CurrencyPosition;
    decimalSeparator: string;
    thousandSeparator: string;
}

/** One menu section and the pages in it the user may open (Admin\\Menu::sections). */
export interface NavSection {
    id: "orders" | "products" | "customers" | "marketing" | "settings";
    label: string;
    url: string;
    pages: { slug: string; label: string; url: string }[];
}

export interface FlexaCommerceNav {
    sections: NavSection[];
    /** [section id, page slug]; the page is "" on a screen with no tab of its own. */
    current: [string, string];
    storeUrl: string;
}

export interface FlexaCommerceGlobal {
    restUrl: string;
    restNonce: string;
    version: string;
    pluginUrl: string;
    adminUrl: string;
    page: AdminPage;
    locale: string;
    theme: AppTheme;
    currency: FlexaCommerceCurrency;
    units: {
        weight: "kg" | "g" | "lb" | "oz";
        dimension: "cm" | "mm" | "m" | "in" | "yd";
    };
    /** The app pages only, not the product editor. */
    nav?: FlexaCommerceNav;
    /** Product editor screen only. */
    productId?: number;
    canManageTerms?: boolean;
    attributesAdminUrl?: string;
    /** Standard (slug "") first, then the store's classes. */
    taxClasses?: { slug: string; name: string }[];
    /** The store's shipping classes (ADR-068), without "No shipping class". */
    shippingClasses?: { slug: string; name: string }[];
    shippingSettingsUrl?: string;
}

declare global {
    interface Window {
        flexaCommerce?: FlexaCommerceGlobal;
    }
}
