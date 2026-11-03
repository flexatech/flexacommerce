import { useRef, type ReactNode } from "react";
import { AlignCenter, AlignLeft, AlignRight, Bold, ImageIcon, Italic, Link, Trash2 } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { Textarea } from "@/shared/components/ui/textarea";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import { canPickImage, pickImage } from "../../components/pickImage";
import { fontLabel } from "./emailBlocks";
import type { Align, DesignBlock, MailStyle } from "./useEmailDesign";

type Props = Record<string, string | number>;

function Field({ id, label, help, children, extra }: { id?: string; label: string; help?: ReactNode; children: ReactNode; extra?: ReactNode }) {
    return (
        <div className="fxc:space-y-1.5">
            <div className="fxc:flex fxc:items-center fxc:justify-between fxc:gap-2">
                <label htmlFor={id} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                    {label}
                </label>
                {extra}
            </div>
            {children}
            {help && <p className="fxc:text-muted-foreground fxc:text-xs">{help}</p>}
        </div>
    );
}

/** Radio pills; each option may carry an icon in place of its text. */
function Choice<T extends string>({ label, value, options, onChange }: { label: string; value: T; options: { value: T; label: string; icon?: ReactNode }[]; onChange: (value: T) => void }) {
    // A div around the fieldset: WP admin resets fieldset margins, which would undo the panel's spacing.
    return (
        <div>
        <fieldset>
            <legend className="fxc:text-foreground fxc:mb-1.5 fxc:text-sm fxc:font-medium">{label}</legend>
            <span className="fxc:bg-muted fxc:inline-flex fxc:rounded-md fxc:p-0.5">
                {options.map((option) => (
                    <label
                        key={option.value}
                        title={option.label}
                        className={cn(
                            "fxc:flex fxc:cursor-pointer fxc:items-center fxc:rounded fxc:px-2.5 fxc:py-1 fxc:text-xs",
                            value === option.value ? "fxc:bg-background fxc:text-foreground fxc:font-medium fxc:shadow-sm" : "fxc:text-muted-foreground",
                        )}
                    >
                        <input type="radio" className="fxc:sr-only" checked={value === option.value} onChange={() => onChange(option.value)} />
                        {option.icon ?? option.label}
                        {option.icon && <span className="fxc:sr-only">{option.label}</span>}
                    </label>
                ))}
            </span>
        </fieldset>
        </div>
    );
}

function AlignChoice({ value, onChange }: { value: string; onChange: (value: Align) => void }) {
    return (
        <Choice<Align>
            label={__("Alignment")}
            value={(["left", "center", "right"].includes(value) ? value : "left") as Align}
            onChange={onChange}
            options={[
                { value: "left", label: __("Left"), icon: <AlignLeft aria-hidden className="fxc:size-4" /> },
                { value: "center", label: __("Center"), icon: <AlignCenter aria-hidden className="fxc:size-4" /> },
                { value: "right", label: __("Right"), icon: <AlignRight aria-hidden className="fxc:size-4" /> },
            ]}
        />
    );
}

interface TokenFieldProps {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    tokens: string[];
    labels: Record<string, string>;
    multiline?: boolean;
    /** Bold, italic and link buttons for text that allows them. */
    formatting?: boolean;
    placeholder?: string;
    help?: ReactNode;
}

/** A text field with a menu that drops a value such as the customer's name in at the cursor. */
export function TokenField({ id, label, value, onChange, tokens, labels, multiline, formatting, placeholder, help }: TokenFieldProps) {
    const ref = useRef<HTMLInputElement & HTMLTextAreaElement>(null);

    /** Puts `before` and `after` around the selection, or `before` alone at the cursor. */
    const wrap = (before: string, after = "", caret?: number) => {
        const el = ref.current;
        const start = el?.selectionStart ?? value.length;
        const end = el?.selectionEnd ?? value.length;
        onChange(value.slice(0, start) + before + value.slice(start, end) + after + value.slice(end));
        window.requestAnimationFrame(() => {
            const at = caret !== undefined ? start + caret : end + before.length + after.length;
            el?.focus();
            el?.setSelectionRange(at, at);
        });
    };

    const insert = (
        <Select
            aria-label={__("Insert a value")}
            className="fxc:h-7 fxc:max-w-40 fxc:px-2 fxc:text-xs"
            value=""
            onChange={(e) => e.target.value !== "" && wrap(e.target.value)}
            options={[{ value: "", label: __("Insert value…") }, ...tokens.map((t) => ({ value: t, label: labels[t] ?? t }))]}
        />
    );

    return (
        <Field id={id} label={label} help={help} extra={insert}>
            {formatting && (
                <div className="fxc:flex fxc:gap-1">
                    <Button variant="ghost" size="sm" aria-label={__("Bold")} title={__("Bold")} onClick={() => wrap("<strong>", "</strong>")}>
                        <Bold aria-hidden />
                    </Button>
                    <Button variant="ghost" size="sm" aria-label={__("Italic")} title={__("Italic")} onClick={() => wrap("<em>", "</em>")}>
                        <Italic aria-hidden />
                    </Button>
                    <Button variant="ghost" size="sm" aria-label={__("Link")} title={__("Link")} onClick={() => wrap('<a href="https://">', "</a>", 17)}>
                        <Link aria-hidden />
                    </Button>
                </div>
            )}
            {multiline ? (
                <Textarea id={id} ref={ref} rows={5} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
            ) : (
                <Input id={id} ref={ref} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
            )}
        </Field>
    );
}

