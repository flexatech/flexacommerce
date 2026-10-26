import { Check, Loader2, X } from "lucide-react";
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from "react";

import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { useTargetLookup, useTargetSearch, type Target, type TargetKind, type TargetType } from "./useCoupons";

export interface Pick {
    type: TargetType;
    id: number;
}

const keyOf = (p: Pick) => `${p.type}:${p.id}`;

interface Props {
    id: string;
    kind: TargetKind;
    value: Pick[];
    onChange: (value: Pick[]) => void;
    placeholder: string;
    describedBy?: string;
    /** False lists whole products only, without their variations. */
    variants?: boolean;
}

/**
 * Search by name or SKU and pick several products, variations or
 * categories. Picks show as removable chips; the names of the ids a coupon
 * already holds are looked up once when the form opens.
 */
export function TargetPicker({ id, kind, value, onChange, placeholder, describedBy, variants = true }: Props) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [known, setKnown] = useState<Map<string, Target | null>>(() => new Map());
    const input = useRef<HTMLInputElement>(null);
    const search = useDebounced(text.trim(), 250);
    const results = useTargetSearch(kind, search, open);
    const rows = useMemo(
        () => (results.data ?? []).filter((row) => variants || row.type !== "variant"),
        [results.data, variants],
    );

    // Names of picks the screen has not seen (the ones the coupon came with); a pick from the results is known already.
    const unknown = value.filter((p) => !known.has(keyOf(p)));
    const lookup = useTargetLookup(unknown);
    const asked = unknown.map(keyOf).join(" ");
    useEffect(() => {
        if (!lookup.isSuccess || asked === "") {
            return;
        }
        setKnown((map) => {
            const next = new Map(map);
            for (const k of asked.split(" ")) {
                next.set(k, null);
            }
            for (const row of lookup.data) {
                next.set(keyOf(row), row);
            }
            return next;
        });
    }, [lookup.isSuccess, lookup.data, asked]);

    useEffect(() => setActive(0), [rows]);

    const picked = new Set(value.map(keyOf));
    const listId = `${id}-list`;

    const toggle = (row: Target) => {
        setKnown((map) => new Map(map).set(keyOf(row), row));
        const k = keyOf(row);
        onChange(picked.has(k) ? value.filter((p) => keyOf(p) !== k) : [...value, { type: row.type, id: row.id }]);
    };

    const remove = (pick: Pick) => onChange(value.filter((p) => keyOf(p) !== keyOf(pick)));

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
            e.preventDefault();
            setOpen(true);
            if (rows.length > 0) {
                setActive((i) => (i + (e.key === "ArrowDown" ? 1 : rows.length - 1)) % rows.length);
            }
        } else if (e.key === "Enter") {
            // Enter picks a row; it never submits the coupon form.
            e.preventDefault();
            if (open && rows[active]) {
                toggle(rows[active]);
            }
        } else if (e.key === "Escape" && open) {
            // Close the list, not the dialog.
            e.preventDefault();
            e.stopPropagation();
            setOpen(false);
        } else if (e.key === "Backspace" && text === "" && value.length > 0) {
            remove(value[value.length - 1]);
        }
    };

    const label = (pick: Pick): string => {
        const row = known.get(keyOf(pick));
        if (row) {
            return row.label;
        }
        if (row === null) {
            return sprintf(__("#%d (removed)"), pick.id);
        }
        return lookup.isError ? `#${pick.id}` : __("Loading…");
    };

    return (
        <div
            className="fxc:relative"
            onBlur={(e) => {
                if (!e.currentTarget.contains(e.relatedTarget as Node | null)) {
                    setOpen(false);
                }
            }}
        >
            <div
                className="flexacommerce-control flexacommerce-picker fxc:border-input fxc:bg-background fxc:flex fxc:min-h-9 fxc:w-full fxc:cursor-text fxc:flex-wrap fxc:items-center fxc:gap-1 fxc:rounded-md fxc:border fxc:shadow-sm"
                onClick={() => input.current?.focus()}
            >
                {value.map((pick) => (
                    <span
                        key={keyOf(pick)}
                        className={cn(
                            "fxc:bg-muted fxc:text-foreground fxc:inline-flex fxc:max-w-full fxc:items-center fxc:gap-1 fxc:rounded fxc:py-0.5 fxc:ps-2 fxc:pe-1 fxc:text-xs",
                            known.get(keyOf(pick)) === null && "fxc:text-muted-foreground fxc:line-through",
                        )}
                    >
                        <span className="fxc:truncate">{label(pick)}</span>
                        <button
                            type="button"
                            className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:rounded fxc:p-0.5 fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:outline-none"
                            aria-label={sprintf(__("Remove %s"), label(pick))}
                            onClick={(e) => {
                                e.stopPropagation();
                                remove(pick);
                            }}
                        >
                            <X className="fxc:size-3" aria-hidden />
                        </button>
                    </span>
                ))}
                <input
                    ref={input}
                    id={id}
                    type="text"
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={open && rows[active] ? `${listId}-${active}` : undefined}
                    aria-describedby={describedBy}
                    autoComplete="off"
                    className="flexacommerce-bare-input fxc:text-foreground fxc:placeholder:text-muted-foreground fxc:h-7 fxc:min-w-32 fxc:flex-1 fxc:text-sm"
                    placeholder={value.length === 0 ? placeholder : ""}
                    value={text}
                    onChange={(e) => {
                        setText(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                />
            </div>
            {open && (
                <div className="fxc:border-border fxc:bg-background fxc:absolute fxc:inset-x-0 fxc:top-full fxc:z-10 fxc:mt-1 fxc:rounded-md fxc:border fxc:shadow-md">
                    <ul id={listId} role="listbox" aria-multiselectable="true" className="fxc:max-h-60 fxc:overflow-y-auto fxc:py-1">
                        {rows.map((row, i) => {
                            const on = picked.has(keyOf(row));
                            return (
                                <li
                                    key={keyOf(row)}
                                    id={`${listId}-${i}`}
                                    role="option"
                                    aria-selected={on}
                                    className={cn(
                                        "fxc:flex fxc:cursor-pointer fxc:items-center fxc:gap-2 fxc:px-3 fxc:py-1.5 fxc:text-sm",
                                        row.type === "variant" && "fxc:ps-8",
                                        i === active && "fxc:bg-muted",
                                    )}
                                    onMouseEnter={() => setActive(i)}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => toggle(row)}
                                >
                                    <Check className={cn("fxc:size-4 fxc:shrink-0", !on && "fxc:invisible")} aria-hidden />
                                    <span className="fxc:min-w-0 fxc:flex-1 fxc:truncate">{row.label}</span>
                                    {row.sku !== "" && <span className="fxc:text-muted-foreground fxc:shrink-0 fxc:text-xs">{row.sku}</span>}
                                </li>
                            );
                        })}
                    </ul>
                    {rows.length === 0 && (
                        <p className="fxc:text-muted-foreground fxc:flex fxc:items-center fxc:gap-2 fxc:px-3 fxc:py-2 fxc:text-sm">
                            {results.isFetching ? (
                                <>
                                    <Loader2 className="fxc:size-4 fxc:animate-spin" aria-hidden />
                                    {__("Searching…")}
                                </>
                            ) : results.isError ? (
                                results.error.message
                            ) : (
                                __("Nothing found.")
                            )}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
