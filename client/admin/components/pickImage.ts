import { __ } from "@/shared/lib/i18n";

interface Attachment {
    id: number;
    get(key: string): unknown;
}

interface MediaFrame {
    on(event: "select" | "ready", callback: () => void): void;
    open(): void;
    /** Set once the frame is ready; its params go with every upload. */
    uploader?: { options: { uploader: { params: Record<string, unknown> } } };
    state(): { get(key: "selection"): { first(): Attachment | undefined; toArray(): Attachment[] } };
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
            onPick({ id: attachment.id, url: thumbOf(attachment) });
        }
    });
    frame.open();
}

const thumbOf = (attachment: Attachment): string => {
    const sizes = attachment.get("sizes") as Record<string, { url: string }> | undefined;
    return sizes?.thumbnail?.url ?? (attachment.get("url") as string);
};

/** Opens the media library for several images, in the order they were selected. */
export function pickImages(title: string, onPick: (images: (PickedImage & { alt: string })[]) => void): void {
    const media = window.wp?.media;
    if (!media) {
        return;
    }
    const frame = media({ title, multiple: "add", library: { type: "image" }, button: { text: __("Add to gallery") } });
    frame.on("select", () => {
        onPick(
            frame
                .state()
                .get("selection")
                .toArray()
                .map((attachment) => ({ id: attachment.id, url: thumbOf(attachment), alt: String(attachment.get("alt") ?? "") })),
        );
    });
    frame.open();
}

export interface PickedVideo {
    id: number;
    url: string;
    filename: string;
}

/** Opens the media library for one video file. */
export function pickVideo(title: string, onPick: (video: PickedVideo) => void): void {
    const media = window.wp?.media;
    if (!media) {
        return;
    }
    const frame = media({ title, multiple: false, library: { type: "video" }, button: { text: __("Use this video") } });
    frame.on("select", () => {
        const attachment = frame.state().get("selection").first();
        if (attachment) {
            onPick({ id: attachment.id, url: String(attachment.get("url") ?? ""), filename: String(attachment.get("filename") ?? "") });
        }
    });
    frame.open();
}

export interface PickedFile {
    url: string;
    filename: string;
    title: string;
}

/**
 * Opens the media library for a file to sell. Uploads from this frame carry
 * `fxc_download`, so the server puts them in the protected downloads folder
 * (ADR-058).
 */
export function pickFile(title: string, onPick: (file: PickedFile) => void): void {
    const media = window.wp?.media;
    if (!media) {
        return;
    }
    const frame = media({ title, multiple: false, button: { text: __("Use this file") } });
    frame.on("ready", () => {
        const options = frame.uploader?.options.uploader;
        if (options) {
            options.params = { ...options.params, fxc_download: "1" };
        }
    });
    frame.on("select", () => {
        const attachment = frame.state().get("selection").first();
        if (attachment) {
            onPick({ url: String(attachment.get("url") ?? ""), filename: String(attachment.get("filename") ?? ""), title: String(attachment.get("title") ?? "") });
        }
    });
    frame.open();
}
