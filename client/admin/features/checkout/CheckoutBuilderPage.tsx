import { forwardRef, useEffect, useRef, useState, type ComponentPropsWithoutRef, type PointerEventHandler, type ReactNode } from "react";
import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    pointerWithin,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type Active,
    type Announcements,
    type CollisionDetection,
    type DragEndEvent,
    type Over,
    type UniqueIdentifier,
} from "@dnd-kit/core";
import { SortableContext, arrayMove, rectSortingStrategy, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { Calendar, ChevronDown, Eye, EyeOff, GripVertical, Heading, Lock, MousePointerClick, Pilcrow, RotateCcw, type LucideIcon } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { ApiError } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { BLOCK_HINTS, BlockInspector } from "./BlockInspector";
import { FieldInspector } from "./FieldInspector";
import { CUSTOM_TYPES, SECTION_NAMES, typeName } from "./fieldTypes";
import { TEMP, blankField, finalise, keyOf, parseKey, problemAt, type LayoutProblem } from "./layoutModel";
import {
    BLOCK,
    COLUMN,
    COLUMN_NAMES,
    CONTENT_NAMES,
    blockKey,
    blockName,
    contentCount,
    contentId,
    finalisePage,
    findBlock,
    insertBlock,
    isContent,
    isSection,
    moveBlock,
    pageProblem,
    pageProblemAt,
    toOneColumn,
    toTwoColumns,
} from "./pageModel";
import {
    LOCKED,
    MAX_CONTENT,
    MAX_CUSTOM,
    useCheckoutLayout,
    usePreviewCheckoutLayout,
    useResetCheckoutLayout,
    useSaveCheckoutLayout,
    type BlockId,
    type Column,
    type ContentType,
    type CustomType,
    type Layout,
    type LayoutBlock,
    type LayoutEntry,
    type LayoutFields,
    type LayoutPage,
    type Section,
} from "./useCheckoutLayout";

const PALETTE = "palette:";
/** Palette items that add a heading or a paragraph to the page. */
const CONTENT = "content:";
const DROP = "section:";

const CONTENT_ICONS: Record<ContentType, LucideIcon> = { heading: Heading, paragraph: Pilcrow };

/** Fields move inside the sections; sections, headings and paragraphs move on the page. */
const isBlockDrag = (id: string | null) => id !== null && (id.startsWith(BLOCK) || id.startsWith(CONTENT));

/** What the canvas draws for a field: the checkout's own control, roughly. */
function kindOf(entry: LayoutEntry): CustomType | "choose" {
    if (entry.type) {
        return entry.type;
    }
    return entry.id === "customer_note" ? "textarea" : entry.id === "country" || entry.id === "state" ? "choose" : "text";
}

function FieldSketch({ entry, label }: { entry: LayoutEntry; label: string }) {
    const kind = kindOf(entry);
    const required = entry.answer === "required";
    const hidden = entry.answer === "hidden";
    const marks = (
        <>
            {required && (
                <>
                    <span aria-hidden className="fxc:text-destructive">
                        *
                    </span>
                    <span className="fxc:sr-only">{__("required")}</span>
                </>
            )}
            {LOCKED.includes(entry.id) && <Lock aria-label={__("locked")} className="fxc:text-muted-foreground fxc:size-3 fxc:shrink-0" />}
            {hidden && (
                <span className="fxc:bg-muted fxc:text-muted-foreground fxc:ms-auto fxc:inline-flex fxc:shrink-0 fxc:items-center fxc:gap-1 fxc:rounded-full fxc:px-2 fxc:text-[11px]">
                    <EyeOff aria-hidden className="fxc:size-3" />
                    {__("Hidden")}
                </span>
            )}
        </>
    );
    if (kind === "checkbox") {
        return (
            <span className={cn("fxc:flex fxc:items-center fxc:gap-2 fxc:py-1 fxc:text-xs fxc:font-medium", hidden && "fxc:opacity-60")}>
                <span aria-hidden className="fxc:border-input fxc:size-3.5 fxc:shrink-0 fxc:rounded-sm fxc:border" />
                <span className="fxc:truncate">{label}</span>
                {marks}
            </span>
        );
    }
    return (
        <span className={cn("fxc:block", hidden && "fxc:opacity-60")}>
            <span className="fxc:flex fxc:items-center fxc:gap-1 fxc:text-xs fxc:font-medium">
                <span className="fxc:truncate">{label}</span>
                {marks}
            </span>
            {kind === "radio" ? (
                <span className="fxc:mt-1 fxc:flex fxc:flex-wrap fxc:gap-x-3 fxc:gap-y-1">
                    {(entry.options ?? []).map((option, i) => (
                        <span key={i} className="fxc:text-muted-foreground fxc:flex fxc:items-center fxc:gap-1 fxc:text-xs">
                            <span aria-hidden className="fxc:border-input fxc:size-3 fxc:rounded-full fxc:border" />
                            {option.label || sprintf(__("Choice %d"), i + 1)}
                        </span>
                    ))}
                </span>
            ) : (
                <span
                    aria-hidden
                    className={cn(
                        "fxc:border-input fxc:bg-muted/40 fxc:text-muted-foreground fxc:mt-1 fxc:flex fxc:h-7 fxc:items-center fxc:justify-between fxc:gap-2 fxc:rounded fxc:border fxc:px-2 fxc:text-xs",
                        kind === "textarea" && "fxc:h-12 fxc:items-start fxc:pt-1",
                    )}
                >
                    <span className="fxc:truncate">{entry.placeholder}</span>
                    {(kind === "select" || kind === "choose") && <ChevronDown className="fxc:size-3.5 fxc:shrink-0" />}
                    {kind === "date" && <Calendar className="fxc:size-3.5 fxc:shrink-0" />}
                </span>
            )}
            {entry.help && <span className="fxc:text-muted-foreground fxc:mt-1 fxc:block fxc:truncate fxc:text-[11px]">{entry.help}</span>}
        </span>
    );
}

const focusRing = "fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent";

const Grip = forwardRef<HTMLButtonElement, { label: string } & ComponentPropsWithoutRef<"button">>(function Grip({ label, ...props }, ref) {
    return (
        <button
            ref={ref}
            type="button"
            {...props}
            aria-label={sprintf(__("Move %s"), label)}
            className={cn("fxc:text-muted-foreground fxc:hover:bg-muted fxc:cursor-grab fxc:touch-none fxc:rounded fxc:p-1", focusRing)}
        >
            <GripVertical aria-hidden className="fxc:size-4" />
        </button>
    );
});

function FieldCard({ id, entry, label, selected, blocked, onSelect }: { id: string; entry: LayoutEntry; label: string; selected: boolean; blocked: boolean; onSelect: () => void }) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id, disabled: { draggable: false, droppable: blocked } });
    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            className={cn(
                "fxc:bg-background fxc:flex fxc:items-start fxc:gap-1 fxc:rounded-md fxc:border fxc:p-1.5",
                entry.width === "half" ? "fxc:w-[calc(50%-0.25rem)]" : "fxc:w-full",
                entry.answer === "hidden" && "fxc:border-dashed",
                selected && "fxc:border-primary fxc:ring-primary fxc:ring-1",
                isDragging && "fxc:opacity-40",
            )}
        >
            <Grip label={label} ref={setActivatorNodeRef} {...attributes} {...listeners} />
            <button
                type="button"
                aria-pressed={selected}
                onClick={onSelect}
                className={cn("fxc:min-w-0 fxc:flex-1 fxc:cursor-pointer fxc:rounded fxc:p-1 fxc:text-start", focusRing)}
            >
                <FieldSketch entry={entry} label={label} />
            </button>
        </div>
    );
}

