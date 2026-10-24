import { useEffect, useRef } from "react";

interface EditorSelectors {
    isSavingPost(): boolean;
    isAutosavingPost(): boolean;
}

interface WpData {
    select(store: "core/editor"): EditorSelectors | undefined;
    subscribe(listener: () => void): () => void;
    dispatch(store: "core/notices"): { createErrorNotice(message: string, options?: Record<string, unknown>): void } | undefined;
}

declare global {
    interface WpGlobal {
        data?: WpData;
    }
}

/**
 * Runs `save` whenever the post itself is saved, so one click on Update or
 * Publish stores the product data too.
 *
 * Block editor: watches `core/editor` for a non-autosave save starting.
 * Classic editor: holds back the form submit until `save` resolves, then
 * submits again with the same button so WordPress sees publish or draft.
 */
export function useSaveWithPost(save: () => Promise<boolean>) {
    const latest = useRef(save);
    latest.current = save;

    useEffect(() => {
        const data = window.wp?.data;
        const editor = data?.select("core/editor");
        if (data && editor && typeof editor.isSavingPost === "function") {
            let wasSaving = false;
            return data.subscribe(() => {
                const saving = editor.isSavingPost() && !editor.isAutosavingPost();
                if (saving && !wasSaving) {
                    void latest.current();
                }
                wasSaving = saving;
            });
        }

        const form = document.getElementById("post");
        if (!(form instanceof HTMLFormElement)) {
            return undefined;
        }
        let passThrough = false;
        const onSubmit = (event: SubmitEvent) => {
            if (passThrough) {
                return;
            }
            event.preventDefault();
            const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
            void latest.current().then((ok) => {
                if (!ok) {
                    return;
                }
                passThrough = true;
                form.requestSubmit(submitter as HTMLButtonElement | HTMLInputElement | null);
            });
        };
        form.addEventListener("submit", onSubmit);
        return () => form.removeEventListener("submit", onSubmit);
    }, []);
}

export function editorErrorNotice(message: string): boolean {
    const notices = window.wp?.data?.dispatch("core/notices");
    if (!notices) {
        return false;
    }
    notices.createErrorNotice(message, { id: "flexacommerce-product-save", isDismissible: true });
    return true;
}
