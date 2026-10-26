import type { FlexaCommerceGlobal } from "@/shared/types/global";

type Json = Record<string, unknown> | unknown[] | string | number | boolean | null;

type ErrorBody = { code?: unknown; message?: unknown; data?: unknown };

type Query = Record<string, string | number | undefined>;

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code?: string,
        /** Extra `data` of a WP_Error, e.g. `field` or `product_id`. */
        public readonly data: Record<string, unknown> = {},
    ) {
        super(message);
        this.name = "ApiError";
    }
}

export function getPluginGlobal(): FlexaCommerceGlobal {
    if (!window.flexaCommerce) {
        throw new Error("flexaCommerce global missing: make sure Enqueue ran before this script.");
    }
    return window.flexaCommerce;
}

function buildUrl(path: string, query?: Query): string {
    const { restUrl } = getPluginGlobal();
    const url = new URL(restUrl.replace(/\/$/, "") + "/" + path.replace(/^\//, ""), window.location.href);
    for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== undefined && value !== "") {
            url.searchParams.set(key, String(value));
        }
    }
    return url.toString();
}

async function send<T>(
    method: "GET" | "POST" | "PATCH" | "PUT" | "DELETE",
    path: string,
    body?: Json | Blob,
    query?: Query,
    signal?: AbortSignal,
): Promise<{ data: T; headers: Headers }> {
    const raw = body instanceof Blob;
    const response = await fetch(buildUrl(path, query), {
        method,
        credentials: "same-origin",
        headers: {
            "Content-Type": raw ? "application/octet-stream" : "application/json",
            "X-WP-Nonce": getPluginGlobal().restNonce,
        },
        body: raw ? body : body !== undefined ? JSON.stringify(body) : undefined,
        signal,
    });

    if (response.status === 204) {
        return { data: undefined as T, headers: response.headers };
    }

    const data = (await response.json().catch(() => null)) as ErrorBody | T | null;

    if (!response.ok) {
        const error = data !== null && typeof data === "object" ? (data as ErrorBody) : {};
        throw new ApiError(
            typeof error.message === "string" ? error.message : response.statusText,
            response.status,
            typeof error.code === "string" ? error.code : undefined,
            error.data !== null && typeof error.data === "object" ? (error.data as Record<string, unknown>) : {},
        );
    }

    return { data: data as T, headers: response.headers };
}

async function request<T>(
    method: "GET" | "POST" | "PATCH" | "PUT" | "DELETE",
    path: string,
    body?: Json,
    query?: Query,
): Promise<T> {
    return (await send<T>(method, path, body, query)).data;
}

export interface Paged<T> {
    items: T[];
    total: number;
    pages: number;
}

export const api = {
    /** A collection route that reports its size in X-WP-Total / X-WP-TotalPages. */
    list: async <T>(path: string, query?: Query): Promise<Paged<T>> => {
        const { data, headers } = await send<T[]>("GET", path, undefined, query);
        return {
            items: data,
            total: Number(headers.get("X-WP-Total") ?? data.length),
            pages: Math.max(1, Number(headers.get("X-WP-TotalPages") ?? 1)),
        };
    },
    get: <T>(path: string, query?: Query) => request<T>("GET", path, undefined, query),
    post: <T>(path: string, body?: Json) => request<T>("POST", path, body),
    /** POSTs a Blob as the raw request body (a file chunk). */
    postBlob: async <T>(path: string, blob: Blob, query?: Query, signal?: AbortSignal) =>
        (await send<T>("POST", path, blob, query, signal)).data,
    patch: <T>(path: string, body?: Json) => request<T>("PATCH", path, body),
    put: <T>(path: string, body?: Json) => request<T>("PUT", path, body),
    delete: <T>(path: string, body?: Json) => request<T>("DELETE", path, body),
};