function NumberField({ id, label, value, min, max, onChange, help }: { id: string; label: string; value: number; min: number; max: number; onChange: (value: number) => void; help?: string }) {
    return (
        <Field id={id} label={label} help={help}>
            <div className="fxc:flex fxc:items-center fxc:gap-3">
                <input
                    id={id}
                    type="range"
                    min={min}
                    max={max}
                    value={value}
                    onChange={(e) => onChange(Number(e.target.value))}
                    className="fxc:accent-primary fxc:min-w-0 fxc:flex-1"
                />
                <span className="fxc:text-muted-foreground fxc:w-14 fxc:text-right fxc:text-xs fxc:tabular-nums">{value > 0 ? `${value} px` : __("Auto")}</span>
            </div>
        </Field>
    );
}

export function ColorField({ id, label, value, onChange, allowEmpty, emptyLabel }: { id: string; label: string; value: string; onChange: (value: string) => void; allowEmpty?: boolean; emptyLabel?: string }) {
    return (
        <Field id={id} label={label}>
            <div className="fxc:flex fxc:items-center fxc:gap-2">
                <input
                    type="color"
                    aria-label={label}
                    value={value !== "" ? value : "#18181b"}
                    onChange={(e) => onChange(e.target.value)}
                    className="fxc:h-9 fxc:w-10 fxc:shrink-0 fxc:cursor-pointer fxc:rounded-md fxc:border fxc:bg-transparent fxc:p-0.5"
                />
                <Input id={id} className="fxc:w-28 fxc:font-mono" value={value} placeholder={emptyLabel} maxLength={7} onChange={(e) => onChange(e.target.value.trim())} />
                {allowEmpty && value !== "" && (
                    <Button variant="ghost" size="sm" onClick={() => onChange("")}>
                        {emptyLabel}
                    </Button>
                )}
            </div>
        </Field>
    );
}

function ImagePicker({ url, label, onPick, onRemove }: { url: string; label: string; onPick: (image: { id: number; url: string; alt: string }) => void; onRemove: () => void }) {
    if (!canPickImage()) {
        return null;
    }
    return (
        <div className="fxc:space-y-2">
            {url !== "" ? (
                <div className="fxc:bg-muted fxc:flex fxc:items-center fxc:justify-center fxc:rounded-md fxc:border fxc:p-3">
                    <img src={url} alt="" className="fxc:max-h-24 fxc:max-w-full fxc:object-contain" />
                </div>
            ) : null}
            <div className="fxc:flex fxc:gap-2">
                <Button variant="outline" size="sm" onClick={() => pickImage(label, onPick, { full: true })}>
                    <ImageIcon aria-hidden />
                    {url !== "" ? __("Replace") : __("Choose image")}
                </Button>
                {url !== "" && (
                    <Button variant="ghost" size="sm" onClick={onRemove}>
                        <Trash2 aria-hidden />
                        {__("Remove")}
                    </Button>
                )}
            </div>
        </div>
    );
}

const LINKS = ["{order_url}", "{site_url}"];

