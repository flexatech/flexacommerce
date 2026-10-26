import { useEffect, useState } from "react";

/**
 * A tab kept in the `tab` query arg, so a link or a reload opens the same
 * tab and the back button steps through them. The first tab has no arg.
 */
export function useUrlTab<T extends string>(tabs: readonly T[]): [T, (next: T) => void] {
    const read = (): T => {
        const tab = new URLSearchParams(window.location.search).get("tab");
        return tabs.find((t) => t === tab) ?? tabs[0];
    };
    const [tab, setTab] = useState<T>(read);

    useEffect(() => {
        const onPop = () => setTab(read());
        window.addEventListener("popstate", onPop);
        return () => window.removeEventListener("popstate", onPop);
    }, []);

    const select = (next: T) => {
        const url = new URL(window.location.href);
        if (next === tabs[0]) {
            url.searchParams.delete("tab");
        } else {
            url.searchParams.set("tab", next);
        }
        window.history.pushState(null, "", url.toString());
        setTab(next);
    };
    return [tab, select];
}
