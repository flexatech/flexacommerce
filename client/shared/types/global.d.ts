/**
 * The `flexaCommerce` global published by Admin\Enqueue through
 * wp_localize_script.
 */

export type AppTheme = "light" | "dark";

export type AdminPage = "flexacommerce" | "flexacommerce-orders" | "flexacommerce-customers" | "flexacommerce-promotions" | "flexacommerce-settings" | "flexacommerce-attributes" | "flexacommerce-product-options" | "flexacommerce-migration" | "product-editor";

export type CurrencyPosition = "left" | "right" | "left_space" | "right_space";

export interface FlexaCommerceCurrency {
    code: string;
    symbol: string;
    exponent: number;
    position: CurrencyPosition;
    decimalSeparator: string;
    thousandSeparator: string;
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
    /** Product editor screen only. */
    productId?: number;
    canManageTerms?: boolean;
    attributesAdminUrl?: string;
    /** Standard (slug "") first, then the store's classes. */
    taxClasses?: { slug: string; name: string }[];
}

declare global {
    interface Window {
        flexaCommerce?: FlexaCommerceGlobal;
    }
}
