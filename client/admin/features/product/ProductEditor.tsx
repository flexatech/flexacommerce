import { showToast } from "@/shared/components/showToast";
import { ProductDataPanel } from "./ProductDataPanel";
import { useProductDraft } from "./useProductDraft";
import { editorErrorNotice, useSaveWithPost } from "./useSaveWithPost";

/**
 * Metabox host (`flexacommerce.admin.product_editor` = "post"): the WordPress
 * post editor owns title, content and status; this saves the product data
 * alongside each post save and reports errors as editor notices.
 */
export function ProductEditor({ productId }: { productId: number }) {
    const editor = useProductDraft(productId, (message) => {
        if (!editorErrorNotice(message)) {
            showToast.error(message);
        }
    });
    useSaveWithPost(editor.submit);
    return <ProductDataPanel editor={editor} />;
}
