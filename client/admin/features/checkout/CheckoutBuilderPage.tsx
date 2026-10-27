import { useEffect, useRef, useState, type PointerEventHandler, type ReactNode } from "react";
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
    type Announcements,
    type CollisionDetection,
    type DragEndEvent,
    type UniqueIdentifier,
} from "@dnd-kit/core";
import { SortableContext, arrayMove, rectSortingStrategy, sortableKeyboardCoordinates, useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { Calendar, ChevronDown, EyeOff, GripVertical, Lock, MousePointerClick, RotateCcw } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { ApiError } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { FieldInspector } from "./FieldInspector";
import { CUSTOM_TYPES, SECTION_NAMES, typeName } from "./fieldTypes";
import { TEMP, blankField, finalise, keyOf, parseKey, problemAt, type LayoutProblem } from "./layoutModel";
import {
    LOCKED,
    MAX_CUSTOM,
    SECTIONS,
    useCheckoutLayout,
    useResetCheckoutLayout,
    useSaveCheckoutLayout,
    type CustomType,
    type LayoutEntry,
    type LayoutFields,
    type Section,
} from "./useCheckoutLayout";

const PALETTE = "palette:";
const DROP = "section:";

const HINTS: Record<Section, string> = {
    billing: __("Who pays: the email, the name and the billing address."),
    shipping: __("Shown when the cart needs shipping and the customer ticks “Ship to a different address”."),
    additional: __("Order notes and your own questions, under the addresses."),
};

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

function FieldCard({ id, entry, label, selected, onSelect }: { id: string; entry: LayoutEntry; label: string; selected: boolean; onSelect: () => void }) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id });
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
            <button
                ref={setActivatorNodeRef}
                type="button"
                {...attributes}
                {...listeners}
                aria-label={sprintf(__("Move %s"), label)}
                className="fxc:text-muted-foreground fxc:hover:bg-muted fxc:cursor-grab fxc:touch-none fxc:rounded fxc:p-1 fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
            >
                <GripVertical aria-hidden className="fxc:size-4" />
            </button>
            <button
                type="button"
                aria-pressed={selected}
                onClick={onSelect}
                className="fxc:min-w-0 fxc:flex-1 fxc:cursor-pointer fxc:rounded fxc:p-1 fxc:text-start fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
            >
                <FieldSketch entry={entry} label={label} />
            </button>
        </div>
    );
}

function SectionCard({ section, ids, children }: { section: Section; ids: string[]; children: ReactNode }) {
    const { setNodeRef, isOver } = useDroppable({ id: DROP + section });
    return (
        <Card className="fxc:px-4 fxc:py-4">
            <h2 className="fxc:text-sm fxc:font-semibold">{SECTION_NAMES[section]}</h2>
            <p className="fxc:text-muted-foreground fxc:mt-0.5 fxc:text-xs">{HINTS[section]}</p>
            <SortableContext items={ids} strategy={rectSortingStrategy}>
                <div ref={setNodeRef} className={cn("fxc:mt-3 fxc:flex fxc:flex-wrap fxc:gap-2 fxc:rounded-md", isOver && "fxc:ring-primary/40 fxc:ring-2 fxc:ring-offset-4")}>
                    {children}
                </div>
            </SortableContext>
        </Card>
    );
}

/** Pointer users drag it onto a section; a click (or Enter) adds it next to the selected field. */
function PaletteItem({ type, disabled, onAdd }: { type: (typeof CUSTOM_TYPES)[number]; disabled: boolean; onAdd: () => void }) {
    const { listeners, setNodeRef } = useDraggable({ id: PALETTE + type.value, disabled });
    const Icon = type.icon;
    return (
        <button
            ref={setNodeRef}
            type="button"
            disabled={disabled}
            onPointerDown={listeners?.onPointerDown as PointerEventHandler<HTMLButtonElement> | undefined}
            onClick={onAdd}
            className="fxc:hover:bg-muted fxc:flex fxc:w-full fxc:cursor-pointer fxc:touch-none fxc:items-center fxc:gap-2 fxc:rounded-md fxc:px-2 fxc:py-1.5 fxc:text-start fxc:text-sm fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent fxc:disabled:opacity-50"
        >
            <span className="fxc:bg-muted fxc:text-muted-foreground fxc:flex fxc:size-7 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-md">
                <Icon aria-hidden className="fxc:size-4" />
            </span>
            {type.label}
        </button>
    );
}

