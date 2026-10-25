import { useEffect, useState } from "react";

import { Input } from "@/shared/components/ui/input";
import { inputToMinor, minorToInput } from "@/shared/lib/units";

interface Props {
    id?: string;
    value: number;
    exponent: number;
    onChange: (minor: number) => void;
    className?: string;
    "aria-label"?: string;
}

/** Typed as a decimal; reported as minor units. Unparseable text reports NaN. */
export function MoneyInput({ id, value, exponent, onChange, className, ...rest }: Props) {
    const [text, setText] = useState(() => minorToInput(value, exponent));

    useEffect(() => {
        setText((current) => (inputToMinor(current, exponent) === value ? current : minorToInput(value, exponent)));
    }, [value, exponent]);

    return (
        <Input
            id={id}
            inputMode="decimal"
            className={className}
            value={text}
            aria-label={rest["aria-label"]}
            onChange={(e) => {
                setText(e.target.value);
                const minor = inputToMinor(e.target.value, exponent);
                onChange(minor === null ? 0 : minor);
            }}
        />
    );
}
