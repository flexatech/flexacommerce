import { Loader2, Search } from "lucide-react";
import { useEffect, useState, type KeyboardEvent } from "react";

import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";

export interface Row {
    key: string;
    label: string;
    hint: string;
    indent?: boolean;
}

interface Props {
    id: string;
    label: string;
    placeholder: string;
    text: string;
    onText: (text: string) => void;
    rows: Row[];
    fetching: boolean;
    onPick: (row: Row) => void;
    open: boolean;
    onOpen: (open: boolean) => void;
}

/** A search field whose results list picks one row at a time; the field clears after a pick. */
export function SearchBox({ id, label, placeholder, text, onText, rows, fetching, onPick, open, onOpen }: Props) {
    const [active, setActive] = useState(0);
    const listId = `${id}-list`;
    useEffect(() => setActive(0), [rows]);

    const pick = (row: Row) => {
        onPick(row);
        onText("");
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
            e.preventDefault();
            onOpen(true);
            if (rows.length > 0) {
                setActive((i) => (i + (e.key === "ArrowDown" ? 1 : rows.length - 1)) % rows.length);
            }
        } else if (e.key === "Enter") {
            e.preventDefault();
            if (open && rows[active]) {
                pick(rows[active]);
            }
        } else if (e.key === "Escape" && open) {
            e.preventDefault();
            onOpen(false);
        }
    };

    return (
        <div
            className="fxc:relative"
            onBlur={(e) => {
                if (!e.currentTarget.contains(e.relatedTarget as Node | null)) {
                    onOpen(false);
                }
            }}
        >
            <div className="flexacommerce-control fxc:border-input fxc:bg-background fxc:flex fxc:h-9 fxc:w-full fxc:items-center fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:shadow-sm">
                <Search className="fxc:text-muted-foreground fxc:size-4 fxc:shrink-0" aria-hidden />
                <input
                    id={id}
                    type="text"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={open && rows[active] ? `${listId}-${active}` : undefined}
                    autoComplete="off"
                    className="flexacommerce-bare-input fxc:text-foreground fxc:placeholder:text-muted-foreground fxc:h-7 fxc:min-w-0 fxc:flex-1 fxc:text-sm"
                    placeholder={placeholder}
                    value={text}
                    onChange={(e) => {
                        onText(e.target.value);
                        onOpen(true);
                    }}
                    onFocus={() => onOpen(true)}
                    onKeyDown={onKeyDown}
                />
            </div>
            {open && (
                <div className="fxc:border-border fxc:bg-background fxc:absolute fxc:inset-x-0 fxc:top-full fxc:z-10 fxc:mt-1 fxc:rounded-md fxc:border fxc:shadow-md">
                    <ul id={listId} role="listbox" className="fxc:max-h-60 fxc:overflow-y-auto fxc:py-1">
                        {rows.map((row, i) => (
                            <li
                                key={row.key}
                                id={`${listId}-${i}`}
                                role="option"
                                aria-selected={i === active}
                                className={cn(
                                    "fxc:flex fxc:cursor-pointer fxc:items-center fxc:gap-2 fxc:px-3 fxc:py-1.5 fxc:text-sm",
                                    row.indent && "fxc:ps-8",
                                    i === active && "fxc:bg-muted",
                                )}
                                onMouseEnter={() => setActive(i)}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => pick(row)}
                            >
                                <span className="fxc:min-w-0 fxc:flex-1 fxc:truncate">{row.label}</span>
                                {row.hint !== "" && <span className="fxc:text-muted-foreground fxc:shrink-0 fxc:text-xs">{row.hint}</span>}
                            </li>
                        ))}
                    </ul>
                    {rows.length === 0 && (
                        <p className="fxc:text-muted-foreground fxc:flex fxc:items-center fxc:gap-2 fxc:px-3 fxc:py-2 fxc:text-sm">
                            {fetching ? (
                                <>
                                    <Loader2 className="fxc:size-4 fxc:animate-spin" aria-hidden />
                                    {__("Searching…")}
                                </>
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