/** A link field: the order page, the store, or an address the merchant types. */
function LinkField({ id, label, value, onChange, labels, allowNone }: { id: string; label: string; value: string; onChange: (value: string) => void; labels: Record<string, string>; allowNone?: boolean }) {
    const preset = value === "" && allowNone ? "" : LINKS.includes(value) ? value : "custom";
    return (
        <Field id={id} label={label}>
            <Select
                id={id}
                className="fxc:w-full"
                value={preset}
                onChange={(e) => onChange(e.target.value === "custom" ? "https://" : e.target.value)}
                options={[
                    ...(allowNone ? [{ value: "", label: __("No link") }] : []),
                    ...LINKS.map((t) => ({ value: t, label: labels[t] ?? t })),
                    { value: "custom", label: __("Another address…") },
                ]}
            />
            {preset === "custom" && <Input aria-label={__("Link address")} value={value} placeholder="https://" onChange={(e) => onChange(e.target.value)} />}
        </Field>
    );
}

interface BlockSettingsProps {
    block: DesignBlock;
    tokens: string[];
    labels: Record<string, string>;
    style: MailStyle;
    onChange: (props: Props) => void;
    onOpenStyle: () => void;
}

/** The settings of the selected block. */
export function BlockSettings({ block, tokens, labels, style, onChange, onOpenStyle }: BlockSettingsProps) {
    const p = block.props;
    const set = (key: string, value: string | number) => onChange({ ...p, [key]: value });
    const id = (key: string) => `fxc-block-${block.id}-${key}`;
    const str = (key: string) => String(p[key] ?? "");
    const num = (key: string) => Number(p[key] ?? 0);

    switch (block.type) {
        case "logo":
            return (
                <>
                    <div className="fxc:text-muted-foreground fxc:text-sm">
                        {style.logo_url !== "" ? __("Shows the logo set in Style.") : __("Shows your store name until you add a logo in Style.")}{" "}
                        <button type="button" className="fxc:text-primary fxc:cursor-pointer fxc:bg-transparent fxc:p-0 fxc:underline" onClick={onOpenStyle}>
                            {__("Open Style")}
                        </button>
                    </div>
                    <AlignChoice value={str("align")} onChange={(v) => set("align", v)} />
                    {style.logo_url !== "" && (
                        <NumberField id={id("width")} label={__("Width")} value={num("width")} min={0} max={600} onChange={(v) => set("width", v)} help={__("Auto uses the width set in Style.")} />
                    )}
                </>
            );
        case "heading":
            return (
                <>
                    <TokenField
                        id={id("text")}
                        label={__("Text")}
                        value={str("text")}
                        onChange={(v) => set("text", v)}
                        tokens={tokens}
                        labels={labels}
                        help={__("Values in braces, such as {heading}, are filled in when the email is sent.")}
                    />
                    <Choice
                        label={__("Size")}
                        value={str("size")}
                        onChange={(v) => set("size", v)}
                        options={[
                            { value: "small", label: __("Small") },
                            { value: "medium", label: __("Medium") },
                            { value: "large", label: __("Large") },
                        ]}
                    />
                    <AlignChoice value={str("align")} onChange={(v) => set("align", v)} />
                </>
            );
        case "text":
            return (
                <>
                    <TokenField
                        id={id("text")}
                        label={__("Text")}
                        value={str("text")}
                        onChange={(v) => set("text", v)}
                        tokens={tokens}
                        labels={labels}
                        multiline
                        formatting
                        help={__("An empty line starts a new paragraph.")}
                    />
                    <AlignChoice value={str("align")} onChange={(v) => set("align", v)} />
                </>
            );
        case "button":
            return (
                <>
                    <TokenField id={id("label")} label={__("Button text")} value={str("label")} onChange={(v) => set("label", v)} tokens={tokens} labels={labels} placeholder={__("View your order")} />
                    <LinkField id={id("url")} label={__("Opens")} value={str("url")} onChange={(v) => set("url", v)} labels={labels} />
                    <ColorField id={id("color")} label={__("Color")} value={str("color")} onChange={(v) => set("color", v)} allowEmpty emptyLabel={__("Brand color")} />
                    <AlignChoice value={str("align")} onChange={(v) => set("align", v)} />
                </>
            );
        case "image":
            return (
                <>
                    <ImagePicker
                        url={str("url")}
                        label={__("Email image")}
                        onPick={(image) => onChange({ ...p, id: image.id, url: image.url, alt: str("alt") !== "" ? str("alt") : image.alt })}
                        onRemove={() => onChange({ ...p, id: 0, url: "" })}
                    />
                    {!canPickImage() && (
                        <Field id={id("url")} label={__("Image address")}>
                            <Input id={id("url")} value={str("url")} placeholder="https://" onChange={(e) => set("url", e.target.value)} />
                        </Field>
                    )}
                    <Field id={id("alt")} label={__("Description")} help={__("Shown when the mail program blocks images.")}>
                        <Input id={id("alt")} value={str("alt")} onChange={(e) => set("alt", e.target.value)} />
                    </Field>
                    <NumberField id={id("width")} label={__("Width")} value={num("width")} min={0} max={600} onChange={(v) => set("width", v)} help={__("Auto fills the width of the email.")} />
                    <LinkField id={id("link")} label={__("Link")} value={str("link")} onChange={(v) => set("link", v)} labels={labels} allowNone />
                    <AlignChoice value={str("align")} onChange={(v) => set("align", v)} />
                </>
            );
        case "spacer":
            return <NumberField id={id("height")} label={__("Height")} value={num("height")} min={4} max={120} onChange={(v) => set("height", Math.max(4, v))} />;
        case "footer":
            return (
                <TokenField
                    id={id("text")}
                    label={__("Text")}
                    value={str("text")}
                    onChange={(v) => set("text", v)}
                    tokens={tokens}
                    labels={labels}
                    multiline
                    formatting
                    placeholder={style.footer !== "" ? style.footer : __("Empty uses the footer set in Style.")}
                    help={__("Leave empty to use the footer set in Style, shared by every email.")}
                />
            );
        case "message":
            return (
                <p className="fxc:text-muted-foreground fxc:text-sm">
                    {__("The text this email always sends, such as “Your order has been received”, followed by the order details. Add Order details, Addresses or Tracking blocks to place those parts somewhere else.")}
                </p>
            );
        case "divider":
            return <p className="fxc:text-muted-foreground fxc:text-sm">{__("A thin line across the email. Nothing to set.")}</p>;
        default:
            return <p className="fxc:text-muted-foreground fxc:text-sm">{__("Filled in from the order. When the order has nothing to show here, the block is left out.")}</p>;
    }
}

