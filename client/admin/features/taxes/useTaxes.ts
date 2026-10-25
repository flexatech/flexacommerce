import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";
import type { Country } from "../shipping/useShipping";

/** A row of `admin/taxes/rates`. The rate is a percent string ("7.25"). */
export interface TaxRateRow {
    id: number;
    tax_class: string;
    country: string;
    state: string;
    rate: string;
    name: string;
    priority: number;
    compound: boolean;
    shipping: boolean;
    rate_order: number;
    postcodes: string[];
    cities: string[];
    /** `manual`, `import` or `preset:{id}`. */
    source: string;
}

export interface TaxClassSummary {
    slug: string;
    name: string;
    rates: number;
}

export interface PresetRegion {
    state: string;
    name: string;
    rate: string | null;
    shipping: boolean;
    source: string;
}

export interface TaxPreset {
    id: string;
    name: string;
    country: string;
    as_of: string;
    regions: PresetRegion[];
    settings: Record<string, unknown>;
    market: TaxMarket | null;
    origin_states: string[];
    notes: string;
}

export interface TaxMarket {
    id: string;
    name: string;
    countries: string[];
    overrides: Record<string, unknown>;
}

export interface TaxOverview {
    classes: TaxClassSummary[];
    presets: TaxPreset[];
    countries: Country[];
    batch: number;
    csv_header: string[];
}

export interface PresetPreview {
    add: TaxRateRow[];
    remove: TaxRateRow[];
    settings: Record<string, unknown>;
    market: TaxMarket | null;
}

export interface ImportResult {
    written: number;
    valid: number;
    errors: { line: number; message: string }[];
}

export const TAXES_KEY = ["taxes"] as const;

export function useTaxOverview() {
    return useQuery({
        queryKey: TAXES_KEY,
        queryFn: () => api.get<TaxOverview>("admin/taxes"),
    });
}

export const PER_PAGE = 100;

export function useTaxRates(taxClass: string, search: string, page: number) {
    return useQuery({
        queryKey: [...TAXES_KEY, "rates", taxClass, search, page],
        queryFn: () =>
            api.get<{ rows: TaxRateRow[]; total: number }>("admin/taxes/rates", {
                tax_class: taxClass,
                search,
                page,
                per_page: PER_PAGE,
            }),
        placeholderData: keepPreviousData,
    });
}

/** Everything under ["taxes"]: the class counts and every rate page. */
export function useRefreshTaxes() {
    const qc = useQueryClient();
    return () => qc.invalidateQueries({ queryKey: TAXES_KEY });
}

export function useSaveRates() {
    const refresh = useRefreshTaxes();
    return useMutation({
        mutationFn: (input: { rows: Record<string, unknown>[]; deleted: number[] }) =>
            api.put<{ saved: number; deleted: number }>("admin/taxes/rates", input),
        onSettled: () => void refresh(),
    });
}

export function importBatch(input: {
    rows: string[][];
    mode: "add" | "replace";
    tax_class: string;
    offset: number;
    /** File line of the batch's first row, for error messages. */
    first_line: number;
    validate_only?: boolean;
    start?: boolean;
    last?: boolean;
}) {
    return api.post<ImportResult>("admin/taxes/import", input);
}

export function exportRates(taxClass: string | null) {
    return api.get<{ filename: string; csv: string }>("admin/taxes/export", taxClass === null ? {} : { tax_class: taxClass });
}

export function previewPreset(id: string, states: string[]) {
    return api.post<PresetPreview>(`admin/taxes/presets/${id}`, { states });
}

export function useApplyPreset() {
    const qc = useQueryClient();
    const refresh = useRefreshTaxes();
    return useMutation({
        mutationFn: (input: { id: string; states: string[]; settings: string[] }) =>
            api.post<{ written: number }>(`admin/taxes/presets/${input.id}/apply`, { states: input.states, settings: input.settings }),
        onSettled: () => {
            void refresh();
            // A preset can change the tax options and markets too.
            void qc.invalidateQueries({ queryKey: ["settings"] });
        },
    });
}
