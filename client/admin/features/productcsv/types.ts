export interface CsvLayout {
    attributes: number;
    downloads: number;
    meta: string[];
}

export interface ExportPage {
    lines: string;
    rows: number;
    total: number;
    pages: number;
    page: number;
    /** Page 1 only. */
    layout?: CsvLayout;
    header?: string;
    name?: string;
}

export interface MappedColumn {
    header: string;
    /** "" when the column is unknown; "unsupported:KEY" for a WooCommerce field FlexaCommerce lacks. */
    key: string;
    label: string;
}

export interface MapResponse {
    columns: MappedColumn[];
    fields: { key: string; label: string }[];
}

export type ImportAction = "create" | "update" | "skip" | "error";

export interface ImportResult {
    line: number;
    action: ImportAction;
    id: number | null;
    sku: string | null;
    name: string;
    message: string;
    warnings: string[];
}

export interface ImportSummary {
    created: number;
    updated: number;
    skipped: number;
    failed: number;
}
