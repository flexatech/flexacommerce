import { useMemo, useState } from "react";

import { SearchBox, type Row } from "../features/simulator/SearchBox";

export type { Row };

/** Rows shown at once; typing narrows a long list such as every country and state. */
const LIMIT = 100;

const fold = (text: string) => text.normalize("NFD").replace(/\p{M}/gu, "").toLowerCase();

interface Props {
    id: string;
    label: string;
    placeholder: string;
    /** Everything that can be picked, in the order shown before typing. */
    choices: Row[];
    onPick: (key: string) => void;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
}

/** A search field over a list the screen already has; names that start with the text come first. */
export function ListSearch({ id, label, placeholder, choices, onPick, disabled, invalid, describedBy }: Props) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);

    const rows = useMemo(() => {
        const query = fold(text.trim());
        if (query === "") {
            return choices.slice(0, LIMIT);
        }
        const starts: Row[] = [];
        const contains: Row[] = [];
        for (const row of choices) {
            const name = fold(row.label);
            if (name.startsWith(query)) {
                starts.push(row);
            } else if (name.includes(query) || fold(row.hint).includes(query)) {
                contains.push(row);
            }
        }
        return [...starts, ...contains].slice(0, LIMIT);
    }, [text, choices]);

    return (
        <SearchBox
            id={id}
            label={label}
            placeholder={placeholder}
            text={text}
            onText={setText}
            rows={rows}
            fetching={false}
            onPick={(row) => onPick(row.key)}
            open={open}
            onOpen={setOpen}
            disabled={disabled}
            invalid={invalid}
            describedBy={describedBy}
        />
    );
}
