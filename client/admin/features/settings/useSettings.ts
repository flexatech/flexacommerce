import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { CurrencyPosition } from "@/shared/types/global";
import type { LogLevel } from "../status/types";
import type { TaxMarket } from "../taxes/useTaxes";

export interface Settings {
    currency: string;
    currency_position: CurrencyPosition;
    thousand_separator: string;
    decimal_separator: string;
    order_number_prefix: string;
    migration_number_prefix: string;
    hold_minutes: number;
    max_line_quantity: number;
    store_customer_ip: boolean;
    checkout_paused: boolean;
    defer_emails: boolean;
    log_level: LogLevel;
    log_retention_days: number;
    remove_data_on_uninstall: boolean;
    tax_enabled: boolean;
    prices_include_tax: boolean;
    tax_based_on: "shipping" | "billing" | "base";
    tax_default_location: "base" | "none";
    /** A class slug, "" for Standard, or `inherit` (from the cart items). */
    shipping_tax_class: string;
    tax_round_at_subtotal: boolean;
    tax_classes: { slug: string; name: string }[];
    tax_display_shop: "excl" | "incl";
    tax_display_cart: "excl" | "incl";
    price_display_suffix: string;
    tax_total_display: "itemized" | "single";
    tax_markets: TaxMarket[];
    swatch_size: number;
    swatch_shape: "round" | "square";
    swatch_show_choice: boolean;
    swatch_unavailable: "dim" | "hide";
    swatch_custom: "dropdown" | "button";
    swatch_list: number;
    gallery_thumbs: "bottom" | "left" | "dots";
    gallery_aspect: "original" | "square" | "portrait" | "landscape";
    gallery_arrows: boolean;
    gallery_zoom: boolean;
    gallery_lightbox: boolean;
    /** 0 shows no countdown. */
    countdown_days: number;
    tier_table: boolean;
    tier_table_title: string;
}

export interface SettingsPayload {
    settings: Settings;
    currencies: string[];
}

const SETTINGS_KEY = ["settings"] as const;

export function useSettings() {
    return useQuery<SettingsPayload>({
        queryKey: SETTINGS_KEY,
        queryFn: () => api.get<SettingsPayload>("admin/settings"),
    });
}

/**
 * Sends only the changed fields. The server merges them over what is stored,
 * so a full payload could overwrite a change made in another tab.
 */
export function useSaveSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: Partial<Settings> & { confirm?: string }) =>
            api.patch<SettingsPayload>("admin/settings", input as Record<string, unknown>),
        onSuccess: (next) => qc.setQueryData(SETTINGS_KEY, next),
        onSettled: () => void qc.invalidateQueries({ queryKey: SETTINGS_KEY }),
    });
}