/** The fields of a section: they sort here, and the merchant's own fields can come in from the other sections. */
function FieldZone({ section, ids, blocked, children }: { section: Section; ids: string[]; blocked: boolean; children: ReactNode }) {
    const { setNodeRef, isOver } = useDroppable({ id: DROP + section, disabled: blocked });
    return (
        <SortableContext items={ids} strategy={rectSortingStrategy}>
            <div ref={setNodeRef} className={cn("fxc:mt-3 fxc:flex fxc:min-h-9 fxc:flex-wrap fxc:gap-2 fxc:rounded-md", isOver && "fxc:ring-primary/40 fxc:ring-2 fxc:ring-offset-4")}>
                {children}
            </div>
        </SortableContext>
    );
}

/** A section of the page, a heading or a paragraph: dragged by its grip, selected by its title. */
function BlockCard({
    block,
    selected,
    blocked,
    hint,
    onSelect,
    children,
}: {
    block: LayoutBlock;
    selected: boolean;
    blocked: boolean;
    hint?: string;
    onSelect: () => void;
    children?: ReactNode;
}) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
        id: blockKey(block.id),
        disabled: { draggable: false, droppable: blocked },
    });
    const name = blockName(block);
    const content = isContent(block);
    return (
        <Card
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            className={cn("fxc:px-3 fxc:py-3", content && "fxc:border-dashed", selected && "fxc:border-primary fxc:ring-primary fxc:ring-1", isDragging && "fxc:relative fxc:z-10 fxc:opacity-60")}
        >
            <div className="fxc:flex fxc:items-start fxc:gap-1">
                <Grip label={name} ref={setActivatorNodeRef} {...attributes} {...listeners} />
                <button type="button" aria-pressed={selected} onClick={onSelect} className={cn("fxc:min-w-0 fxc:flex-1 fxc:cursor-pointer fxc:rounded fxc:px-1 fxc:py-0.5 fxc:text-start", focusRing)}>
                    {content ? (
                        <>
                            <span className="fxc:text-muted-foreground fxc:block fxc:text-[11px] fxc:font-medium fxc:uppercase">{CONTENT_NAMES[block.type ?? "heading"]}</span>
                            <span className={cn("fxc:line-clamp-3 fxc:block", block.type === "heading" ? "fxc:text-base fxc:font-semibold" : "fxc:text-muted-foreground fxc:text-sm")}>
                                {name}
                            </span>
                        </>
                    ) : (
                        <>
                            <span className="fxc:block fxc:truncate fxc:text-sm fxc:font-semibold">{name}</span>
                            {hint && <span className="fxc:text-muted-foreground fxc:mt-0.5 fxc:block fxc:text-xs">{hint}</span>}
                        </>
                    )}
                </button>
                {block.id === "payment" && <Lock aria-label={__("Stays at the end of its column")} className="fxc:text-muted-foreground fxc:mt-1.5 fxc:size-3.5 fxc:shrink-0" />}
            </div>
            {children}
        </Card>
    );
}

