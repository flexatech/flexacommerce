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

async function request<T>(
    method: "GET" | "POST" | "PATCH" | "DELETE",
    path: string,
    body?: Json,
    query?: Query,
): Promise<T> {
    const response = await fetch(buildUrl(path, query), {
        method,
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": getPluginGlobal().restNonce,
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (response.status === 204) {
        return undefined as T;
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

    return data as T;
}

export const api = {
    get: <T>(path: string, query?: Query) => request<T>("GET", path, undefined, query),
    post: <T>(path: string, body?: Json) => request<T>("POST", path, body),
    patch: <T>(path: string, body?: Json) => request<T>("PATCH", path, body),
    delete: <T>(path: string, body?: Json) => request<T>("DELETE", path, body),
};
