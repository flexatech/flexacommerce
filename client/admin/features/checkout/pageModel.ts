import { __ } from "@/shared/lib/i18n";
import { SECTIONS, type BlockId, type Column, type ContentType, type LayoutBlock, type LayoutPage, type Section } from "./useCheckoutLayout";
import type { LayoutProblem } from "./layoutModel";

/** Selection and drag ids of the page's blocks: `block:billing`, `block:text_k3x9`. */
export const BLOCK = "block:";
export const COLUMN = "column:";
export const COLUMNS: Column[] = ["main", "side"];

export const blockKey = (id: string) => BLOCK + id;

export const BLOCK_NAMES: Record<BlockId, string> = {
    billing: __("Billing details"),
    shipping: __("Shipping address"),
    additional: __("Additional information"),
    shipping_method: __("Shipping"),
    summary: __("Your order"),
    payment: __("Payment"),
};

export const CONTENT_NAMES: Record<ContentType, string> = {
    heading: __("Heading"),
    paragraph: __("Paragraph"),
};

export const COLUMN_NAMES: Record<Column, string> = {
    main: __("Main column"),
    side: __("Side column"),
};

export type Step = "information" | "shipping" | "payment";

export const STEPS: Step[] = ["information", "shipping", "payment"];

export const STEP_NAMES: Record<Step, string> = {
    information: __("Information"),
    shipping: __("Shipping"),
    payment: __("Payment"),
};

const STEP_OF: Partial<Record<BlockId, Step>> = {
    billing: "information",
    shipping: "information",
    additional: "information",
    shipping_method: "shipping",
    payment: "payment",
};

/**
 * The step a block shows in, as CheckoutLayout::step(): a section's own, a
 * heading or paragraph that of the section below it (else above it). Null
 * means every step, as for the order summary.
 */
export function stepOf(page: LayoutPage, column: Column, index: number): Step | null {
    const blocks = page[column];
    const order = [...blocks.slice(index), ...blocks.slice(0, index).reverse()];
    const section = order.find((b) => !isContent(b));
    return section ? (STEP_OF[section.id as BlockId] ?? null) : null;
}

export const isSection = (id: string): id is Section => SECTIONS.includes(id as Section);
export const isContent = (block: LayoutBlock) => !(block.id in BLOCK_NAMES);

/** What the canvas and the inspector call a block. */
export function blockName(block: LayoutBlock): string {
    if (isContent(block)) {
        return block.text?.replace(/<[^>]*>/g, "").trim() || CONTENT_NAMES[block.type ?? "heading"];
    }
    return block.title?.trim() || BLOCK_NAMES[block.id as BlockId];
}

export function findBlock(page: LayoutPage, key: string | null): { column: Column; index: number; block: LayoutBlock } | null {
    if (!key?.startsWith(BLOCK)) {
        return null;
    }
    const id = key.slice(BLOCK.length);
    for (const column of COLUMNS) {
        const index = page[column].findIndex((b) => b.id === id);
        if (index >= 0) {
            return { column, index, block: page[column][index] };
        }
    }
    return null;
}

/** Payment closes its column: the Place order button is the last thing in it. */
export function settle(page: LayoutPage): LayoutPage {
    const out = { ...page };
    for (const column of COLUMNS) {
        const payment = out[column].find((b) => b.id === "payment");
        if (payment && out[column].at(-1) !== payment) {
            out[column] = [...out[column].filter((b) => b !== payment), payment];
        }
    }
    return out;
}

/** Moves a block to `index` of `column`; an index past the end appends it. */
export function moveBlock(page: LayoutPage, id: string, column: Column, index: number): LayoutPage {
    const from = findBlock(page, blockKey(id));
    if (!from) {
        return page;
    }
    const out = { ...page, [from.column]: page[from.column].filter((b) => b.id !== id) };
    const list = [...out[column]];
    // In its own column, dropping on a later block puts it after that block.
    list.splice(Math.min(index, list.length), 0, from.block);
    return settle({ ...out, [column]: list });
}

export function insertBlock(page: LayoutPage, block: LayoutBlock, column: Column, index: number): LayoutPage {
    const list = [...page[column]];
    list.splice(Math.min(index, list.length), 0, block);
    return settle({ ...page, [column]: list });
}

/** One column reads the main column, then the side one. */
export function toOneColumn(page: LayoutPage): LayoutPage {
    return settle({ ...page, columns: "one", main: [...page.main, ...page.side], side: [] });
}

/**
 * Back to two columns: what follows the last field section goes to the
 * side, which holds Shipping, the order summary and Payment by default.
 * `before` is the split the page had, kept when the order did not change.
 */
export function toTwoColumns(page: LayoutPage, before: LayoutPage | null): LayoutPage {
    const ids = page.main.map((b) => b.id);
    if (before && [...before.main, ...before.side].map((b) => b.id).join() === ids.join()) {
        return { ...page, columns: "two", main: page.main.slice(0, before.main.length), side: page.main.slice(before.main.length) };
    }
    let last = -1;
    ids.forEach((id, i) => isSection(id) && (last = i));
    return settle({ ...page, columns: "two", main: page.main.slice(0, last + 1), side: page.main.slice(last + 1) });
}

export function contentId(page: LayoutPage): string {
    const taken = new Set([...page.main, ...page.side].map((b) => b.id));
    let id = "";
    do {
        id = "text_" + Math.random().toString(36).slice(2, 10);
    } while (taken.has(id) || id.length < 6);
    return id;
}

export const contentCount = (page: LayoutPage) => [...page.main, ...page.side].filter(isContent).length;

/** The first heading or paragraph left without text. */
export function pageProblem(page: LayoutPage): LayoutProblem | null {
    const empty = [...page.main, ...page.side].find((b) => isContent(b) && (b.text ?? "").replace(/<[^>]*>/g, "").trim() === "");
    return empty ? { key: blockKey(empty.id), field: "text", message: __("Write the text, or delete the item.") } : null;
}

/** `page.side.1.title` from the server => the block it points at. */
export function pageProblemAt(page: LayoutPage, path: string, message: string): LayoutProblem | null {
    const [root, column, index, field] = path.split(".");
    const block = root === "page" && COLUMNS.includes(column as Column) ? page[column as Column][Number(index)] : undefined;
    return block ? { key: blockKey(block.id), field: field ?? "id", message } : null;
}

/** Trimmed the way the server stores it; an empty section title stays empty. */
export function finalisePage(page: LayoutPage): LayoutPage {
    const clean = (b: LayoutBlock): LayoutBlock => (isContent(b) ? { id: b.id, type: b.type, text: (b.text ?? "").trim() } : { id: b.id, title: (b.title ?? "").trim() });
    return { ...page, main: page.main.map(clean), side: page.side.map(clean) };
}
