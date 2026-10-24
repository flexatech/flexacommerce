export interface TableStatus {
    name: string;
    exists: boolean;
    engine: string;
    rows: number;
}

export interface RequirementCheck {
    id: string;
    label: string;
    ok: boolean;
    detail: string;
}

export type DoctorStatus = "ok" | "warning" | "error";

export interface DoctorCheck {
    id: string;
    label: string;
    status: DoctorStatus;
    detail: string;
}

export interface StatusPayload {
    environment: {
        plugin_version: string;
        php_version: string;
        wp_version: string;
        db_server: string;
        multisite: boolean;
        woocommerce: boolean;
        api_version: string;
    };
    database: {
        installed_version: number;
        code_version: number;
        tables: TableStatus[];
    };
    requirements: RequirementCheck[];
    doctor: DoctorCheck[];
}

export type LogLevel = "debug" | "info" | "notice" | "warning" | "error" | "critical";

export interface LogEntry {
    id: number;
    level: LogLevel;
    channel: string;
    message: string;
    context: Record<string, unknown> | null;
    created_at: string;
}

export interface LogsPayload {
    items: LogEntry[];
    total: number;
    channels: string[];
}

export interface LogFilters {
    level: LogLevel | "";
    channel: string;
    search: string;
    page: number;
}
