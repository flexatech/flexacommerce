import { useCallback, useEffect, useState } from "react";

/** The order being viewed lives in `?order=` so the page can be reloaded or shared. */
function readOrderId(): number {
    const id = Number.parseInt(new URLSearchParams(window.location.search).get("order") ?? "", 10);
    return Number.isFinite(id) && id > 0 ? id : 0;
}

export function orderUrl(id: number): string {
    const url = new URL(window.location.href);
    if (id > 0) {
        url.searchParams.set("order", String(id));
    } else {
        url.searchParams.delete("order");
    }
    return url.toString();
}

export function useOrderId(): [number, (id: number) => void] {
    const [id, setId] = useState(readOrderId);

    useEffect(() => {
        const onPop = () => setId(readOrderId());
        window.addEventListener("popstate", onPop);
        return () => window.removeEventListener("popstate", onPop);
    }, []);

    const open = useCallback((next: number) => {
        window.history.pushState(null, "", orderUrl(next));
        setId(next);
        window.scrollTo({ top: 0 });
    }, []);

    return [id, open];
}
