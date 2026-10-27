import { useId } from "react";
import { ArrowDown, ArrowUp, Trash2 } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { Textarea } from "@/shared/components/ui/textarea";
import { __, sprintf } from "@/shared/lib/i18n";
import { Row } from "./FieldInspector";
import type { LayoutProblem } from "./layoutModel";
import { BLOCK_NAMES, COLUMN_NAMES, CONTENT_NAMES, isContent } from "./pageModel";
import { HEADING_MAX, LABEL_MAX, PARAGRAPH_MAX, type BlockId, type Column, type ContentType, type LayoutBlock } from "./useCheckoutLayout";

export const BLOCK_HINTS: Record<BlockId, string> = {
    billing: __("Who pays: the email, the name and the billing address."),
    shipping: __("Shown when the cart needs shipping and the customer ticks “Ship to a different address”."),
    additional: __("Order notes and your own questions. Hidden when all its fields are hidden."),
    shipping_method: __("The shipping rates for the customer's address. Shown when the cart needs shipping."),
    summary: __("The products in the cart and the totals."),
    payment: __("The payment methods, the terms and the Place order button."),
};

interface Props {
    block: LayoutBlock;
    column: Column;
    twoColumns: boolean;
    problem: LayoutProblem | null;
    /** A heading or paragraph added from the palette: its text gets the focus. */
    fresh: boolean;
    canUp: boolean;
    canDown: boolean;
    onChange: (patch: Partial<LayoutBlock>) => void;
    /** The order summary's coupon code box. */
    coupons: boolean;
    onCoupons: (show: boolean) => void;
    onMove: (delta: -1 | 1) => void;
    onColumn: (column: Column) => void;
    onDelete: () => void;
}

/** The settings of a section of the page, or of a heading or paragraph (ADR-032). */
export function BlockInspector({ block, column, twoColumns, problem, fresh, canUp, canDown, onChange, coupons, onCoupons, onMove, onColumn, onDelete }: Props) {
    const id = useId();
    const content = isContent(block);
    const payment = block.id === "payment";
    const error = (field: string) => (problem?.field === field ? problem.message : undefined);
    const where = twoColumns ? (column === "main" ? __("In the main column") : __("In the side column")) : __("On a one-column page");

    return (
        <div className="fxc:space-y-5">
            <div>
                <h2 className="fxc:truncate fxc:text-base fxc:font-semibold">{content ? CONTENT_NAMES[block.type ?? "heading"] : block.title?.trim() || BLOCK_NAMES[block.id as BlockId]}</h2>
                <p className="fxc:text-muted-foreground fxc:text-xs">{where}</p>
                {!content && <p className="fxc:text-muted-foreground fxc:mt-3 fxc:text-xs">{BLOCK_HINTS[block.id as BlockId]}</p>}
            </div>

            {content ? (
                <>
                    <Row id={`${id}-type`} label={__("Type")}>
                        <Select
                            id={`${id}-type`}
                            className="fxc:w-full"
                            value={block.type ?? "heading"}
                            onChange={(e) => onChange({ type: e.target.value as ContentType })}
                            options={(Object.keys(CONTENT_NAMES) as ContentType[]).map((t) => ({ value: t, label: CONTENT_NAMES[t] }))}
                        />
                    </Row>
                    <Row
                        id={`${id}-text`}
                        label={__("Text")}
                        hint={block.type === "paragraph" ? __("A blank line starts a new paragraph. Links, bold and italic work: <a href=\"…\">, <strong>, <em>.") : undefined}
                        error={error("text")}
                    >
                        {block.type === "paragraph" ? (
                            <Textarea
                                id={`${id}-text`}
                                rows={5}
                                autoFocus={fresh}
                                maxLength={PARAGRAPH_MAX}
                                value={block.text ?? ""}
                                aria-invalid={error("text") ? true : undefined}
                                aria-describedby={error("text") ? `${id}-text-error` : undefined}
                                onChange={(e) => onChange({ text: e.target.value })}
                            />
                        ) : (
                            <Input
                                id={`${id}-text`}
                                autoFocus={fresh}
                                maxLength={HEADING_MAX}
                                value={block.text ?? ""}
                                aria-invalid={error("text") ? true : undefined}
                                aria-describedby={error("text") ? `${id}-text-error` : undefined}
                                onChange={(e) => onChange({ text: e.target.value })}
                            />
                        )}
                    </Row>
                </>
            ) : (
                <>
                    <Row id={`${id}-title`} label={__("Title")} hint={__("Leave empty to use the default title, translated with the store language.")} error={error("title")}>
                        <Input
                            id={`${id}-title`}
                            maxLength={LABEL_MAX}
                            value={block.title ?? ""}
                            placeholder={BLOCK_NAMES[block.id as BlockId]}
                            onChange={(e) => onChange({ title: e.target.value })}
                        />
                    </Row>
                    {block.id === "summary" && (
                        <label className="fxc:flex fxc:items-start fxc:justify-between fxc:gap-3 fxc:text-sm">
                            <span>
                                <span className="fxc:block fxc:font-medium">{__("Coupon code box")}</span>
                                <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{__("Customers can add or remove a coupon code without going back to the cart.")}</span>
                            </span>
                            <Switch checked={coupons} onCheckedChange={onCoupons} />
                        </label>
                    )}
                    {block.id === "additional" && <p className="fxc:text-muted-foreground fxc:text-xs">{__("The title shows when the section holds more than the order notes.")}</p>}
                </>
            )}

            <div className="fxc:space-y-3 fxc:border-t fxc:pt-4">
                {payment ? (
                    <p className="fxc:text-muted-foreground fxc:text-xs">{__("Payment stays at the end of its column, with the Place order button.")}</p>
                ) : (
                    <div className="fxc:flex fxc:gap-2">
                        <Button variant="ghost" size="sm" disabled={!canUp} onClick={() => onMove(-1)}>
                            <ArrowUp aria-hidden className="fxc:size-4" />
                            {__("Move up")}
                        </Button>
                        <Button variant="ghost" size="sm" disabled={!canDown} onClick={() => onMove(1)}>
                            <ArrowDown aria-hidden className="fxc:size-4" />
                            {__("Move down")}
                        </Button>
                    </div>
                )}
                {twoColumns && (
                    <Row id={`${id}-column`} label={__("Column")}>
                        <Select
                            id={`${id}-column`}
                            className="fxc:w-full"
                            value={column}
                            onChange={(e) => onColumn(e.target.value as Column)}
                            options={(Object.keys(COLUMN_NAMES) as Column[]).map((c) => ({ value: c, label: COLUMN_NAMES[c] }))}
                        />
                    </Row>
                )}
                {content && (
                    <Button variant="ghost" size="sm" className="fxc:text-destructive" onClick={onDelete}>
                        <Trash2 aria-hidden className="fxc:size-4" />
                        {sprintf(__("Delete %s"), CONTENT_NAMES[block.type ?? "heading"].toLocaleLowerCase())}
                    </Button>
                )}
            </div>
        </div>
    );
}
