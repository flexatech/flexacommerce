import { __ } from "@/shared/lib/i18n";
import { SECTIONS, type CustomType, type LayoutEntry, type LayoutFields, type Section } from "./useCheckoutLayout";

/** A field added since the last save carries a temporary id until it has a label. */
export const TEMP = "__new_";

/** Ids the server keeps for the address columns and the order notes. */
const RESERVED = ["first_name", "last_name", "company", "address_1", "address_2", "city", "state", "postcode", "country", "email", "phone", "customer_note"];

export const CHOICE_TYPES: CustomType[] = ["select", "radio"];
/** These show no placeholder on the checkout. */
export const NO_PLACEHOLDER: CustomType[] = ["checkbox", "radio", "select", "date"];

export const keyOf = (section: Section, id: string) => `${section}:${id}`;

export function parseKey(key: string): { section: Section; id: string } | null {
    const at = key.indexOf(":");
    const section = key.slice(0, at) as Section;
    return at > 0 && SECTIONS.includes(section) ? { section, id: key.slice(at + 1) } : null;
}

export function blankField(id: string, type: CustomType): LayoutEntry {
    return {
        id,
        label: "",
        placeholder: "",
        help: "",
        width: "full",
        answer: "optional",
        custom: true,
        type,
        options: CHOICE_TYPES.includes(type) ? [{ value: "", label: "" }, { value: "", label: "" }] : [],
        email: true,
    };
}

/** "Gift message" => "gift_message"; accents dropped, so "Thời gian giao" => "thoi_gian_giao". */
function slug(label: string, max: number): string {
    return label
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .replace(/[đĐ]/g, "d")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+/, "")
        .slice(0, max)
        .replace(/_+$/, "");
}

function unique(base: string, taken: Set<string>): string {
    let id = base;
    for (let n = 2; taken.has(id); n++) {
        id = `${base}_${n}`;
    }
    taken.add(id);
    return id;
}

export interface LayoutProblem {
    key: string;
    /** The setting at fault: `label`, `options`, `answer`... */
    field: string;
    message: string;
}

/**
 * The layout as the server takes it: new fields get their id from their
 * label, choices get a value from theirs, and empty choices are dropped.
 * `renames` maps the temporary keys to the final ones.
 */
export function finalise(fields: LayoutFields): { fields: LayoutFields; renames: Map<string, string>; problem: LayoutProblem | null } {
    const taken = new Set<string>(RESERVED);
    SECTIONS.forEach((s) => fields[s].forEach((e) => !e.id.startsWith(TEMP) && taken.add(e.id)));
    const renames = new Map<string, string>();
    let problem: LayoutProblem | null = null;
    const out = {} as LayoutFields;
    for (const section of SECTIONS) {
        out[section] = fields[section].map((entry) => {
            if (!entry.custom) {
                return entry;
            }
            const key = keyOf(section, entry.id);
            const label = entry.label.trim();
            if (label === "") {
                problem ??= { key, field: "label", message: __("Give the field a label.") };
                return entry;
            }
            let id = entry.id;
            if (id.startsWith(TEMP)) {
                const base = slug(label, 34);
                id = unique(/^[a-z]/.test(base) ? base : `field_${base}`.replace(/_+$/, ""), taken);
                renames.set(key, keyOf(section, id));
            }
            let options = entry.options ?? [];
            if (entry.type && CHOICE_TYPES.includes(entry.type)) {
                const values = new Set(options.map((o) => o.value).filter(Boolean));
                options = options
                    .filter((o) => o.label.trim() !== "")
                    .map((o) => ({ label: o.label.trim(), value: o.value || unique(slug(o.label, 34) || "choice", values) }));
                if (options.length === 0) {
                    problem ??= { key, field: "options", message: __("Add at least one choice.") };
                }
            }
            return { ...entry, id, label, placeholder: entry.placeholder.trim(), help: entry.help.trim(), options };
        });
    }
    return { fields: out, renames, problem };
}

/** `billing.3.options.1.value` from the server => the field it points at. */
export function problemAt(fields: LayoutFields, path: string, message: string): LayoutProblem | null {
    const [section, index, ...rest] = path.split(".");
    const entry = SECTIONS.includes(section as Section) ? fields[section as Section][Number(index)] : undefined;
    return entry ? { key: keyOf(section as Section, entry.id), field: rest[0] ?? "id", message } : null;
}