const Bar = ({ className }: { className?: string }) => <span className={cn("fxc:bg-muted fxc:block fxc:h-2 fxc:rounded-full", className)} />;

/** A rough drawing of the parts the checkout fills in itself. */
function BlockSketch({ id }: { id: BlockId }) {
    const radio = <span className="fxc:border-input fxc:size-3 fxc:shrink-0 fxc:rounded-full fxc:border" />;
    if (id === "shipping_method") {
        return (
            <span aria-hidden className="fxc:mt-3 fxc:block fxc:space-y-2 fxc:ps-7">
                {["fxc:w-24", "fxc:w-16"].map((w) => (
                    <span key={w} className="fxc:flex fxc:items-center fxc:gap-2">
                        {radio}
                        <Bar className={w} />
                        <Bar className="fxc:ms-auto fxc:w-8" />
                    </span>
                ))}
            </span>
        );
    }
    if (id === "summary") {
        return (
            <span aria-hidden className="fxc:mt-3 fxc:block fxc:space-y-2 fxc:ps-7">
                {["fxc:w-28", "fxc:w-20"].map((w) => (
                    <span key={w} className="fxc:flex fxc:items-center fxc:gap-2">
                        <span className="fxc:bg-muted fxc:size-5 fxc:shrink-0 fxc:rounded" />
                        <Bar className={w} />
                        <Bar className="fxc:ms-auto fxc:w-8" />
                    </span>
                ))}
                <span className="fxc:flex fxc:items-center fxc:gap-2 fxc:border-t fxc:pt-2">
                    <Bar className="fxc:bg-muted-foreground/40 fxc:w-10" />
                    <Bar className="fxc:bg-muted-foreground/40 fxc:ms-auto fxc:w-12" />
                </span>
            </span>
        );
    }
    if (id === "payment") {
        return (
            <span aria-hidden className="fxc:mt-3 fxc:block fxc:space-y-2 fxc:ps-7">
                {["fxc:w-24", "fxc:w-20"].map((w) => (
                    <span key={w} className="fxc:flex fxc:items-center fxc:gap-2">
                        {radio}
                        <Bar className={w} />
                    </span>
                ))}
                <span className="fxc:bg-foreground fxc:text-background fxc:flex fxc:h-7 fxc:items-center fxc:justify-center fxc:rounded fxc:text-xs fxc:font-medium">{__("Place order")}</span>
            </span>
        );
    }
    return null;
}

/** A column of the page; it takes drops itself only while empty, the blocks in it take them otherwise. */
function ColumnZone({ column, ids, label, blocked, children }: { column: Column; ids: string[]; label?: string; blocked: boolean; children: ReactNode }) {
    const { setNodeRef, isOver } = useDroppable({ id: COLUMN + column, disabled: blocked || ids.length > 0 });
    return (
        <section aria-label={label ?? __("Page")} className="fxc:min-w-0">
            {label && <h2 className="fxc:text-muted-foreground fxc:mb-2 fxc:text-xs fxc:font-medium">{label}</h2>}
            <SortableContext items={ids} strategy={verticalListSortingStrategy}>
                <div ref={setNodeRef} className={cn("fxc:space-y-3 fxc:rounded-lg", ids.length === 0 && "fxc:border fxc:border-dashed fxc:p-6", isOver && "fxc:ring-primary/40 fxc:ring-2")}>
                    {ids.length === 0 ? <p className="fxc:text-muted-foreground fxc:text-center fxc:text-xs">{__("Drag a section here.")}</p> : children}
                </div>
            </SortableContext>
        </section>
    );
}