function same(a: unknown, b: unknown): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

/** FlexaCommerce > Checkout: the fields of the checkout form, arranged by dragging (ADR-031). */
export function CheckoutBuilderPage() {
    const query = useCheckoutLayout();
    const save = useSaveCheckoutLayout();
    const reset = useResetCheckoutLayout();
    const [draft, setDraft] = useState<LayoutFields | null>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const [fresh, setFresh] = useState<string | null>(null);
    const [problem, setProblem] = useState<LayoutProblem | null>(null);
    const [resetting, setResetting] = useState(false);
    const [dragging, setDragging] = useState<string | null>(null);
    const counter = useRef(0);

    const server = query.data;
    useEffect(() => {
        if (server && draft === null) {
            setDraft(server.fields);
        }
    }, [server, draft]);
    const dirty = server !== undefined && draft !== null && !same(draft, server.fields);

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

    if (query.isPending || draft === null || server === undefined) {
        return query.isError ? <p className="fxc:text-destructive">{query.error.message}</p> : <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }

    const labelOf = (entry: LayoutEntry) => entry.label.trim() || server.labels[entry.id] || sprintf(__("New %s field"), typeName(entry.type).toLocaleLowerCase());

    const find = (key: string | null) => {
        const at = key ? parseKey(key) : null;
        const index = at ? draft[at.section].findIndex((e) => e.id === at.id) : -1;
        return at && index >= 0 ? { ...at, index, entry: draft[at.section][index] } : null;
    };

    const nameOf = (id: UniqueIdentifier) => {
        const key = String(id);
        if (key.startsWith(PALETTE)) {
            return sprintf(__("New %s field"), typeName(key.slice(PALETTE.length) as CustomType).toLocaleLowerCase());
        }
        const found = find(key);
        return found ? labelOf(found.entry) : key;
    };

    /** Where a drop on `over` lands: a field's place, or the end of a section. */
    const target = (over: string): { section: Section; index: number } | null => {
        if (over.startsWith(DROP)) {
            const section = over.slice(DROP.length) as Section;
            return { section, index: draft[section].length };
        }
        const found = find(over);
        return found ? { section: found.section, index: found.index } : null;
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

    const add = (type: CustomType, at?: { section: Section; index: number }) => {
        const current = find(selected);
        const place = at ?? (current ? { section: current.section, index: current.index + 1 } : { section: "additional" as Section, index: draft.additional.length });
        if (draft[place.section].filter((e) => e.custom).length >= MAX_CUSTOM) {
            showToast.error(sprintf(__("A section can hold at most %d fields of your own."), MAX_CUSTOM));
            return;
        }
        const id = TEMP + ++counter.current;
        const list = [...draft[place.section]];
        list.splice(place.index, 0, blankField(id, type));
        setDraft({ ...draft, [place.section]: list });
        setSelected(keyOf(place.section, id));
        setFresh(keyOf(place.section, id));
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

    // An address field stays in its own section; the merchant's fields can go anywhere.
    const collision: CollisionDetection = (args) => {
        const active = String(args.active.id);
        const home = active.startsWith(PALETTE) ? null : find(active);
        const allowed = args.droppableContainers.filter((c) => {
            const id = String(c.id);
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
        const at = target(String(over));
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
        const at = over ? target(String(over.id)) : null;
        if (!at) {
            return;
        }
        const key = String(active.id);
        if (key.startsWith(PALETTE)) {
            add(key.slice(PALETTE.length) as CustomType, at);
        } else {
            move(key, at);
        }
    };

    const onSave = () => {
        const out = finalise(draft);
        if (out.problem) {
            setProblem(out.problem);
            setSelected(out.problem.key);
            showToast.error(out.problem.message);
            return;
        }
        save.mutate(out.fields, {
            onSuccess: (data) => {
                setDraft(data.fields);
                setProblem(null);
                setFresh(null);
                setSelected((key) => (key ? (out.renames.get(key) ?? key) : key));
                showToast.success(__("Checkout fields saved."));
            },
            onError: (error) => {
                const field = error instanceof ApiError ? error.data.field : undefined;
                const at = typeof field === "string" ? problemAt(draft, field, error.message) : null;
                if (at) {
                    setProblem(at);
                    setSelected(at.key);
                }
                showToast.error(error.message);
            },
        });
    };

    const current = find(selected);
    const overlay = dragging ? (dragging.startsWith(PALETTE) ? nameOf(dragging) : null) : null;

    return (
        <div>
            <PageTitle
                title={__("Checkout")}
                description={__("Arrange the fields of the checkout form: drag them into order, rename them, set them to required, optional or hidden, and add your own questions.")}
                actions={
                    <>
                        {dirty ? (
                            <Button variant="ghost" disabled={save.isPending} onClick={() => setDraft(server.fields)}>
                                {__("Undo changes")}
                            </Button>
                        ) : (
                            <Button variant="ghost" disabled={same(server.fields, server.default)} onClick={() => setResetting(true)}>
                                <RotateCcw aria-hidden className="fxc:size-4" />
                                {__("Reset to default")}
                            </Button>
                        )}
                        <Button disabled={!dirty || save.isPending} onClick={onSave}>
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
                        draggable: __("To move a field, press Space or Enter, use the arrow keys, then press Space or Enter again to drop it. Escape cancels."),
                    },
                }}
                onDragStart={({ active }) => setDragging(String(active.id))}
                onDragCancel={() => setDragging(null)}
                onDragEnd={onDragEnd}
            >
                <div className="fxc:grid fxc:items-start fxc:gap-5 fxc:lg:grid-cols-[11rem_minmax(0,1fr)_18rem]">
                    <aside aria-label={__("Add a field")} className="fxc:lg:sticky fxc:lg:top-24">
                        <h2 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("Add a field")}</h2>
                        <div className="fxc:grid fxc:grid-cols-2 fxc:gap-1 fxc:lg:grid-cols-1">
                            {CUSTOM_TYPES.map((type) => (
                                <PaletteItem key={type.value} type={type} disabled={save.isPending} onAdd={() => add(type.value)} />
                            ))}
                        </div>
                        <p className="fxc:text-muted-foreground fxc:mt-3 fxc:text-xs">{__("Drag onto a section, or click to add after the selected field.")}</p>
                    </aside>

                    <div className="fxc:space-y-4">
                        {SECTIONS.map((section) => (
                            <SectionCard key={section} section={section} ids={draft[section].map((e) => keyOf(section, e.id))}>
                                {draft[section].map((entry) => {
                                    const key = keyOf(section, entry.id);
                                    return (
                                        <FieldCard
                                            key={key}
                                            id={key}
                                            entry={entry}
                                            label={labelOf(entry)}
                                            selected={selected === key}
                                            onSelect={() => setSelected(selected === key ? null : key)}
                                        />
                                    );
                                })}
                            </SectionCard>
                        ))}
                    </div>

                    <Card aria-label={__("Field settings")} className="fxc:px-4 fxc:py-4 fxc:lg:sticky fxc:lg:top-24">
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
                        ) : (
                            <div className="fxc:text-muted-foreground fxc:flex fxc:flex-col fxc:items-center fxc:gap-2 fxc:py-6 fxc:text-center fxc:text-sm">
                                <MousePointerClick aria-hidden className="fxc:size-6" />
                                {__("Select a field to change it, or add one from the list on the left.")}
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
                        <DialogTitle>{__("Reset the checkout fields?")}</DialogTitle>
                        <DialogDescription>{__("The fields go back to their default order, labels and settings, and your own fields leave the checkout. Answers on past orders stay.")}</DialogDescription>
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
                                        setSelected(null);
                                        setProblem(null);
                                        setResetting(false);
                                        showToast.success(__("Checkout fields reset."));
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
