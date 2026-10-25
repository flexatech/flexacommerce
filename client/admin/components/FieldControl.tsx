import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { Textarea } from "@/shared/components/ui/textarea";
import { getPluginGlobal } from "@/shared/lib/api";
import { __ } from "@/shared/lib/i18n";
import { MoneyInput } from "./MoneyInput";

/** A setting declared by a gateway or shipping method (PHP SettingField::to_array). */
export interface SettingField {
    id: string;
    type: "text" | "textarea" | "secret" | "checkbox" | "select" | "number" | "money";
    label: string;
    default: unknown;
    description: string;
    /** value => label; PHP sends [] when there are none. */
    options: Record<string, string> | [];
}

export type FieldValues = Record<string, unknown>;

interface Props {
    field: SettingField;
    id: string;
    value: unknown;
    onChange: (value: unknown) => void;
}

export function FieldControl({ field, id, value, onChange }: Props) {
    switch (field.type) {
        case "checkbox":
            return <Switch aria-label={field.label} checked={value === true} onCheckedChange={onChange} />;
        case "textarea":
            return <Textarea id={id} value={typeof value === "string" ? value : ""} onChange={(e) => onChange(e.target.value)} />;
        case "select":
            return (
                <Select
                    id={id}
                    value={typeof value === "string" ? value : ""}
                    onChange={(e) => onChange(e.target.value)}
                    options={Object.entries(field.options).map(([key, label]) => ({ value: key, label }))}
                />
            );
        case "number":
            return (
                <Input
                    id={id}
                    type="number"
                    className="fxc:w-32"
                    value={typeof value === "number" ? value : ""}
                    onChange={(e) => onChange(e.target.value === "" ? "" : Number(e.target.value))}
                />
            );
        case "money":
            return (
                <MoneyInput
                    id={id}
                    className="fxc:w-32"
                    value={typeof value === "number" ? value : 0}
                    exponent={getPluginGlobal().currency.exponent}
                    onChange={onChange}
                />
            );
        case "secret":
            return (
                <Input
                    id={id}
                    type={typeof value === "string" && value.startsWith("••••") ? "text" : "password"}
                    autoComplete="new-password"
                    placeholder={__("Not set")}
                    value={typeof value === "string" ? value : ""}
                    onFocus={(e) => e.target.select()}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
        default:
            return <Input id={id} value={typeof value === "string" ? value : ""} onChange={(e) => onChange(e.target.value)} />;
    }
}

/** Label above the control, description under it. */
export function FieldRow({ field, id, value, onChange }: Props) {
    const inline = field.type === "checkbox";
    return (
        <div className={inline ? "fxc:flex fxc:items-start fxc:justify-between fxc:gap-4" : "fxc:space-y-1.5"}>
            <div>
                <label htmlFor={id} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                    {field.label}
                </label>
                {inline && field.description !== "" && <p className="fxc:text-muted-foreground fxc:text-xs">{field.description}</p>}
            </div>
            <FieldControl field={field} id={id} value={value} onChange={onChange} />
            {!inline && field.description !== "" && <p className="fxc:text-muted-foreground fxc:text-xs">{field.description}</p>}
        </div>
    );
}