/** Pointer users drag it onto the form; a click (or Enter) adds it next to the selection. */
function PaletteItem({ id, label, icon: Icon, disabled, onAdd }: { id: string; label: string; icon: LucideIcon; disabled: boolean; onAdd: () => void }) {
    const { listeners, setNodeRef } = useDraggable({ id, disabled });
    return (
        <button
            ref={setNodeRef}
            type="button"
            disabled={disabled}
            onPointerDown={listeners?.onPointerDown as PointerEventHandler<HTMLButtonElement> | undefined}
            onClick={onAdd}
            className={cn(
                "fxc:hover:bg-muted fxc:flex fxc:w-full fxc:cursor-pointer fxc:touch-none fxc:items-center fxc:gap-2 fxc:rounded-md fxc:px-2 fxc:py-1.5 fxc:text-start fxc:text-sm fxc:disabled:opacity-50",
                focusRing,
            )}
        >
            <span className="fxc:bg-muted fxc:text-muted-foreground fxc:flex fxc:size-7 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-md">
                <Icon aria-hidden className="fxc:size-4" />
            </span>
            {label}
        </button>
    );
}

/** A small radio group drawn as joined buttons. */
function Segmented<T extends string>({ legend, value, options, onChange }: { legend: string; value: T; options: { value: T; label: string }[]; onChange: (value: T) => void }) {
    return (
        <fieldset className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
            <legend className="fxc:float-left fxc:me-2 fxc:text-xs fxc:font-medium">{legend}</legend>
            <span className="fxc:bg-muted fxc:inline-flex fxc:rounded-md fxc:p-0.5">
                {options.map((option) => (
                    <label
                        key={option.value}
                        className={cn(
                            "fxc:cursor-pointer fxc:rounded fxc:px-2.5 fxc:py-1 fxc:text-xs fxc:has-[:focus-visible]:ring-2 fxc:has-[:focus-visible]:ring-primary-accent",
                            value === option.value ? "fxc:bg-background fxc:font-medium fxc:shadow-sm" : "fxc:text-muted-foreground",
                        )}
                    >
                        <input type="radio" className="fxc:sr-only" checked={value === option.value} onChange={() => onChange(option.value)} />
                        {option.label}
                    </label>
                ))}
            </span>
        </fieldset>
    );
}

function same(a: unknown, b: unknown): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

/** Where the dragged block lands next to `over` in another column: before it, or after it when dropped on its lower half. */
function after(active: Active, over: Over): boolean {
    const rect = active.rect.current.translated;
    return rect !== null && rect.top + rect.height / 2 > over.rect.top + over.rect.height / 2;
}

