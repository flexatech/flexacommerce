import { __ } from "@/shared/lib/i18n";

interface MediaFrame {
    on(event: "select", callback: () => void): void;
    open(): void;
    state(): { get(key: "selection"): { first(): { id: number; get(key: string): unknown } | undefined } };
}

declare global {
    interface WpGlobal {
        media?: (options: Record<string, unknown>) => MediaFrame;
    }
}

export interface PickedImage {
    id: number;
    /** The thumbnail, or the full image when the site has no thumbnail size. */
    url: string;
}

/** True when the screen enqueued the media library (`wp_enqueue_media()`). */
export function canPickImage(): boolean {
    return typeof window.wp?.media === "function";
}

/** Opens the media library for one image; `onPick` runs when the user selects it. */
export function pickImage(title: string, onPick: (image: PickedImage) => void): void {
    const media = window.wp?.media;
    if (!media) {
        return;
    }
    const frame = media({ title, multiple: false, library: { type: "image" }, button: { text: __("Use this image") } });
    frame.on("select", () => {
        const attachment = frame.state().get("selection").first();
        if (attachment) {
            const sizes = attachment.get("sizes") as Record<string, { url: string }> | undefined;
            onPick({ id: attachment.id, url: sizes?.thumbnail?.url ?? (attachment.get("url") as string) });
        }
    });
    frame.open();
}
