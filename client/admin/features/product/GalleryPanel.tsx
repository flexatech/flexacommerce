import { useContext, useEffect, useId, useState } from "react";
import { ArrowLeft, ArrowRight, Film, ImagePlus, Play, X } from "lucide-react";

import { canPickImage, pickImages, pickVideo } from "../../components/pickImage";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import { Field, InvalidFields } from "./fields";
import type { GalleryImage, ProductVideo, VideoPosition, VideoProvider } from "./types";

const POSITIONS: { value: VideoPosition; label: string }[] = [
    { value: "second", label: __("Right after the product image") },
    { value: "first", label: __("Before the product image") },
    { value: "last", label: __("After the gallery images") },
];

/** The same links the server accepts (ProductVideo::parse), to show what was recognised while typing. */
export function detectProvider(url: string): VideoProvider | null {
    const rest = url.trim().replace(/^[a-z][a-z0-9+.-]*:\/\//i, "");
    if (/^((www\.|m\.|music\.)?youtube(-nocookie)?\.com\/(watch\?(.*&)?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/)[A-Za-z0-9_-]{11}([?&#/]|$)/.test(rest)) {
        return "youtube";
    }
    if (/^((www\.)?vimeo\.com\/(channels\/[^/]+\/|groups\/[^/]+\/videos\/)?|player\.vimeo\.com\/video\/)\d{4,12}([/?&#]|$)/.test(rest)) {
        return "vimeo";
    }
    return /^[^?#]+\.(mp4|m4v|webm)([?#].*)?$/i.test(rest) ? "file" : null;
}

const PROVIDER_LABEL: Record<VideoProvider, string> = {
    youtube: __("YouTube video"),
    vimeo: __("Vimeo video"),
    file: __("Video file"),
};

function fileName(url: string): string {
    return decodeURIComponent(url.split(/[?#]/)[0].split("/").pop() ?? url);
}

interface Props {
    gallery: GalleryImage[];
    video: ProductVideo | null;
    errors: Record<string, string>;
    onGallery: (gallery: GalleryImage[]) => void;
    onVideo: (video: ProductVideo | null) => void;
}

/**
 * The product page gallery (ADR-048): the product image (set in the sidebar)
 * comes first, then these images; one video can sit among them.
 */
export function GalleryPanel({ gallery, video, errors, onGallery, onVideo }: Props) {
    const media = canPickImage();

    const add = () =>
        pickImages(__("Add gallery images"), (picked) => {
            const have = new Set(gallery.map((image) => image.id));
            onGallery([...gallery, ...picked.filter((image) => !have.has(image.id)).map((image) => ({ id: image.id, thumb: image.url, alt: image.alt }))]);
        });
    const move = (index: number, delta: number) => {
        const next = [...gallery];
        const [image] = next.splice(index, 1);
        next.splice(index + delta, 0, image);
        onGallery(next);
    };

    return (
        <div className="fxc:space-y-8">
            <section aria-labelledby="fxc-gallery-images" className="fxc:space-y-3">
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-2">
                    <div>
                        <h3 id="fxc-gallery-images" className="fxc:text-sm fxc:font-semibold">
                            {__("Gallery images")}
                        </h3>
                        <p className="fxc:text-muted-foreground fxc:text-xs">
                            {__("The product image set in the sidebar shows first; these follow it in this order.")}
                        </p>
                    </div>
                    {media && (
                        <Button variant="outline" size="sm" onClick={add}>
                            <ImagePlus aria-hidden />
                            {__("Add images")}
                        </Button>
                    )}
                </div>
                {gallery.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:rounded-md fxc:border fxc:border-dashed fxc:px-4 fxc:py-6 fxc:text-center fxc:text-sm">
                        {__("No gallery images yet. Shoppers see the product image only.")}
                    </p>
                ) : (
                    <ol className="fxc:grid fxc:grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] fxc:gap-3">
                        {gallery.map((image, index) => (
                            <li key={image.id} className="fxc:overflow-hidden fxc:rounded-md fxc:border">
                                <img src={image.thumb} alt={image.alt} className="fxc:bg-muted fxc:aspect-square fxc:w-full fxc:object-cover" />
                                <div className="fxc:flex fxc:items-center fxc:justify-between fxc:px-1 fxc:py-0.5">
                                    <span className="fxc:text-muted-foreground fxc:px-1 fxc:text-xs">{index + 1}</span>
                                    <span className="fxc:flex">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="fxc:size-7"
                                            disabled={index === 0}
                                            aria-label={sprintf(__("Move image %d earlier"), index + 1)}
                                            onClick={() => move(index, -1)}
                                        >
                                            <ArrowLeft aria-hidden />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="fxc:size-7"
                                            disabled={index === gallery.length - 1}
                                            aria-label={sprintf(__("Move image %d later"), index + 1)}
                                            onClick={() => move(index, 1)}
                                        >
                                            <ArrowRight aria-hidden />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="fxc:size-7"
                                            aria-label={sprintf(__("Remove image %d"), index + 1)}
                                            onClick={() => onGallery(gallery.filter((other) => other.id !== image.id))}
                                        >
                                            <X aria-hidden />
                                        </Button>
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            <VideoSection video={video} error={errors["video.url"] ?? errors["video.attachment_id"] ?? errors["video.position"] ?? errors.video} media={media} onChange={onVideo} />
        </div>
    );
}

function VideoSection({ video, error, media, onChange }: { video: ProductVideo | null; error?: string; media: boolean; onChange: (video: ProductVideo | null) => void }) {
    const fromLibrary = video !== null && video.attachment_id > 0;
    const [text, setText] = useState(() => (video !== null && !fromLibrary ? video.url : ""));
    const [position, setPosition] = useState<VideoPosition>(video?.position ?? "second");
    const report = useContext(InvalidFields);
    const key = useId();

    const typed = text.trim();
    const provider = fromLibrary ? "file" : typed === "" ? null : detectProvider(typed);
    const invalid = !fromLibrary && typed !== "" && provider === null;

    useEffect(() => {
        report(key, invalid);
        return () => report(key, false);
    }, [report, key, invalid]);

    const type = (value: string) => {
        setText(value);
        const found = detectProvider(value.trim());
        if (value.trim() === "") {
            onChange(null);
        } else if (found !== null) {
            onChange({ provider: found, url: value.trim(), attachment_id: 0, position });
        }
    };
    const choose = () =>
        pickVideo(__("Gallery video"), (picked) => {
            setText("");
            onChange({ provider: "file", url: picked.url, attachment_id: picked.id, position });
        });
    const place = (value: VideoPosition) => {
        setPosition(value);
        if (video !== null) {
            onChange({ ...video, position: value });
        }
    };

    return (
        <section aria-labelledby="fxc-gallery-video" className="fxc:space-y-3">
            <div>
                <h3 id="fxc-gallery-video" className="fxc:text-sm fxc:font-semibold">
                    {__("Video")}
                </h3>
                <p className="fxc:text-muted-foreground fxc:text-xs">
                    {__("Shoppers see the product image with a play button, so the product needs an image. The video loads only when they press play: until then the page contacts no video site.")}
                </p>
            </div>

            {fromLibrary ? (
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2">
                    <Film aria-hidden className="fxc:text-muted-foreground fxc:size-4" />
                    <span className="fxc:min-w-0 fxc:flex-1 fxc:truncate fxc:text-sm">{fileName(video.url)}</span>
                    <Badge variant="muted">{PROVIDER_LABEL.file}</Badge>
                    <Button variant="ghost" size="sm" onClick={() => onChange(null)}>
                        {__("Remove video")}
                    </Button>
                </div>
            ) : (
                <div className="fxc:flex fxc:flex-wrap fxc:items-start fxc:gap-3">
                    <Field
                        className="fxc:min-w-64 fxc:flex-1"
                        label={__("Video link")}
                        help={__("A YouTube or Vimeo link, or a link to an MP4 or WebM file.")}
                        error={invalid ? __("This link is not one we can play. Use a YouTube or Vimeo link, or a link ending in .mp4 or .webm.") : error}
                    >
                        {(id) => (
                            <Input
                                id={id}
                                type="url"
                                value={text}
                                placeholder="https://www.youtube.com/watch?v=…"
                                aria-invalid={invalid || error !== undefined}
                                onChange={(event) => type(event.target.value)}
                            />
                        )}
                    </Field>
                    {media && (
                        <div className="fxc:pt-6">
                            <Button variant="outline" size="sm" className="fxc:h-9" onClick={choose}>
                                <Film aria-hidden />
                                {__("Choose a video file")}
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {provider !== null && (
                <p className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                    <Play aria-hidden className="fxc:size-4" />
                    {sprintf(__("%s found."), PROVIDER_LABEL[provider])}
                </p>
            )}

            <Field label={__("Place in the gallery")} className="fxc:max-w-sm">
                {(id) => <Select id={id} value={position} options={POSITIONS} disabled={video === null} onChange={(event) => place(event.target.value as VideoPosition)} />}
            </Field>
        </section>
    );
}
