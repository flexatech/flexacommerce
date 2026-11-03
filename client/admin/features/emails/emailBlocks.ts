import {
    Download,
    Heading,
    ImageIcon,
    LayoutList,
    MapPin,
    MessageSquareText,
    Minus,
    MousePointerClick,
    MoveVertical,
    PanelBottom,
    Pilcrow,
    Store,
    Truck,
    type LucideIcon,
} from "lucide-react";

import { __ } from "@/shared/lib/i18n";
import type { BlockType, Design, DesignBlock } from "./useEmailDesign";

export interface BlockKind {
    type: BlockType;
    name: string;
    hint: string;
    icon: LucideIcon;
    /** Only one per email. */
    single: boolean;
}

/** In the order the "Add block" list shows them. Props mirror DesignTree::TYPES. */
export const BLOCK_KINDS: BlockKind[] = [
    { type: "heading", name: __("Heading"), hint: __("A title line"), icon: Heading, single: false },
    { type: "text", name: __("Text"), hint: __("A paragraph you write"), icon: Pilcrow, single: false },
    { type: "button", name: __("Button"), hint: __("A link styled as a button"), icon: MousePointerClick, single: false },
    { type: "image", name: __("Image"), hint: __("A picture from the media library"), icon: ImageIcon, single: false },
    { type: "logo", name: __("Logo"), hint: __("Your store logo or name"), icon: Store, single: false },
    { type: "divider", name: __("Divider"), hint: __("A thin line"), icon: Minus, single: false },
    { type: "spacer", name: __("Space"), hint: __("Empty room between blocks"), icon: MoveVertical, single: false },
    { type: "message", name: __("Email message"), hint: __("What this email says, with the order details"), icon: MessageSquareText, single: true },
    { type: "order_details", name: __("Order details"), hint: __("The products and totals"), icon: LayoutList, single: true },
    { type: "addresses", name: __("Addresses"), hint: __("Billing and shipping addresses"), icon: MapPin, single: true },
    { type: "downloads", name: __("Downloads"), hint: __("Download links, when the order has files"), icon: Download, single: true },
    { type: "shipments", name: __("Tracking"), hint: __("Shipment tracking, when the order has it"), icon: Truck, single: true },
    { type: "footer", name: __("Footer"), hint: __("Small print at the bottom"), icon: PanelBottom, single: false },
];

export const kindOf = (type: BlockType): BlockKind => BLOCK_KINDS.find((k) => k.type === type) ?? BLOCK_KINDS[0];

const DEFAULTS: Record<BlockType, Record<string, string | number>> = {
    logo: { align: "left", width: 0 },
    heading: { text: "{heading}", size: "medium", align: "left" },
    text: { text: "", align: "left" },
    button: { label: "", url: "{order_url}", align: "left", color: "" },
    image: { id: 0, url: "", alt: "", width: 0, align: "center", link: "" },
    divider: {},
    spacer: { height: 24 },
    message: {},
    order_details: {},
    addresses: {},
    downloads: {},
    shipments: {},
    footer: { text: "" },
};

/** A short id no other block of the design has. */
export function freeId(design: Design): string {
    const taken = new Set(design.blocks.map((b) => b.id));
    let n = design.blocks.length + 1;
    while (taken.has(`b${n}`)) {
        n++;
    }
    return `b${n}`;
}

export function newBlock(design: Design, type: BlockType): DesignBlock {
    return { id: freeId(design), type, props: { ...DEFAULTS[type] } };
}

/** Where a new block goes: after the selected one, else above the footer, else at the end. */
export function insertAt(design: Design, selected: string | null): number {
    const at = selected ? design.blocks.findIndex((b) => b.id === selected) : -1;
    if (at >= 0) {
        return at + 1;
    }
    const last = design.blocks.length - 1;
    return last >= 0 && design.blocks[last].type === "footer" ? last : design.blocks.length;
}

/** What a block shows in the list under its name. */
export function summary(block: DesignBlock, labels: Record<string, string>): string {
    const p = block.props;
    const plain = (value: unknown) =>
        String(value ?? "")
            .replace(/<[^>]*>/g, "")
            .replace(/\{([a-z_]+)\}/g, (_match: string, name: string) => labels[`{${name}}`] ?? name)
            .trim();
    switch (block.type) {
        case "heading":
        case "text":
            return plain(p.text) || __("Empty");
        case "button":
            return plain(p.label) || __("View your order");
        case "image":
            return p.url ? plain(p.alt) || __("Image chosen") : __("No image yet");
        case "spacer":
            return `${Number(p.height)} px`;
        case "footer":
            return plain(p.text) || __("Store footer");
        default:
            return kindOf(block.type).hint;
    }
}

/** Plain names for the values an email fills in. */
export function tokenLabels(): Record<string, string> {
    return {
        "{site_title}": __("Store name"),
        "{site_url}": __("Store address"),
        "{store_email}": __("Store email"),
        "{heading}": __("Email heading"),
        "{order_number}": __("Order number"),
        "{order_date}": __("Order date"),
        "{order_total}": __("Order total"),
        "{order_url}": __("Order link"),
        "{payment_method}": __("Payment method"),
        "{customer_name}": __("Customer name"),
        "{customer_first_name}": __("Customer first name"),
        "{customer_email}": __("Customer email"),
        "{year}": __("Current year"),
    };
}

export function fontLabel(font: string): string {
    switch (font) {
        case "arial":
            return "Arial";
        case "verdana":
            return "Verdana";
        case "georgia":
            return "Georgia";
        case "times":
            return "Times New Roman";
        case "courier":
            return "Courier New";
        default:
            return __("System default");
    }
}
