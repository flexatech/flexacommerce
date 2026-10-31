import { useCallback, useEffect, useState } from "react";

/** `?order=new` opens the editor for a new order (ADR-061). */
export const NEW_ORDER = -1;

export interface OrderRoute {
    /** 0 for the list, NEW_ORDER for a new order. */
    id: number;
    /** `&edit=1`: the editor for an existing order. */
    edit: boolean;
}

/** The order being viewed lives in `?order=` so the page can be reloaded or shared. */
function readRoute(): OrderRoute {
    const query = new URLSearchParams(window.location.search);
    const raw = query.get("order") ?? "";
    if (raw === "new") {
        return { id: NEW_ORDER, edit: true };
    }
    const id = Number.parseInt(raw, 10);
    return Number.isFinite(id) && id > 0 ? { id, edit: query.get("edit") === "1" } : { id: 0, edit: false };
}

export function orderUrl(id: number, edit = false): string {
    const url = new URL(window.location.href);
    url.searchParams.delete("edit");
    if (id === NEW_ORDER) {
        url.searchParams.set("order", "new");
    } else if (id > 0) {
        url.searchParams.set("order", String(id));
        if (edit) {
            url.searchParams.set("edit", "1");
        }
    } else {
        url.searchParams.delete("order");
    }
    return url.toString();
}

export function useOrderRoute(): [OrderRoute, (id: number, edit?: boolean) => void] {
    const [route, setRoute] = useState(readRoute);

    useEffect(() => {
        const onPop = () => setRoute(readRoute());
        window.addEventListener("popstate", onPop);
        return () => window.removeEventListener("popstate", onPop);
    }, []);

    const open = useCallback((id: number, edit = false) => {
        window.history.pushState(null, "", orderUrl(id, edit));
        setRoute({ id, edit: id === NEW_ORDER || edit });
        window.scrollTo({ top: 0 });
    }, []);

    return [route, open];
}
