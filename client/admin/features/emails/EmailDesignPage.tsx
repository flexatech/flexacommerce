import { useEffect, useRef, useState } from "react";
import { DndContext, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { ArrowLeft, Copy, GripVertical, Monitor, Plus, RotateCcw, Send, Smartphone, Trash2, Undo2, X } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { Tabs } from "../../components/Tabs";
import { BlockSettings, StylePanel } from "./DesignPanels";
import { BLOCK_KINDS, freeId, insertAt, kindOf, newBlock, summary, tokenLabels } from "./emailBlocks";
import { useDesignPreview, useEmailDesign, useSaveDesign, useSaveStyle, useSendTest, type BlockType, type Design, type DesignBlock, type MailStyle, type PreviewInput } from "./useEmailDesign";

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

const emailsUrl = () => `${getPluginGlobal().adminUrl}?page=flexacommerce-settings&tab=emails`;

const readEmail = () => new URLSearchParams(window.location.search).get("email") ?? "";

/** Waits until `value` has stopped changing for `ms`. */
function useSettled<T>(value: T, ms: number): T {
    const [settled, setSettled] = useState(value);
    // Compared by content: the caller builds a new object on every render.
    const content = JSON.stringify(value);
    const latest = useRef(value);
    latest.current = value;
    useEffect(() => {
        const timer = window.setTimeout(() => setSettled(latest.current), ms);
        return () => window.clearTimeout(timer);
    }, [content, ms]);
    return settled;
}

/** FlexaCommerce > Settings > Emails > Customize design (ADR-085). */
export function EmailDesignPage() {
    const [emailId, setEmailId] = useState(readEmail);
    useEffect(() => {
        const onPop = () => setEmailId(readEmail());
        window.addEventListener("popstate", onPop);
        return () => window.removeEventListener("popstate", onPop);
    }, []);

    if (emailId === "") {
        return (
            <p className="fxc:text-muted-foreground">
                {__("Choose an email to design from")} <a href={emailsUrl()}>{__("the email settings")}</a>.
            </p>
        );
    }
    return <Designer key={emailId} emailId={emailId} onSwitch={setEmailId} />;
}

function Designer({ emailId, onSwitch }: { emailId: string; onSwitch: (id: string) => void }) {
    const query = useEmailDesign(emailId);
    const saveDesign = useSaveDesign(emailId);
    const saveStyle = useSaveStyle();
    const sendTest = useSendTest(emailId);
    const [design, setDesign] = useState<Design | null>(null);
    const [style, setStyle] = useState<MailStyle | null>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const [tab, setTab] = useState<"blocks" | "style">("blocks");
    const [adding, setAdding] = useState(false);
    const [orderId, setOrderId] = useState(0);
    const [device, setDevice] = useState<"desktop" | "mobile">("desktop");
    const [resetting, setResetting] = useState(false);
    const [testing, setTesting] = useState(false);
    const [testTo, setTestTo] = useState("");
    const labels = useRef(tokenLabels()).current;

    const server = query.data;
    useEffect(() => {
        if (server && design === null) {
            setDesign(server.design ?? server.starter);
            setStyle(server.style);
            setTestTo(server.test_to);
        }
    }, [server, design]);

    const saved = server ? (server.design ?? server.starter) : null;
    const designDirty = server !== undefined && design !== null && !same(design, saved);
    const styleDirty = server !== undefined && style !== null && !same(style, server.style);
    const dirty = designDirty || styleDirty;

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

    const input: PreviewInput | null = design && style ? { design, style, order_id: orderId } : null;
    const preview = useDesignPreview(emailId, useSettled(input, 300));
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

    if (query.isPending || !server || !design || !style) {
        return query.isError ? <p className="fxc:text-destructive">{query.error.message}</p> : <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }

    const custom = server.design !== null;
    const busy = saveDesign.isPending || saveStyle.isPending;
    const block = design.blocks.find((b) => b.id === selected) ?? null;
    const has = (type: BlockType) => design.blocks.some((b) => b.type === type);

    const choose = (id: string | null) => {
        setSelected(id);
        setAdding(false);
        if (id !== null) {
            setTab("blocks");
        }
    };

    const add = (type: BlockType) => {
        const fresh = newBlock(design, type);
        const at = insertAt(design, selected);
        setDesign({ blocks: [...design.blocks.slice(0, at), fresh, ...design.blocks.slice(at)] });
        choose(fresh.id);
    };

    const update = (id: string, props: DesignBlock["props"]) => setDesign({ blocks: design.blocks.map((b) => (b.id === id ? { ...b, props } : b)) });

    const remove = (id: string) => {
        if (design.blocks.length <= 1) {
            showToast.error(__("An email needs at least one block."));
            return;
        }
        setDesign({ blocks: design.blocks.filter((b) => b.id !== id) });
        choose(null);
    };

    const duplicate = (source: DesignBlock) => {
        const copy = { ...source, id: freeId(design), props: { ...source.props } };
        const at = design.blocks.findIndex((b) => b.id === source.id) + 1;
        setDesign({ blocks: [...design.blocks.slice(0, at), copy, ...design.blocks.slice(at)] });
        choose(copy.id);
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) {
            return;
        }
        const from = design.blocks.findIndex((b) => b.id === active.id);
        const to = design.blocks.findIndex((b) => b.id === over.id);
        setDesign({ blocks: arrayMove(design.blocks, from, to) });
    };

    const switchTo = (id: string) => {
        if (dirty && !window.confirm(__("Leave this email? Your changes are not saved."))) {
            return;
        }
        const url = new URL(window.location.href);
        url.searchParams.set("email", id);
        window.history.pushState(null, "", url.toString());
        onSwitch(id);
    };

    const onSave = async () => {
        try {
            // An email on the default layout stays on it until its blocks change.
            if (designDirty) {
                await saveDesign.mutateAsync(design);
            }
            if (styleDirty) {
                await saveStyle.mutateAsync(style);
            }
            showToast.success(__("Email design saved."));
        } catch (e) {
            showToast.error(e instanceof Error ? e.message : __("The design could not be saved."));
        }
    };

    const onReset = () =>
        saveDesign.mutate(null, {
            onSuccess: () => {
                setDesign(server.starter);
                setResetting(false);
                choose(null);
                showToast.success(__("This email uses the default design again."));
            },
            onError: (e) => showToast.error(e.message),
        });

    const onSendTest = () =>
        sendTest.mutate(
            { design, style, order_id: orderId, to: testTo },
            {
                onSuccess: ({ sent }) => {
                    setTesting(false);
                    showToast.success(sprintf(__("Test email sent to %s."), sent.join(", ")));
                },
                onError: (e) => showToast.error(e.message),
            },
        );

    return (
        <div>
            <a href={emailsUrl()} className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:mb-3 fxc:inline-flex fxc:items-center fxc:gap-1 fxc:text-sm fxc:no-underline">
                <ArrowLeft aria-hidden className="fxc:size-4" />
                {__("Emails")}
            </a>
            <div className="fxc:mb-5 fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-3">
                <div className="fxc:flex fxc:min-w-0 fxc:flex-wrap fxc:items-center fxc:gap-3">
                    <h1 className="fxc:text-foreground fxc:m-0 fxc:text-xl fxc:font-semibold">{__("Email design")}</h1>
                    <Select
                        aria-label={__("Email")}
                        value={emailId}
                        onChange={(e) => switchTo(e.target.value)}
                        options={server.emails.map((e) => ({ value: e.id, label: e.custom ? sprintf(__("%s (customized)"), e.title) : e.title }))}
                    />
                    <Badge variant={custom ? "brand" : "muted"}>{custom ? __("Customized") : __("Default design")}</Badge>
                </div>
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    {dirty ? (
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={() => {
                                setDesign(saved);
                                setStyle(server.style);
                                choose(null);
                            }}
                        >
                            <Undo2 aria-hidden />
                            {__("Undo changes")}
                        </Button>
                    ) : (
                        custom && (
                            <Button variant="ghost" disabled={busy} onClick={() => setResetting(true)}>
                                <RotateCcw aria-hidden />
                                {__("Reset to default")}
                            </Button>
                        )
                    )}
                    <Button variant="outline" onClick={() => setTesting(true)}>
                        <Send aria-hidden />
                        {__("Send test")}
                    </Button>
                    <Button disabled={!dirty || busy} onClick={() => void onSave()}>
                        {busy ? __("Saving…") : __("Save")}
                    </Button>
                </div>
            </div>

            <div className="fxc:grid fxc:items-start fxc:gap-5 fxc:lg:grid-cols-[22rem_minmax(0,1fr)]">
                <Card className="fxc:lg:sticky fxc:lg:top-24 fxc:overflow-hidden">
                    <div className="fxc:px-4 fxc:pt-2">
                        <Tabs
                            value={tab}
                            onChange={(next) => {
                                setTab(next);
                                setSelected(null);
                            }}
                            items={[
                                { value: "blocks", label: __("Blocks") },
                                { value: "style", label: __("Style") },
                            ]}
                        />
                    </div>
                    <div className="fxc:max-h-[calc(100vh-14rem)] fxc:overflow-y-auto fxc:p-4">
                        {tab === "style" ? (
                            <StylePanel style={style} fonts={server.fonts} onChange={setStyle} />
                        ) : block ? (
                            <div className="fxc:space-y-4">
                                <div className="fxc:flex fxc:items-center fxc:justify-between fxc:gap-2">
                                    <button type="button" onClick={() => choose(null)} className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:inline-flex fxc:cursor-pointer fxc:items-center fxc:gap-1 fxc:bg-transparent fxc:p-0 fxc:text-sm">
                                        <ArrowLeft aria-hidden className="fxc:size-4" />
                                        {__("All blocks")}
                                    </button>
                                    <div className="fxc:flex fxc:gap-1">
                                        {!kindOf(block.type).single && (
                                            <Button variant="ghost" size="icon" aria-label={__("Duplicate block")} title={__("Duplicate block")} onClick={() => duplicate(block)}>
                                                <Copy aria-hidden />
                                            </Button>
                                        )}
                                        <Button variant="ghost" size="icon" aria-label={__("Remove block")} title={__("Remove block")} onClick={() => remove(block.id)}>
                                            <Trash2 aria-hidden />
                                        </Button>
                                    </div>
                                </div>
                                <h2 className="fxc:text-foreground fxc:text-base fxc:font-semibold">{kindOf(block.type).name}</h2>
                                <BlockSettings block={block} tokens={server.tokens} labels={labels} style={style} onChange={(props) => update(block.id, props)} onOpenStyle={() => setTab("style")} />
                            </div>
                        ) : (
                            <div className="fxc:flex fxc:flex-col fxc:gap-4">
                                <div className="fxc:text-muted-foreground fxc:text-xs">{__("Click a block to change it, or drag to reorder. You can also click straight on the preview.")}</div>
                                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                                    <SortableContext items={design.blocks.map((b) => b.id)} strategy={verticalListSortingStrategy}>
                                        <ul className="fxc:m-0 fxc:flex fxc:list-none fxc:flex-col fxc:gap-2.5 fxc:p-0">
                                            {design.blocks.map((b) => (
                                                <BlockRow key={b.id} block={b} text={summary(b, labels)} onSelect={() => choose(b.id)} />
                                            ))}
                                        </ul>
                                    </SortableContext>
                                </DndContext>
                                {adding ? (
                                    <div className="fxc:rounded-md fxc:border fxc:p-2">
                                        <div className="fxc:mb-1 fxc:flex fxc:items-center fxc:justify-between fxc:px-1">
                                            <span className="fxc:text-sm fxc:font-medium">{__("Add a block")}</span>
                                            <Button variant="ghost" size="icon" aria-label={__("Close")} onClick={() => setAdding(false)}>
                                                <X aria-hidden />
                                            </Button>
                                        </div>
                                        <div className="fxc:grid fxc:grid-cols-2 fxc:gap-1">
                                            {BLOCK_KINDS.map((kind) => {
                                                const taken = kind.single && has(kind.type);
                                                const Icon = kind.icon;
                                                return (
                                                    <button
                                                        key={kind.type}
                                                        type="button"
                                                        disabled={taken || design.blocks.length >= 60}
                                                        title={taken ? __("This email already has one.") : kind.hint}
                                                        onClick={() => add(kind.type)}
                                                        className="fxc:hover:bg-muted fxc:flex fxc:cursor-pointer fxc:items-center fxc:gap-2 fxc:rounded-md fxc:bg-transparent fxc:px-2 fxc:py-2 fxc:text-left fxc:text-sm fxc:disabled:cursor-not-allowed fxc:disabled:opacity-40"
                                                    >
                                                        <Icon aria-hidden className="fxc:text-primary fxc:size-4 fxc:shrink-0" />
                                                        {kind.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ) : (
                                    <Button variant="outline" className="fxc:w-full" onClick={() => setAdding(true)}>
                                        <Plus aria-hidden />
                                        {__("Add block")}
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                </Card>

                <div className="fxc:min-w-0 fxc:space-y-3">
                    <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-2">
                        <Select
                            aria-label={__("Preview with")}
                            value={String(orderId)}
                            onChange={(e) => setOrderId(Number(e.target.value))}
                            options={[{ value: "0", label: __("Preview with a sample order") }, ...server.orders.map((o) => ({ value: String(o.id), label: sprintf(__("Preview with order %s"), o.label) }))]}
                        />
                        <span className="fxc:bg-muted fxc:inline-flex fxc:rounded-md fxc:p-0.5" role="group" aria-label={__("Screen size")}>
                            {(["desktop", "mobile"] as const).map((d) => (
                                <button
                                    key={d}
                                    type="button"
                                    aria-pressed={device === d}
                                    onClick={() => setDevice(d)}
                                    className={cn(
                                        "fxc:flex fxc:cursor-pointer fxc:items-center fxc:gap-1.5 fxc:rounded fxc:bg-transparent fxc:px-2.5 fxc:py-1 fxc:text-xs",
                                        device === d ? "fxc:bg-background fxc:text-foreground fxc:font-medium fxc:shadow-sm" : "fxc:text-muted-foreground",
                                    )}
                                >
                                    {d === "desktop" ? <Monitor aria-hidden className="fxc:size-4" /> : <Smartphone aria-hidden className="fxc:size-4" />}
                                    {d === "desktop" ? __("Desktop") : __("Mobile")}
                                </button>
                            ))}
                        </span>
                    </div>
                    <Card className="fxc:overflow-hidden">
                        <div className="fxc:border-b fxc:px-4 fxc:py-2.5 fxc:text-sm">
                            <span className="fxc:text-muted-foreground">{__("Subject:")}</span> <span className="fxc:font-medium">{preview.data?.subject ?? "…"}</span>
                        </div>
                        <div className={cn("fxc:bg-muted/40 fxc:relative", preview.isFetching && "fxc:opacity-80")}>
                            {preview.isError ? (
                                <p className="fxc:text-destructive fxc:p-4 fxc:text-sm">{preview.error.message}</p>
                            ) : preview.data ? (
                                <PreviewFrame html={preview.data.html} selected={selected} width={device === "mobile" ? 375 : null} onSelect={choose} />
                            ) : (
                                <p className="fxc:text-muted-foreground fxc:p-4 fxc:text-sm">{__("Loading preview…")}</p>
                            )}
                        </div>
                    </Card>
                </div>
            </div>

            <Dialog open={resetting} onOpenChange={setResetting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__("Reset to the default design?")}</DialogTitle>
                        <DialogDescription>{__("This email goes back to the standard layout. The style shared by all emails stays as it is.")}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setResetting(false)}>
                            {__("Cancel")}
                        </Button>
                        <Button variant="destructive" disabled={saveDesign.isPending} onClick={onReset}>
                            {saveDesign.isPending ? __("Resetting…") : __("Reset")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={testing} onOpenChange={setTesting}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__("Send a test email")}</DialogTitle>
                        <DialogDescription>{__("Sends what you see in the preview, including changes you have not saved.")}</DialogDescription>
                    </DialogHeader>
                    <form
                        className="fxc:space-y-1.5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            onSendTest();
                        }}
                    >
                        <label htmlFor="fxc-test-to" className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                            {__("Send to")}
                        </label>
                        <Input id="fxc-test-to" type="text" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                        <p className="fxc:text-muted-foreground fxc:text-xs">{__("Separate up to five addresses with commas.")}</p>
                    </form>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setTesting(false)}>
                            {__("Cancel")}
                        </Button>
                        <Button disabled={sendTest.isPending || testTo.trim() === ""} onClick={onSendTest}>
                            {sendTest.isPending ? __("Sending…") : __("Send test")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function BlockRow({ block, text, onSelect }: { block: DesignBlock; text: string; onSelect: () => void }) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: block.id });
    const kind = kindOf(block.type);
    const Icon = kind.icon;
    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={cn("fxc:bg-background fxc:m-0 fxc:flex fxc:items-center fxc:rounded-md fxc:border", isDragging && "fxc:relative fxc:z-10 fxc:shadow-md")}
        >
            <button
                type="button"
                ref={setActivatorNodeRef}
                {...attributes}
                {...listeners}
                aria-label={sprintf(__("Move %s"), kind.name)}
                className="fxc:text-muted-foreground fxc:flex fxc:cursor-grab fxc:self-stretch fxc:items-center fxc:bg-transparent fxc:px-1.5"
            >
                <GripVertical aria-hidden className="fxc:size-4" />
            </button>
            <button type="button" onClick={onSelect} className="fxc:hover:bg-muted/60 fxc:flex fxc:min-w-0 fxc:flex-1 fxc:cursor-pointer fxc:items-center fxc:gap-2.5 fxc:bg-transparent fxc:py-2 fxc:pr-3 fxc:text-left">
                <Icon aria-hidden className="fxc:text-primary fxc:size-4 fxc:shrink-0" />
                <span className="fxc:min-w-0">
                    <span className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">{kind.name}</span>
                    <span className="fxc:text-muted-foreground fxc:block fxc:truncate fxc:text-xs">{text}</span>
                </span>
            </button>
        </li>
    );
}

const PICK = "#2563eb";

/**
 * The email in a sandboxed frame without scripts. Clicking a block selects it;
 * the selected block gets an outline. The frame grows to the email's height.
 */
function PreviewFrame({ html, selected, width, onSelect }: { html: string; selected: string | null; width: number | null; onSelect: (id: string) => void }) {
    const frame = useRef<HTMLIFrameElement>(null);
    const [loaded, setLoaded] = useState(0);
    const select = useRef(onSelect);
    select.current = onSelect;

    const fit = () => {
        const doc = frame.current?.contentDocument;
        if (frame.current && doc?.documentElement) {
            // Shrink first: a document is never shorter than the frame showing it.
            frame.current.style.height = "0px";
            frame.current.style.height = `${doc.documentElement.scrollHeight}px`;
        }
    };

    const onLoad = () => {
        const doc = frame.current?.contentDocument;
        if (!doc) {
            return;
        }
        doc.addEventListener("click", (event) => {
            event.preventDefault();
            const row = (event.target as Element | null)?.closest?.("[data-fxc-block]");
            if (row) {
                select.current(row.getAttribute("data-fxc-block") ?? "");
            }
        });
        doc.querySelectorAll<HTMLElement>("[data-fxc-block] > td").forEach((cell) => {
            cell.style.cursor = "pointer";
            cell.addEventListener("mouseenter", () => {
                if (!cell.dataset.fxcPicked) {
                    cell.style.outline = `1px dashed ${PICK}`;
                    cell.style.outlineOffset = "-1px";
                }
            });
            cell.addEventListener("mouseleave", () => {
                if (!cell.dataset.fxcPicked) {
                    cell.style.outline = "";
                }
            });
        });
        fit();
        setLoaded((n) => n + 1);
    };

    useEffect(() => {
        const doc = frame.current?.contentDocument;
        if (!doc) {
            return;
        }
        doc.querySelectorAll<HTMLElement>("[data-fxc-block] > td").forEach((cell) => {
            const picked = cell.parentElement?.getAttribute("data-fxc-block") === selected;
            cell.dataset.fxcPicked = picked ? "1" : "";
            cell.style.outline = picked ? `2px solid ${PICK}` : "";
            cell.style.outlineOffset = "-2px";
            if (picked) {
                cell.scrollIntoView({ block: "nearest", behavior: "smooth" });
            }
        });
    }, [selected, loaded]);

    useEffect(fit, [width]);

    return (
        <iframe
            ref={frame}
            title={__("Email preview")}
            sandbox="allow-same-origin"
            srcDoc={html}
            onLoad={onLoad}
            className="fxc:mx-auto fxc:block fxc:min-h-96 fxc:border-0 fxc:bg-transparent fxc:transition-[width]"
            style={{ width: width !== null ? `${width}px` : "100%" }}
        />
    );
}