/** Logo, colors, font and footer, shared by every store email. */
export function StylePanel({ style, fonts, onChange }: { style: MailStyle; fonts: string[]; onChange: (style: MailStyle) => void }) {
    const set = <K extends keyof MailStyle>(key: K, value: MailStyle[K]) => onChange({ ...style, [key]: value });
    return (
        <div className="fxc:space-y-5">
            <div className="fxc:bg-muted fxc:text-muted-foreground fxc:rounded-md fxc:px-3 fxc:py-2 fxc:text-xs">{__("Style applies to every store email, including the ones you have not customized.")}</div>
            <Field label={__("Logo")}>
                <ImagePicker
                    url={style.logo_url}
                    label={__("Email logo")}
                    onPick={(image) => onChange({ ...style, logo_id: image.id, logo_url: image.url })}
                    onRemove={() => onChange({ ...style, logo_id: 0, logo_url: "" })}
                />
                {!canPickImage() && <Input aria-label={__("Logo address")} value={style.logo_url} placeholder="https://" onChange={(e) => set("logo_url", e.target.value)} />}
            </Field>
            {style.logo_url !== "" && <NumberField id="fxc-style-logo-width" label={__("Logo width")} value={style.logo_width} min={40} max={600} onChange={(v) => set("logo_width", Math.max(40, v))} />}
            <ColorField id="fxc-style-brand" label={__("Brand color")} value={style.brand} onChange={(v) => set("brand", v)} />
            <ColorField id="fxc-style-button-text" label={__("Button text")} value={style.button_text} onChange={(v) => set("button_text", v)} />
            <ColorField id="fxc-style-text" label={__("Text")} value={style.text} onChange={(v) => set("text", v)} />
            <ColorField id="fxc-style-body" label={__("Email background")} value={style.body} onChange={(v) => set("body", v)} />
            <ColorField id="fxc-style-background" label={__("Page background")} value={style.background} onChange={(v) => set("background", v)} />
            <Field id="fxc-style-font" label={__("Font")} help={__("Mail programs only have a few fonts, so these are the safe ones.")}>
                <Select id="fxc-style-font" className="fxc:w-full" value={style.font} onChange={(e) => set("font", e.target.value)} options={fonts.map((f) => ({ value: f, label: fontLabel(f) }))} />
            </Field>
            <Field id="fxc-style-footer" label={__("Footer")} help={__("Your address or a short note. Empty shows the store name.")}>
                <Textarea id="fxc-style-footer" rows={3} maxLength={1000} value={style.footer} onChange={(e) => set("footer", e.target.value)} />
            </Field>
        </div>
    );
}
