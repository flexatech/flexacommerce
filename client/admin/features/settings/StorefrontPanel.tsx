import { useEffect, useMemo, useState } from "react";
import { ArrowLeftRight, Ban, Circle, Crop, Expand, LayoutPanelLeft, Maximize2, MousePointerClick, Tag, ZoomIn } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import { SettingRow } from "../../components/SettingRow";
import { useSaveSettings, useSettings, type Settings } from "./useSettings";

const KEYS = [
    "swatch_size",
    "swatch_shape",
    "swatch_show_choice",
    "swatch_unavailable",
    "swatch_custom",
    "gallery_thumbs",
    "gallery_aspect",
    "gallery_arrows",
    "gallery_zoom",
    "gallery_lightbox",
] as const;

type Draft = Pick<Settings, (typeof KEYS)[number]>;

const pick = (settings: Settings): Draft =>
    Object.fromEntries(KEYS.map((key) => [key, settings[key]])) as Draft;

/** Settings > Storefront: how the product page shows swatches and images, each with a preview. */
export function StorefrontPanel() {
    const query = useSettings();
    const save = useSaveSettings();
    const [draft, setDraft] = useState<Draft | null>(null);

    const saved = query.data?.settings;
    useEffect(() => {
        if (saved) {
            setDraft(pick(saved));
        }
    }, [saved]);

    const changes = useMemo(() => {
        const out: Partial<Record<keyof Draft, unknown>> = {};
        if (draft && saved) {
            for (const key of KEYS) {
                if (draft[key] !== saved[key]) {
                    out[key] = draft[key];
                }
            }
        }
        return out as Partial<Draft>;
    }, [draft, saved]);
    const dirty = Object.keys(changes).length > 0;

    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }
    if (query.isPending || !draft) {
        return <p className="fxc:text-muted-foreground">{__("Loading settings…")}</p>;
    }

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft({ ...draft, [key]: value });

    const submit = () =>
        save.mutate(changes, {
            onSuccess: () => showToast.success(__("Settings saved.")),
            onError: (error) => showToast.error(error.message),
        });

    return (
        <div className="fxc:space-y-6">
            <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3">
                {dirty && <span className="fxc:text-muted-foreground fxc:text-xs">{__("Unsaved changes")}</span>}
                <Button onClick={submit} disabled={!dirty || save.isPending}>
                    {save.isPending ? __("Saving…") : __("Save changes")}
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Swatches")}</CardTitle>
                    <CardDescription>
                        {__("Choose which attributes use colours, images or buttons under Attributes. These options set how they look on the product page.")}
                    </CardDescription>
                </CardHeader>
                <SettingRow
                    icon={Maximize2}
                    label={__("Swatch size (px)")}
                    htmlFor="fxc-swatch-size"
                    control={
                        <Input
                            id="fxc-swatch-size"
                            type="number"
                            min={20}
                            max={96}
                            className="fxc:w-24"
                            value={draft.swatch_size}
                            onChange={(e) => set("swatch_size", Number.parseInt(e.target.value, 10) || 20)}
                        />
                    }
                />
                <SettingRow
                    icon={Circle}
                    label={__("Shape")}
                    htmlFor="fxc-swatch-shape"
                    control={
                        <Select
                            id="fxc-swatch-shape"
                            value={draft.swatch_shape}
                            onChange={(e) => set("swatch_shape", e.target.value as Draft["swatch_shape"])}
                            options={[
                                { value: "round", label: __("Round") },
                                { value: "square", label: __("Square") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={Tag}
                    label={__("Show the chosen value")}
                    description={__("Adds the name of the picked value after the attribute, for example Color: Red.")}
                    control={
                        <Switch
                            aria-label={__("Show the chosen value")}
                            checked={draft.swatch_show_choice}
                            onCheckedChange={(v) => set("swatch_show_choice", v)}
                        />
                    }
                />
                <SettingRow
                    icon={Ban}
                    label={__("Values that are out of stock")}
                    htmlFor="fxc-swatch-unavailable"
                    description={__("A value is out of stock when no variation with it, and the values already picked, can be bought.")}
                    control={
                        <Select
                            id="fxc-swatch-unavailable"
                            value={draft.swatch_unavailable}
                            onChange={(e) => set("swatch_unavailable", e.target.value as Draft["swatch_unavailable"])}
                            options={[
                                { value: "dim", label: __("Cross out") },
                                { value: "hide", label: __("Hide") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={MousePointerClick}
                    label={__("Custom attributes")}
                    htmlFor="fxc-swatch-custom"
                    description={__("Attributes made on a single product have no colours or images, but can show as buttons.")}
                    control={
                        <Select
                            id="fxc-swatch-custom"
                            value={draft.swatch_custom}
                            onChange={(e) => set("swatch_custom", e.target.value as Draft["swatch_custom"])}
                            options={[
                                { value: "dropdown", label: __("Dropdown") },
                                { value: "button", label: __("Buttons") },
                            ]}
                        />
                    }
                />
                <CardContent className="fxc:border-t fxc:pt-4">
                    <SwatchPreview draft={draft} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Product gallery")}</CardTitle>
                    <CardDescription>
                        {__("How the images of a product show on its page. Without JavaScript every image is still a link to its full size.")}
                    </CardDescription>
                </CardHeader>
                <SettingRow
                    icon={LayoutPanelLeft}
                    label={__("Thumbnails")}
                    htmlFor="fxc-gallery-thumbs"
                    description={__("On small screens, thumbnails on the left move below the image.")}
                    control={
                        <Select
                            id="fxc-gallery-thumbs"
                            value={draft.gallery_thumbs}
                            onChange={(e) => set("gallery_thumbs", e.target.value as Draft["gallery_thumbs"])}
                            options={[
                                { value: "bottom", label: __("Below the image") },
                                { value: "left", label: __("Left of the image") },
                                { value: "dots", label: __("Dots only") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={Crop}
                    label={__("Image shape")}
                    htmlFor="fxc-gallery-aspect"
                    description={__("Other shapes crop the large image to fit, so every product lines up.")}
                    control={
                        <Select
                            id="fxc-gallery-aspect"
                            value={draft.gallery_aspect}
                            onChange={(e) => set("gallery_aspect", e.target.value as Draft["gallery_aspect"])}
                            options={[
                                { value: "original", label: __("As uploaded") },
                                { value: "square", label: __("Square (1:1)") },
                                { value: "portrait", label: __("Portrait (4:5)") },
                                { value: "landscape", label: __("Landscape (4:3)") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={ArrowLeftRight}
                    label={__("Arrows")}
                    description={__("Previous and next buttons on the large image. Swiping works either way.")}
                    control={
                        <Switch
                            aria-label={__("Arrows")}
                            checked={draft.gallery_arrows}
                            onCheckedChange={(v) => set("gallery_arrows", v)}
                        />
                    }
                />
                <SettingRow
                    icon={ZoomIn}
                    label={__("Zoom on hover")}
                    description={__("Enlarges the part of the image under the mouse. Touch screens skip it.")}
                    control={
                        <Switch
                            aria-label={__("Zoom on hover")}
                            checked={draft.gallery_zoom}
                            onCheckedChange={(v) => set("gallery_zoom", v)}
                        />
                    }
                />
                <SettingRow
                    icon={Expand}
                    label={__("Full-screen view")}
                    description={__("Clicking the large image opens it full size over the page, with arrows and arrow keys.")}
                    control={
                        <Switch
                            aria-label={__("Full-screen view")}
                            checked={draft.gallery_lightbox}
                            onCheckedChange={(v) => set("gallery_lightbox", v)}
                        />
                    }
                />
                <CardContent className="fxc:border-t fxc:pt-4">
                    <GalleryPreview draft={draft} />
                </CardContent>
            </Card>
        </div>
    );
}

const ASPECT: Record<Draft["gallery_aspect"], string> = {
    original: "3 / 2",
    square: "1 / 1",
    portrait: "4 / 5",
    landscape: "4 / 3",
};

const TONES = ["#c3c4c7", "#dcdcde", "#a7aaad", "#e0e0e0"];

/** A rough picture of the product gallery with the current draft. */
function GalleryPreview({ draft }: { draft: Draft }) {
    const left = draft.gallery_thumbs === "left";
    const thumbs =
        draft.gallery_thumbs === "dots" ? (
            <div className="fxc:flex fxc:justify-center fxc:gap-1.5">
                {TONES.map((tone, i) => (
                    <span
                        key={tone}
                        className={cn("fxc:size-2 fxc:rounded-full fxc:bg-foreground", i === 0 ? "fxc:opacity-100" : "fxc:opacity-30")}
                    />
                ))}
            </div>
        ) : (
            <div className={cn("fxc:grid fxc:gap-1.5", left ? "fxc:w-10 fxc:grid-cols-1" : "fxc:grid-cols-4")}>
                {TONES.map((tone, i) => (
                    <span
                        key={tone}
                        className={cn("fxc:block fxc:aspect-square fxc:rounded-sm", i === 0 && "fxc:ring-foreground fxc:ring-2")}
                        style={{ backgroundColor: tone }}
                    />
                ))}
            </div>
        );
    return (
        <div aria-hidden className="fxc:space-y-3">
            <p className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium fxc:uppercase">{__("Preview")}</p>
            <div className={cn("fxc:max-w-xs", left ? "fxc:flex fxc:items-start fxc:gap-1.5" : "fxc:space-y-1.5")}>
                {left && thumbs}
                <div
                    className="fxc:relative fxc:flex-1 fxc:overflow-hidden fxc:rounded-sm"
                    style={{ aspectRatio: ASPECT[draft.gallery_aspect], backgroundColor: TONES[0] }}
                >
                    {draft.gallery_arrows && (
                        <>
                            <span className="fxc:absolute fxc:top-1/2 fxc:left-1.5 fxc:flex fxc:size-6 fxc:-translate-y-1/2 fxc:items-center fxc:justify-center fxc:rounded-full fxc:bg-white/90 fxc:text-sm">
                                ‹
                            </span>
                            <span className="fxc:absolute fxc:top-1/2 fxc:right-1.5 fxc:flex fxc:size-6 fxc:-translate-y-1/2 fxc:items-center fxc:justify-center fxc:rounded-full fxc:bg-white/90 fxc:text-sm">
                                ›
                            </span>
                        </>
                    )}
                    {(draft.gallery_zoom || draft.gallery_lightbox) && (
                        <span className="fxc:absolute fxc:right-1.5 fxc:bottom-1.5 fxc:flex fxc:items-center fxc:gap-1 fxc:rounded fxc:bg-white/90 fxc:px-1.5 fxc:py-0.5 fxc:text-[10px]">
                            {draft.gallery_zoom ? <ZoomIn className="fxc:size-3" /> : <Expand className="fxc:size-3" />}
                            {draft.gallery_zoom ? __("Zoom") : __("Full screen")}
                        </span>
                    )}
                </div>
                {!left && thumbs}
            </div>
        </div>
    );
}

const SAMPLE = [
    { name: __("Red"), color: "#d63638" },
    { name: __("Blue"), color: "#2271b1" },
    { name: __("Green"), color: "#00a32a", out: true },
];

/** A rough picture of the product page with the current draft; the theme sets the real fonts and colours. */
function SwatchPreview({ draft }: { draft: Draft }) {
    const size = Math.min(96, Math.max(20, draft.swatch_size));
    const round = draft.swatch_shape === "round";
    const rows = draft.swatch_unavailable === "hide" ? SAMPLE.filter((s) => !s.out) : SAMPLE;
    return (
        <div aria-hidden className="fxc:space-y-3">
            <p className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium fxc:uppercase">{__("Preview")}</p>
            <p className="fxc:text-sm">
                {__("Color")}
                {draft.swatch_show_choice && <span className="fxc:font-semibold">: {SAMPLE[0].name}</span>}
            </p>
            <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                {rows.map((swatch, i) => (
                    <span
                        key={swatch.name}
                        className={cn(
                            "fxc:inline-block fxc:border fxc:border-black/20",
                            round ? "fxc:rounded-full" : "fxc:rounded",
                            i === 0 && "fxc:ring-foreground fxc:ring-2 fxc:ring-offset-2",
                            swatch.out && "fxc:opacity-40",
                        )}
                        style={{
                            width: size,
                            height: size,
                            backgroundColor: swatch.color,
                            backgroundImage: swatch.out
                                ? "linear-gradient(to top right, transparent calc(50% - 1px), currentColor, transparent calc(50% + 1px))"
                                : undefined,
                        }}
                    />
                ))}
            </div>
            <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                {["S", "M", "L"].map((label, i) => (
                    <span
                        key={label}
                        className={cn(
                            "fxc:inline-flex fxc:items-center fxc:border fxc:border-black/20 fxc:px-3 fxc:text-sm",
                            round ? "fxc:rounded-full" : "fxc:rounded",
                            i === 1 && "fxc:ring-foreground fxc:ring-2 fxc:ring-offset-2",
                        )}
                        style={{ height: size }}
                    >
                        {label}
                    </span>
                ))}
            </div>
        </div>
    );
}
