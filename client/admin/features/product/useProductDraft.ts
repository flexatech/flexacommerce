import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { ApiError } from "@/shared/lib/api";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { attributesSignature, toPayload, type Draft } from "./draft";
import type { FieldErrors } from "./RowFields";
import { useGenerateVariants, useProduct, useSaveProduct } from "./useProduct";

export type Tab = "general" | "inventory" | "shipping" | "attributes" | "variations";

function errorsFrom(error: unknown): FieldErrors {
    if (error instanceof ApiError && typeof error.data.field === "string" && error.data.field !== "") {
        return { [error.data.field]: error.message };
    }
    return {};
}

/** The tab that holds the field a server error points at. */
function tabFor(field: string): Tab | null {
    if (field.startsWith("variations")) {
        return "variations";
    }
    if (field.startsWith("attributes")) {
        return "attributes";
    }
    if (/^base\.(sku|manage_stock|stock_|backorders|low_stock)/.test(field)) {
        return "inventory";
    }
    if (/^base\.(weight|length|width|height)/.test(field)) {
        return "shipping";
    }
    return field.startsWith("base") || field === "type" ? "general" : null;
}

/** Where save errors go: the block editor's notices in the metabox, a toast elsewhere. */
export type ErrorReporter = (message: string) => void;

const toastError: ErrorReporter = (message) => showToast.error(message);

/**
 * The product data draft and its save, without any UI. The metabox host
 * (ProductEditor) and a standalone editor app share it, so both send the
 * same payload and map server errors to the same fields and tabs.
 */
export function useProductDraft(productId: number, reportError: ErrorReporter = toastError) {
    const query = useProduct(productId);
    const save = useSaveProduct(productId);
    const generate = useGenerateVariants(productId);

    const [draft, setDraft] = useState<Draft | null>(null);
    const [tab, setTab] = useState<Tab>("general");
    const [errors, setErrors] = useState<FieldErrors>({});
    const [invalid, setInvalid] = useState<Set<string>>(new Set());
    const draftRef = useRef<Draft | null>(null);
    draftRef.current = draft;
    const savedRef = useRef(query.data);
    savedRef.current = query.data;
    const reportRef = useRef(reportError);
    reportRef.current = reportError;

    useEffect(() => {
        if (query.data) {
            setDraft(query.data);
        }
    }, [query.data]);

    const reportInvalid = useCallback((key: string, isInvalid: boolean) => {
        setInvalid((current) => {
            if (current.has(key) === isInvalid) {
                return current;
            }
            const next = new Set(current);
            if (isInvalid) {
                next.add(key);
            } else {
                next.delete(key);
            }
            return next;
        });
    }, []);
    const invalidRef = useRef(invalid);
    invalidRef.current = invalid;

    const attributesStale = useMemo(
        () => Boolean(draft && query.data && attributesSignature(draft.attributes) !== attributesSignature(query.data.attributes)),
        [draft, query.data],
    );

    const submit = useCallback(async (): Promise<boolean> => {
        const current = draftRef.current;
        if (!current) {
            return true;
        }
        if (invalidRef.current.size > 0) {
            reportRef.current(__("Product data was not saved: fix the highlighted fields first."));
            return false;
        }
        try {
            await save.mutateAsync(toPayload(current, savedRef.current));
            setErrors({});
            return true;
        } catch (error) {
            const fieldErrors = errorsFrom(error);
            setErrors(fieldErrors);
            const field = Object.keys(fieldErrors)[0];
            const target = field ? tabFor(field) : null;
            if (target) {
                setTab(target);
            }
            reportRef.current(sprintf(__("Product data was not saved: %s"), error instanceof Error ? error.message : String(error)));
            return false;
        }
    }, [save]);

    const runGenerate = useCallback(async () => {
        // Generation reads the stored product, so pending edits go first.
        if (!(await submit())) {
            return;
        }
        try {
            const result = await generate.mutateAsync({});
            showToast.success(
                result.limit_reached
                    ? __("The variation limit was reached; some combinations were not created.")
                    : sprintf(_n("%d variation created.", "%d variations created.", result.created), result.created),
            );
        } catch (error) {
            showToast.error(error instanceof Error ? error.message : String(error));
        }
    }, [submit, generate]);

    return {
        query,
        saved: query.data,
        draft,
        setDraft,
        tab,
        setTab,
        errors,
        reportInvalid,
        attributesStale,
        submit,
        runGenerate,
        saving: save.isPending,
        busy: save.isPending || generate.isPending,
    };
}

export type ProductDraftState = ReturnType<typeof useProductDraft>;
