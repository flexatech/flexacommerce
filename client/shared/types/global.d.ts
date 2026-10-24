/**
 * The `flexaCommerce` global published by Admin\Enqueue through
 * wp_localize_script.
 */

export type AppTheme = "light" | "dark";

export type AdminPage = "flexacommerce" | "flexacommerce-settings";

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
}

declare global {
    interface Window {
        flexaCommerce?: FlexaCommerceGlobal;
    }
}