/** FlexaCommerce > Checkout: the checkout page and its fields, arranged by dragging (ADR-031, ADR-032). */
export function CheckoutBuilderPage() {
    const query = useCheckoutLayout();
    const save = useSaveCheckoutLayout();
    const reset = useResetCheckoutLayout();
    const preview = usePreviewCheckoutLayout();
    const [draft, setDraft] = useState<LayoutFields | null>(null);
    const [page, setPage] = useState<LayoutPage | null>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const [fresh, setFresh] = useState<string | null>(null);
    const [problem, setProblem] = useState<LayoutProblem | null>(null);
    const [resetting, setResetting] = useState(false);
    const [dragging, setDragging] = useState<string | null>(null);
    const counter = useRef(0);
    /** The two-column split before switching to one column, to come back to. */
    const split = useRef<LayoutPage | null>(null);
    const inspector = useRef<HTMLDivElement>(null);

    const server = query.data;
    useEffect(() => {
        if (server && draft === null) {
            setDraft(server.fields);
            setPage(server.page);
        }
    }, [server, draft]);
    const dirty = server !== undefined && draft !== null && page !== null && (!same(draft, server.fields) || !same(page, server.page));

    useEffect(() => {
        if (!dirty) {
            return;
        }
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = "";
        };
        window.addEventListener("beforeunload", warn);
        return () => window.removeEventListener("beforeunload", warn);
    }, [dirty]);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

    if (query.isPending || draft === null || page === null || server === undefined) {
        return query.isError ? <p className="fxc:text-destructive">{query.error.message}</p> : <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }

    const twoColumns = page.columns === "two";
    const blockDrag = isBlockDrag(dragging);
    const fieldDrag = dragging !== null && !blockDrag;

    const labelOf = (entry: LayoutEntry) => entry.label.trim() || server.labels[entry.id] || sprintf(__("New %s field"), typeName(entry.type).toLocaleLowerCase());

    const find = (key: string | null) => {
        const at = key ? parseKey(key) : null;
        const index = at ? draft[at.section].findIndex((e) => e.id === at.id) : -1;
        return at && index >= 0 ? { ...at, index, entry: draft[at.section][index] } : null;
    };

    /** On narrow screens the settings sit below the form: bring them into view. */
    const choose = (key: string | null) => {
        setSelected(key);
        if (key && !window.matchMedia("(min-width: 768px)").matches) {
            window.requestAnimationFrame(() => inspector.current?.scrollIntoView({ behavior: "smooth", block: "start" }));
        }
    };

    const nameOf = (id: UniqueIdentifier) => {
        const key = String(id);
        if (key.startsWith(PALETTE)) {
            return sprintf(__("New %s field"), typeName(key.slice(PALETTE.length) as CustomType).toLocaleLowerCase());
        }
        if (key.startsWith(CONTENT)) {
            return sprintf(__("New %s"), CONTENT_NAMES[key.slice(CONTENT.length) as ContentType].toLocaleLowerCase());
        }
        const block = findBlock(page, key);
        if (block) {
            return blockName(block.block);
        }
        const found = find(key);
        return found ? labelOf(found.entry) : key;
    };

    /** Where a field dropped on `over` lands: a field's place, or the end of a section. */
    const target = (over: string): { section: Section; index: number } | null => {
        if (over.startsWith(DROP)) {
            const section = over.slice(DROP.length) as Section;
            return { section, index: draft[section].length };
        }
        const found = find(over);
        return found ? { section: found.section, index: found.index } : null;
    };

    /** Where a block dropped on `over` lands. */
    const blockTarget = (over: string): { column: Column; index: number } | null => {
        if (over.startsWith(COLUMN)) {
            const column = over.slice(COLUMN.length) as Column;
            return { column, index: page[column].length };
        }
        const found = findBlock(page, over);
        return found ? { column: found.column, index: found.index } : null;
    };

    const patch = (key: string, change: Partial<LayoutEntry>) => {
        const found = find(key);
        if (!found) {
            return;
        }
        setDraft({ ...draft, [found.section]: draft[found.section].map((e, i) => (i === found.index ? { ...e, ...change } : e)) });
        if (problem?.key === key) {
            setProblem(null);
        }
    };

    const patchBlock = (key: string, change: Partial<LayoutBlock>) => {
        const found = findBlock(page, key);
        if (!found) {
            return;
        }
        setPage({ ...page, [found.column]: page[found.column].map((b, i) => (i === found.index ? { ...b, ...change } : b)) });
        if (problem?.key === key) {
            setProblem(null);
        }
    };

    /** The block holding the selection: a section, a heading or a paragraph, or the section of a selected field. */
    const selectedBlock = () => findBlock(page, selected) ?? (find(selected) ? findBlock(page, blockKey(find(selected)?.section ?? "")) : null);

    const add = (type: CustomType, at?: { section: Section; index: number }) => {
        const current = find(selected);
        const holder = selectedBlock();
        const place =
            at ??
            (current
                ? { section: current.section, index: current.index + 1 }
                : holder && isSection(holder.block.id)
                  ? { section: holder.block.id, index: draft[holder.block.id].length }
                  : { section: "additional" as Section, index: draft.additional.length });
        if (draft[place.section].filter((e) => e.custom).length >= MAX_CUSTOM) {
            showToast.error(sprintf(__("A section can hold at most %d fields of your own."), MAX_CUSTOM));
            return;
        }
        const id = TEMP + ++counter.current;
        const list = [...draft[place.section]];
        list.splice(place.index, 0, blankField(id, type));
        setDraft({ ...draft, [place.section]: list });
        choose(keyOf(place.section, id));
        setFresh(keyOf(place.section, id));
    };

    const addContent = (type: ContentType, at?: { column: Column; index: number }) => {
        if (contentCount(page) >= MAX_CONTENT) {
            showToast.error(sprintf(__("The page can hold at most %d headings and paragraphs."), MAX_CONTENT));
            return;
        }
        const holder = selectedBlock();
        const place = at ?? (holder ? { column: holder.column, index: holder.index + 1 } : { column: "main" as Column, index: 0 });
        const id = contentId(page);
        setPage(insertBlock(page, { id, type, text: "" }, place.column, place.index));
        choose(blockKey(id));
        setFresh(blockKey(id));
    };

    const move = (key: string, to: { section: Section; index: number }) => {
        const found = find(key);
        if (!found) {
            return;
        }
        if (found.section === to.section) {
            setDraft({ ...draft, [to.section]: arrayMove(draft[to.section], found.index, Math.min(to.index, draft[to.section].length - 1)) });
            return;
        }
        if (!found.entry.custom || draft[to.section].some((e) => e.id === found.entry.id)) {
            return;
        }
        const list = [...draft[to.section]];
        list.splice(to.index, 0, found.entry);
        setDraft({ ...draft, [found.section]: draft[found.section].filter((_, i) => i !== found.index), [to.section]: list });
        if (selected === key) {
            setSelected(keyOf(to.section, found.entry.id));
        }
    };

    const remove = (key: string) => {
        const found = find(key);
        if (found) {
            setDraft({ ...draft, [found.section]: draft[found.section].filter((_, i) => i !== found.index) });
            setSelected(null);
        }
    };

    const removeBlock = (key: string) => {
        const found = findBlock(page, key);
        if (found) {
            setPage({ ...page, [found.column]: page[found.column].filter((_, i) => i !== found.index) });
            setSelected(null);
        }
    };

    const setColumns = (columns: LayoutPage["columns"]) => {
        if (columns === page.columns) {
            return;
        }
        if (columns === "one") {
            split.current = page;
            setPage(toOneColumn(page));
        } else {
            setPage(toTwoColumns(page, split.current));
        }
    };

    // An address field stays in its own section; the merchant's fields can go anywhere.
    // Sections, headings and paragraphs land between the blocks of a column.
    const collision: CollisionDetection = (args) => {
        const active = String(args.active.id);
        if (isBlockDrag(active)) {
            const allowed = args.droppableContainers.filter((c) => String(c.id).startsWith(BLOCK) || String(c.id).startsWith(COLUMN));
            const within = pointerWithin({ ...args, droppableContainers: allowed });
            const blocks = within.filter((c) => String(c.id).startsWith(BLOCK));
            return blocks.length > 0 ? blocks : within.length > 0 ? within : closestCenter({ ...args, droppableContainers: allowed });
        }
        const home = active.startsWith(PALETTE) ? null : find(active);
        const allowed = args.droppableContainers.filter((c) => {
            const id = String(c.id);
            if (id.startsWith(BLOCK) || id.startsWith(COLUMN)) {
                return false;
            }
            return home && !home.entry.custom ? id.startsWith(home.section + ":") : true;
        });
        const within = pointerWithin({ ...args, droppableContainers: allowed });
        const fields = within.filter((c) => !String(c.id).startsWith(DROP));
        if (fields.length > 0) {
            return fields;
        }
        return within.length > 0 ? within : closestCenter({ ...args, droppableContainers: allowed.filter((c) => !String(c.id).startsWith(DROP)) });
    };

    const position = (over: UniqueIdentifier) => {
        const key = String(over);
        if (key.startsWith(BLOCK) || key.startsWith(COLUMN)) {
            const at = blockTarget(key);
            return at ? [Math.min(at.index + 1, page[at.column].length || 1), page[at.column].length || 1, twoColumns ? COLUMN_NAMES[at.column] : __("Page")] : null;
        }
        const at = target(key);
        return at ? [Math.min(at.index + 1, draft[at.section].length), draft[at.section].length, SECTION_NAMES[at.section]] : null;
    };

    const announcements: Announcements = {
        onDragStart: ({ active }) => sprintf(__("Picked up %s."), nameOf(active.id)),
        onDragOver: ({ active, over }) => {
            const at = over ? position(over.id) : null;
            return at ? sprintf(__("%1$s is over position %2$d of %3$d in %4$s."), nameOf(active.id), ...at) : sprintf(__("%s is not over a section."), nameOf(active.id));
        },
        onDragEnd: ({ active, over }) => {
            const at = over ? position(over.id) : null;
            return at ? sprintf(__("%1$s, moved to position %2$d of %3$d in %4$s."), nameOf(active.id), ...at) : sprintf(__("%s was dropped outside the form."), nameOf(active.id));
        },
        onDragCancel: ({ active }) => sprintf(__("Moving %s was cancelled."), nameOf(active.id)),
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragging(null);
        const key = String(active.id);
        if (isBlockDrag(key)) {
            const at = over ? blockTarget(String(over.id)) : null;
            if (!at || !over) {
                return;
            }
            const from = findBlock(page, key);
            // Across columns nothing shifts while dragging, so the drop point says before or after.
            const index = String(over.id).startsWith(BLOCK) && from?.column !== at.column && after(active, over) ? at.index + 1 : at.index;
            if (key.startsWith(CONTENT)) {
                addContent(key.slice(CONTENT.length) as ContentType, { column: at.column, index });
            } else {
                setPage(moveBlock(page, key.slice(BLOCK.length), at.column, index));
            }
            return;
        }
        const at = over ? target(String(over.id)) : null;
        if (!at) {
            return;
        }
        if (key.startsWith(PALETTE)) {
            add(key.slice(PALETTE.length) as CustomType, at);
        } else {
            move(key, at);
        }
    };

    /** The layout as the server takes it, or null after pointing at what is missing. */
    const prepared = (): { layout: Layout; renames: Map<string, string> } | null => {
        const out = finalise(draft);
        const issue = out.problem ?? pageProblem(page);
        if (issue) {
            setProblem(issue);
            choose(issue.key);
            showToast.error(issue.message);
            return null;
        }
        return { layout: { fields: out.fields, page: finalisePage(page) }, renames: out.renames };
    };

    const refused = (error: Error) => {
        const field = error instanceof ApiError ? error.data.field : undefined;
        const at = typeof field === "string" ? (field.startsWith("page.") ? pageProblemAt(page, field, error.message) : problemAt(draft, field, error.message)) : null;
        if (at) {
            setProblem(at);
            choose(at.key);
        }
        showToast.error(error.message);
    };

    const onSave = () => {
        const out = prepared();
        if (!out) {
            return;
        }
        save.mutate(out.layout, {
            onSuccess: (data) => {
                setDraft(data.fields);
                setPage(data.page);
                setProblem(null);
                setFresh(null);
                setSelected((key) => (key ? (out.renames.get(key) ?? key) : key));
                showToast.success(__("Checkout saved."));
            },
            onError: refused,
        });
    };

    const onPreview = () => {
        const out = prepared();
        if (!out) {
            return;
        }
        // Opened now, while the click still counts, so the browser does not block it.
        const tab = window.open("", "_blank");
        preview.mutate(out.layout, {
            onSuccess: ({ url }) => {
                if (tab) {
                    tab.opener = null;
                    tab.location.href = url;
                } else {
                    window.open(url, "_blank", "noopener");
                }
            },
            onError: (error) => {
                tab?.close();
                refused(error);
            },
        });
    };

    const undo = () => {
        setDraft(server.fields);
        setPage(server.page);
        setProblem(null);
        split.current = null;
    };

    const current = find(selected);
    const currentBlock = findBlock(page, selected);
    const overlay = dragging?.startsWith(PALETTE) || dragging?.startsWith(CONTENT) ? nameOf(dragging) : null;
    const atDefault = same(server.fields, server.default.fields) && same(server.page, server.default.page);
    const busy = save.isPending || preview.isPending;

    const renderBlock = (block: LayoutBlock) => {
        const key = blockKey(block.id);
        const props = { block, selected: selected === key, blocked: fieldDrag, onSelect: () => choose(selected === key ? null : key) };
        if (isContent(block)) {
            return <BlockCard key={key} {...props} />;
        }
        const id = block.id as BlockId;
        if (!isSection(id)) {
            return (
                <BlockCard key={key} {...props} hint={BLOCK_HINTS[id]}>
                    <BlockSketch id={id} />
                </BlockCard>
            );
        }
        return (
            <BlockCard key={key} {...props} hint={BLOCK_HINTS[id]}>
                <FieldZone section={id} ids={draft[id].map((e) => keyOf(id, e.id))} blocked={blockDrag}>
                    {draft[id].map((entry) => {
                        const field = keyOf(id, entry.id);
                        return (
                            <FieldCard
                                key={field}
                                id={field}
                                entry={entry}
                                label={labelOf(entry)}
                                selected={selected === field}
                                blocked={blockDrag}
                                onSelect={() => choose(selected === field ? null : field)}
                            />
                        );
                    })}
                </FieldZone>
            </BlockCard>
        );
    };

    return (
        <div>
            <PageTitle
                title={__("Checkout")}
                description={__("Arrange the checkout page: drag the sections between the columns, add headings and text, and set up the fields of each section.")}
                actions={
                    <>
                        {dirty ? (
                            <Button variant="ghost" disabled={busy} onClick={undo}>
                                {__("Undo changes")}
                            </Button>
                        ) : (
                            <Button variant="ghost" disabled={atDefault} onClick={() => setResetting(true)}>
                                <RotateCcw aria-hidden className="fxc:size-4" />
                                {__("Reset to default")}
                            </Button>
                        )}
                        <Button variant="outline" disabled={busy} onClick={onPreview}>
                            <Eye aria-hidden className="fxc:size-4" />
                            {preview.isPending ? __("Opening…") : __("Preview on site")}
                        </Button>
                        <Button disabled={!dirty || busy} onClick={onSave}>
                            {save.isPending ? __("Saving…") : __("Save changes")}
                        </Button>
                    </>
                }
            />

            <DndContext
                sensors={sensors}
                collisionDetection={collision}
                accessibility={{
                    announcements,
                    screenReaderInstructions: {
                        draggable: __("To move an item, press Space or Enter, use the arrow keys, then press Space or Enter again to drop it. Escape cancels."),
                    },
                }}
                onDragStart={({ active }) => setDragging(String(active.id))}
                onDragCancel={() => setDragging(null)}
                onDragEnd={onDragEnd}
            >
                <div className="fxc:grid fxc:items-start fxc:gap-5 fxc:md:grid-cols-[minmax(0,1fr)_16rem] fxc:lg:grid-cols-[11rem_minmax(0,1fr)_18rem]">
                    <aside aria-label={__("Add to the checkout")} className="fxc:space-y-4 fxc:md:col-span-2 fxc:lg:sticky fxc:lg:top-24 fxc:lg:col-span-1">
                        <div>
                            <h2 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("Add a field")}</h2>
                            <div className="fxc:grid fxc:grid-cols-2 fxc:gap-1 fxc:sm:grid-cols-3 fxc:lg:grid-cols-1">
                                {CUSTOM_TYPES.map((type) => (
                                    <PaletteItem key={type.value} id={PALETTE + type.value} label={type.label} icon={type.icon} disabled={busy} onAdd={() => add(type.value)} />
                                ))}
                            </div>
                        </div>
                        <div>
                            <h2 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("Add content")}</h2>
                            <div className="fxc:grid fxc:grid-cols-2 fxc:gap-1 fxc:sm:grid-cols-3 fxc:lg:grid-cols-1">
                                {(Object.keys(CONTENT_NAMES) as ContentType[]).map((type) => (
                                    <PaletteItem key={type} id={CONTENT + type} label={CONTENT_NAMES[type]} icon={CONTENT_ICONS[type]} disabled={busy} onAdd={() => addContent(type)} />
                                ))}
                            </div>
                        </div>
                        <p className="fxc:text-muted-foreground fxc:text-xs">{__("Drag onto the page, or click to add after the selected item.")}</p>
                    </aside>

                    <div className="fxc:@container fxc:min-w-0 fxc:space-y-4">
                        <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-x-6 fxc:gap-y-2">
                            <Segmented<LayoutPage["columns"]>
                                legend={__("Layout")}
                                value={page.columns}
                                onChange={setColumns}
                                options={[
                                    { value: "two", label: __("Two columns") },
                                    { value: "one", label: __("One column") },
                                ]}
                            />
                            <Segmented<LayoutPage["summary"]>
                                legend={__("Order summary on phones")}
                                value={page.summary}
                                onChange={(summary) => setPage({ ...page, summary })}
                                options={[
                                    { value: "end", label: __("In its place") },
                                    { value: "top", label: __("At the top, folded") },
                                ]}
                            />
                        </div>
                        <div className={cn("fxc:grid fxc:items-start fxc:gap-4", twoColumns && "fxc:@2xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]")}>
                            {(twoColumns ? (["main", "side"] as Column[]) : (["main"] as Column[])).map((column) => (
                                <ColumnZone
                                    key={column}
                                    column={column}
                                    ids={page[column].map((b) => blockKey(b.id))}
                                    label={twoColumns ? COLUMN_NAMES[column] : undefined}
                                    blocked={fieldDrag}
                                >
                                    {page[column].map(renderBlock)}
                                </ColumnZone>
                            ))}
                        </div>
                    </div>

                    <Card ref={inspector} aria-label={__("Settings")} className="fxc:scroll-mt-12 fxc:px-4 fxc:py-4 fxc:md:sticky fxc:md:top-12 fxc:lg:top-24">
                        {current ? (
                            <FieldInspector
                                key={selected}
                                entry={current.entry}
                                section={current.section}
                                defaultLabel={server.labels[current.entry.id] ?? ""}
                                problem={problem?.key === selected ? problem : null}
                                fresh={fresh === selected}
                                canUp={current.index > 0}
                                canDown={current.index < draft[current.section].length - 1}
                                onChange={(change) => selected && patch(selected, change)}
                                onMove={(delta) => selected && move(selected, { section: current.section, index: current.index + delta })}
                                onSection={(section) => selected && move(selected, { section, index: draft[section].length })}
                                onDelete={() => selected && remove(selected)}
                            />
                        ) : currentBlock && selected ? (
                            <BlockInspector
                                key={selected}
                                block={currentBlock.block}
                                column={currentBlock.column}
                                twoColumns={twoColumns}
                                problem={problem?.key === selected ? problem : null}
                                fresh={fresh === selected}
                                canUp={currentBlock.index > 0}
                                canDown={currentBlock.index < page[currentBlock.column].length - 1 && page[currentBlock.column][currentBlock.index + 1]?.id !== "payment"}
                                onChange={(change) => patchBlock(selected, change)}
                                onMove={(delta) => setPage(moveBlock(page, currentBlock.block.id, currentBlock.column, currentBlock.index + delta))}
                                onColumn={(column) => setPage(moveBlock(page, currentBlock.block.id, column, page[column].length))}
                                onDelete={() => removeBlock(selected)}
                            />
                        ) : (
                            <div className="fxc:text-muted-foreground fxc:flex fxc:flex-col fxc:items-center fxc:gap-2 fxc:py-6 fxc:text-center fxc:text-sm">
                                <MousePointerClick aria-hidden className="fxc:size-6" />
                                {__("Select a section or a field to change it, or add one from the list on the left.")}
                            </div>
                        )}
                    </Card>
                </div>
                <DragOverlay>
                    {overlay && <div className="fxc:bg-background fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-sm fxc:shadow-md">{overlay}</div>}
                </DragOverlay>
            </DndContext>

            <Dialog open={resetting} onOpenChange={(open) => !open && !reset.isPending && setResetting(false)}>
                <DialogContent className="fxc:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{__("Reset the checkout?")}</DialogTitle>
                        <DialogDescription>
                            {__("The sections and fields go back to their default places, titles and settings; your headings, paragraphs and own fields leave the checkout. Answers on past orders stay.")}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" disabled={reset.isPending} onClick={() => setResetting(false)}>
                            {__("Cancel")}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={reset.isPending}
                            onClick={() =>
                                reset.mutate(undefined, {
                                    onSuccess: (data) => {
                                        setDraft(data.fields);
                                        setPage(data.page);
                                        setSelected(null);
                                        setProblem(null);
                                        setResetting(false);
                                        split.current = null;
                                        showToast.success(__("Checkout reset."));
                                    },
                                    onError: (error) => showToast.error(error.message),
                                })
                            }
                        >
                            {reset.isPending ? __("Resetting…") : __("Reset")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
