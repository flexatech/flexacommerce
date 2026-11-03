import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export type BlockType =
    | "logo"
    | "heading"
    | "text"
    | "button"
    | "image"
    | "divider"
    | "spacer"
    | "message"
    | "order_details"
    | "addresses"
    | "downloads"
    | "shipments"
    | "footer";

export type Align = "left" | "center" | "right";

export interface DesignBlock {
    id: string;
    type: BlockType;
    props: Record<string, string | number>;
}

export interface Design {
    blocks: DesignBlock[];
}

export interface MailStyle {
    logo_id: number;
    logo_url: string;
    logo_width: number;
    brand: string;
    button_text: string;
    background: string;
    body: string;
    text: string;
    font: string;
    footer: string;
}

export interface DesignResponse {
    email: { id: string; title: string; to_customer: boolean };
    /** Null while the email uses the default layout. */
    design: Design | null;
    starter: Design;
    style: MailStyle;
    defaults: MailStyle;
    fonts: string[];
    tokens: string[];
    orders: { id: number; label: string }[];
    emails: { id: string; title: string; custom: boolean }[];
    test_to: string;
}

export interface PreviewInput {
    design: Design | null;
    style: MailStyle;
    order_id: number;
}

const key = (id: string) => ["emails", id, "design"] as const;

export function useEmailDesign(id: string) {
    return useQuery({
        queryKey: key(id),
        queryFn: () => api.get<DesignResponse>(`admin/emails/${encodeURIComponent(id)}/design`),
        enabled: id !== "",
    });
}

/** Saves the design (null goes back to the default layout). */
export function useSaveDesign(id: string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (design: Design | null) => api.put<{ design: Design | null }>(`admin/emails/${encodeURIComponent(id)}/design`, { design }),
        onSuccess: ({ design }) => {
            qc.setQueryData<DesignResponse>(key(id), (data) =>
                data ? { ...data, design, emails: data.emails.map((e) => (e.id === id ? { ...e, custom: design !== null } : e)) } : data,
            );
            void qc.invalidateQueries({ queryKey: ["emails"], exact: true });
        },
    });
}

/** The style every email shares; each email's cached design data takes the new one. */
export function useSaveStyle() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (style: Partial<MailStyle>) => api.put<{ style: MailStyle }>("admin/email-style", { style }),
        onSuccess: ({ style }) => {
            qc.setQueriesData<DesignResponse>({ queryKey: ["emails"] }, (data) => (data && "style" in data ? { ...data, style } : data));
        },
    });
}

/** The email as it would go out with what is on screen. Pass null to wait. */
export function useDesignPreview(id: string, input: PreviewInput | null) {
    return useQuery({
        queryKey: ["emails", id, "preview", input],
        queryFn: () => api.post<{ subject: string; html: string }>(`admin/emails/${encodeURIComponent(id)}/preview`, { ...input }),
        enabled: input !== null,
        placeholderData: keepPreviousData,
        staleTime: 60_000,
    });
}

export function useSendTest(id: string) {
    return useMutation({
        mutationFn: (input: PreviewInput & { to: string }) => api.post<{ sent: string[] }>(`admin/emails/${encodeURIComponent(id)}/test`, { ...input }),
    });
}
