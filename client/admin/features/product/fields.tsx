import { createContext, useContext, useEffect, useId, useState, type ReactNode } from "react";

import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import {
    gramsToInput,
    inputToGrams,
    inputToMillimetres,
    inputToMinor,
    millimetresToInput,
    minorToInput,
} from "@/shared/lib/units";

export function Field({
    label,
    help,
    error,
    children,
    className,
}: {
    label: string;
    help?: string;
    error?: string;
    children: (id: string) => ReactNode;
    className?: string;
}) {
    const id = useId();
    return (
        <div className={cn("fxc:min-w-0", className)}>
            <Label htmlFor={id} className="fxc:mb-1.5 fxc:block">
                {label}
            </Label>
            {children(id)}
            {error ? (
                <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{error}</p>
            ) : (
                help && <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{help}</p>
            )}
        </div>
    );
}

/**
 * Inputs holding text that cannot be saved register here, so the editor can
 * refuse to save instead of silently sending the last good value.
 */
export const InvalidFields = createContext<(key: string, invalid: boolean) => void>(() => undefined);

/**
 * Text input that keeps what the merchant typed and reports a parsed value.
 * `parse` returns `false` for text that cannot be saved; the field then keeps
 * the last good value and shows an error.
 */
function ParsedInput<T>({
    id,
    value,
    format,
    parse,
    onChange,
    suffix,
    placeholder,
    invalidMessage,
}: {
    id: string;
    value: T;
    format: (value: T) => string;
    parse: (text: string) => T | false;
    onChange: (value: T) => void;
    suffix?: string;
    placeholder?: string;
    invalidMessage: string;
}) {
    const [text, setText] = useState(() => format(value));
    const [invalid, setInvalid] = useState(false);
    const key = useId();
    const report = useContext(InvalidFields);

    useEffect(() => {
        report(key, invalid);
    }, [invalid, key, report]);
    useEffect(() => () => report(key, false), [key, report]);

    // Follow outside changes (bulk edit, a reload after save) unless the text already means the same value.
    useEffect(() => {
        setText((current) => {
            const parsed = parse(current);
            return parsed !== false && parsed === value ? current : format(value);
        });
        setInvalid(false);
    }, [value]);

    const update = (next: string) => {
        setText(next);
        const parsed = parse(next);
        const ok = parsed !== false;
        setInvalid(!ok);
        if (ok && parsed !== value) {
            onChange(parsed);
        }
    };

    return (
        <div>
            <div className="fxc:relative">
                <Input
                    id={id}
                    inputMode="decimal"
                    value={text}
                    placeholder={placeholder}
                    aria-invalid={invalid || undefined}
                    onChange={(event) => update(event.target.value)}
                    className={cn(suffix && "fxc:pr-12", invalid && "fxc:border-destructive")}
                />
                {suffix && (
                    <span className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:inset-y-0 fxc:right-3 fxc:flex fxc:items-center fxc:text-xs">
                        {suffix}
                    </span>
                )}
            </div>
            {invalid && <p className="fxc:text-destructive fxc:mt-1 fxc:text-xs">{invalidMessage}</p>}
        </div>
    );
}

interface ValueProps<T> {
    id: string;
    value: T;
    onChange: (value: T) => void;
    placeholder?: string;
}

export function MoneyInput(props: ValueProps<number | null>) {
    const { currency } = getPluginGlobal();
    return (
        <ParsedInput
            {...props}
            suffix={currency.symbol}
            format={(minor) => minorToInput(minor, currency.exponent)}
            parse={(text) => {
                const minor = inputToMinor(text, currency.exponent);
                return minor !== null && Number.isNaN(minor) ? false : minor;
            }}
            invalidMessage={__("Enter an amount such as 19.99.")}
        />
    );
}

export function WeightInput(props: ValueProps<string | null>) {
    const { units } = getPluginGlobal();
    return (
        <ParsedInput
            {...props}
            suffix={units.weight}
            format={(grams) => gramsToInput(grams, units.weight)}
            parse={(text) => inputToGrams(text, units.weight)}
            invalidMessage={__("Enter a number.")}
        />
    );
}

export function DimensionInput(props: ValueProps<string | null>) {
    const { units } = getPluginGlobal();
    return (
        <ParsedInput
            {...props}
            suffix={units.dimension}
            format={(mm) => millimetresToInput(mm, units.dimension)}
            parse={(text) => inputToMillimetres(text, units.dimension)}
            invalidMessage={__("Enter a number.")}
        />
    );
}

export function IntInput({ allowNegative = false, ...props }: ValueProps<number | null> & { allowNegative?: boolean }) {
    return (
        <ParsedInput
            {...props}
            format={(n) => (n === null ? "" : String(n))}
            parse={(text) => {
                const clean = text.trim();
                if (clean === "") {
                    return null;
                }
                return (allowNegative ? /^-?\d{1,12}$/ : /^\d{1,12}$/).test(clean) ? Number(clean) : false;
            }}
            invalidMessage={allowNegative ? __("Enter a whole number.") : __("Enter a whole number of 0 or more.")}
        />
    );
}

export function Toggle({
    checked,
    onChange,
    label,
    help,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    help?: string;
}) {
    const id = useId();
    return (
        <div className="fxc:flex fxc:items-start fxc:gap-2">
            <input
                id={id}
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="flexacommerce-check fxc:mt-0.5"
            />
            <div>
                <label htmlFor={id} className="fxc:text-foreground fxc:text-sm fxc:font-medium">
                    {label}
                </label>
                {help && <p className="fxc:text-muted-foreground fxc:text-xs">{help}</p>}
            </div>
        </div>
    );
}
