import { useId, type ReactNode } from "react";
import { ArrowDown, ArrowUp, Lock, Plus, Trash2, X } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { CHOICE_TYPES, NO_PLACEHOLDER, type LayoutProblem } from "./layoutModel";
import { BY_COUNTRY, HELP_MAX, LABEL_MAX, LOCKED, MAX_CHOICES, type Answer, type CustomType, type LayoutEntry, type Section, type Width } from "./useCheckoutLayout";
import { CUSTOM_TYPES, SECTION_NAMES, typeName } from "./fieldTypes";
import { FieldConditions } from "./FieldConditions";

interface Props {
    entry: LayoutEntry;
    section: Section;
    /** The translated label of a core field, shown when the merchant leaves theirs empty. */
    defaultLabel: string;
    problem: LayoutProblem | null;
    /** The countries a custom field can be limited to. */
    countries: { code: string; name: string }[];
    /** A field added from the palette: its label gets the focus. */
    fresh: boolean;
    canUp: boolean;
    canDown: boolean;
    onChange: (patch: Partial<LayoutEntry>) => void;
    onMove: (delta: -1 | 1) => void;
    onSection: (section: Section) => void;
    onDelete: () => void;
}

export function Choice<T extends string>({ legend, value, options, onChange }: { legend: string; value: T; options: { value: T; label: string; help?: string }[]; onChange: (value: T) => void }) {
    const name = useId();
    return (
        <fieldset>
            <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{legend}</legend>
            <div className="fxc:space-y-1.5">
                {options.map((option) => (
                    <label
                        key={option.value}
                        className={cn(
                            "fxc:flex fxc:cursor-pointer fxc:items-start fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-sm",
                            value === option.value && "fxc:border-primary fxc:bg-muted/50",
                        )}
                    >
                        <input type="radio" name={name} checked={value === option.value} onChange={() => onChange(option.value)} className="flexacommerce-check fxc:mt-0.5" />
                        <span>
                            <span className="fxc:block">{option.label}</span>
                            {option.help && <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{option.help}</span>}
                        </span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

export function Row({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: ReactNode }) {
    return (
        <div>
            <Label htmlFor={id} className="fxc:mb-1.5 fxc:block">
                {label}
            </Label>
            {children}
            {error ? (
                <p id={`${id}-error`} className="fxc:text-destructive fxc:mt-1 fxc:text-xs">
                    {error}
                </p>
            ) : (
                hint && <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{hint}</p>
            )}
        </div>
    );
}

export function FieldInspector({ entry, section, defaultLabel, problem, countries, fresh, canUp, canDown, onChange, onMove, onSection, onDelete }: Props) {
    const id = useId();
    const type = entry.type;
    const locked = LOCKED.includes(entry.id);
    const byCountry = BY_COUNTRY.includes(entry.id);
    const note = entry.id === "customer_note";
    const choices = type !== undefined && CHOICE_TYPES.includes(type);
    const placeholder = type ? !NO_PLACEHOLDER.includes(type) : entry.id !== "country";
    const width = type !== "textarea" && !note;
    const error = (field: string) => (problem?.field === field ? problem.message : undefined);
    const title = entry.label.trim() || defaultLabel || __("New field");

    const answers: { value: Answer; label: string; help?: string }[] = byCountry
        ? [
              { value: "required", label: __("Shown"), help: __("Required when the customer's country uses it.") },
              { value: "hidden", label: __("Hidden") },
          ]
        : [
              { value: "required", label: __("Required") },
              { value: "optional", label: __("Optional") },
              { value: "hidden", label: __("Hidden"), help: entry.custom ? __("Kept here, not shown on the checkout.") : undefined },
          ];

    const setType = (next: CustomType) =>
        onChange({
            type: next,
            options: CHOICE_TYPES.includes(next) && (entry.options ?? []).length === 0 ? [{ value: "", label: "" }, { value: "", label: "" }] : entry.options,
            width: next === "textarea" ? "full" : entry.width,
            placeholder: NO_PLACEHOLDER.includes(next) ? "" : entry.placeholder,
        });

    const options = entry.options ?? [];
    const setOption = (index: number, label: string) => onChange({ options: options.map((o, i) => (i === index ? { ...o, label } : o)) });

    return (
        <div className="fxc:space-y-5">
            <div>
                <h2 className="fxc:truncate fxc:text-base fxc:font-semibold">{title}</h2>
                <p className="fxc:text-muted-foreground fxc:text-xs">
                    {entry.custom ? sprintf(__("%1$s in %2$s"), typeName(type), SECTION_NAMES[section]) : sprintf(__("Address field in %s"), SECTION_NAMES[section])}
                </p>
            </div>

            {entry.custom && (
                <Row id={`${id}-type`} label={__("Type")}>
                    <Select
                        id={`${id}-type`}
                        className="fxc:w-full"
                        value={type}
                        onChange={(e) => setType(e.target.value as CustomType)}
                        options={CUSTOM_TYPES.map((t) => ({ value: t.value, label: t.label }))}
                    />
                </Row>
            )}

            <Row
                id={`${id}-label`}
                label={__("Label")}
                hint={entry.custom ? undefined : __("Leave empty to use the default label, translated with the store language.")}
                error={error("label")}
            >
                <Input
                    id={`${id}-label`}
                    autoFocus={fresh}
                    maxLength={LABEL_MAX}
                    value={entry.label}
                    placeholder={defaultLabel}
                    aria-invalid={error("label") ? true : undefined}
                    aria-describedby={error("label") ? `${id}-label-error` : undefined}
                    onChange={(e) => onChange({ label: e.target.value })}
                />
            </Row>

            {choices && (
                <fieldset>
                    <legend className="fxc:mb-1.5 fxc:text-sm fxc:font-medium">{__("Choices")}</legend>
                    <div className="fxc:space-y-2">
                        {options.map((option, index) => (
                            <div key={index} className="fxc:flex fxc:items-center fxc:gap-1">
                                <Input aria-label={sprintf(__("Choice %d"), index + 1)} maxLength={LABEL_MAX} value={option.label} onChange={(e) => setOption(index, e.target.value)} />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={sprintf(__("Remove choice %d"), index + 1)}
                                    disabled={options.length <= 1}
                                    onClick={() => onChange({ options: options.filter((_, i) => i !== index) })}
                                >
                                    <X aria-hidden className="fxc:size-4" />
                                </Button>
                            </div>
                        ))}
                        {error("options") && <p className="fxc:text-destructive fxc:text-xs">{error("options")}</p>}
                        <Button variant="outline" size="sm" disabled={options.length >= MAX_CHOICES} onClick={() => onChange({ options: [...options, { value: "", label: "" }] })}>
                            <Plus aria-hidden className="fxc:size-4" />
                            {__("Add choice")}
                        </Button>
                    </div>
                </fieldset>
            )}

            {placeholder && (
                <Row id={`${id}-placeholder`} label={__("Placeholder")} hint={__("Sample text inside the empty box.")} error={error("placeholder")}>
                    <Input id={`${id}-placeholder`} maxLength={LABEL_MAX} value={entry.placeholder} onChange={(e) => onChange({ placeholder: e.target.value })} />
                </Row>
            )}

            <Row id={`${id}-help`} label={__("Help text")} hint={__("Shown under the field.")} error={error("help")}>
                <Input id={`${id}-help`} maxLength={HELP_MAX} value={entry.help} onChange={(e) => onChange({ help: e.target.value })} />
            </Row>

            {width && (
                <Choice<Width>
                    legend={__("Width")}
                    value={entry.width}
                    onChange={(value) => onChange({ width: value })}
                    options={[
                        { value: "full", label: __("Full row") },
                        { value: "half", label: __("Half row"), help: __("Two half fields sit side by side. Phones show one per row.") },
                    ]}
                />
            )}

            {locked ? (
                <p className="fxc:text-muted-foreground fxc:flex fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-xs">
                    <Lock aria-hidden className="fxc:mt-0.5 fxc:size-3.5 fxc:shrink-0" />
                    {entry.id === "email" ? __("Always shown and required: the order confirmation is sent to it.") : __("Always shown and required: taxes and shipping depend on it.")}
                </p>
            ) : (
                <Choice<Answer> legend={__("Answer")} value={entry.answer} onChange={(value) => onChange({ answer: value })} options={answers} />
            )}

            {entry.custom && (
                <label className="fxc:flex fxc:items-start fxc:justify-between fxc:gap-3 fxc:text-sm">
                    <span>
                        <span className="fxc:block fxc:font-medium">{__("Show in emails")}</span>
                        <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{__("The answer always shows on the order screen.")}</span>
                    </span>
                    <Switch checked={entry.email !== false} onCheckedChange={(email) => onChange({ email })} />
                </label>
            )}

            {entry.custom && entry.answer !== "hidden" && (
                <FieldConditions when={entry.when} section={section} countries={countries} error={error("when")} onChange={(when) => onChange({ when })} />
            )}

            {section === "shipping" && entry.custom && (
                <p className="fxc:text-muted-foreground fxc:text-xs">{__("Shipping fields show only when the customer ticks “Ship to a different address”.")}</p>
            )}

            <div className="fxc:space-y-3 fxc:border-t fxc:pt-4">
                <div className="fxc:flex fxc:gap-2">
                    <Button variant="ghost" size="sm" disabled={!canUp} onClick={() => onMove(-1)}>
                        <ArrowUp aria-hidden className="fxc:size-4" />
                        {__("Move up")}
                    </Button>
                    <Button variant="ghost" size="sm" disabled={!canDown} onClick={() => onMove(1)}>
                        <ArrowDown aria-hidden className="fxc:size-4" />
                        {__("Move down")}
                    </Button>
                </div>
                {entry.custom && (
                    <>
                        <Row id={`${id}-section`} label={__("Section")}>
                            <Select
                                id={`${id}-section`}
                                className="fxc:w-full"
                                value={section}
                                onChange={(e) => onSection(e.target.value as Section)}
                                options={(Object.keys(SECTION_NAMES) as Section[]).map((s) => ({ value: s, label: SECTION_NAMES[s] }))}
                            />
                        </Row>
                        <div>
                            <Button variant="ghost" size="sm" className="fxc:text-destructive" onClick={onDelete}>
                                <Trash2 aria-hidden className="fxc:size-4" />
                                {__("Delete field")}
                            </Button>
                            <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{__("Answers on past orders stay.")}</p>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
