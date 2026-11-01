import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

/** What the list filters on: the stored status, or a card that is spent or past its date. */
export type GiftCardFilter = "" | "active" | "disabled" | "used" | "expired";

export type GiftCardState = "active" | "disabled" | "used" | "expired";

export interface GiftCardItem {
    id: number;
    /** XXXX-XXXX-XXXX-XXXX */
    code: string;
    initial_amount: number;
    balance: number;
    currency: string;
    status: "active" | "disabled";
    state: GiftCardState;
    recipient_email: string;
    recipient_name: string;
    sender_name: string;
    message: string;
    /** The order that bought it; null for a card made here. */
    order_id: number | null;
    deliver_at: string | null;
    delivered_at: string | null;
    expires_at: string | null;
    /** The last store-local day the card can pay, YYYY-MM-DD; empty when it does not expire. */
    expires_on: string;
    created_at: string | null;
}

export interface GiftCardActivityRow {
    id: number;
    type: "issued" | "redeemed" | "credited" | "adjusted";
    /** Signed, in minor units. */
    amount: number;
    balance_after: number;
    order_id: number | null;
    note: string;
    /** Who made the change; null for the shop itself. */
    user: string | null;
    created_at: string | null;
}

export interface GiftCardDetail extends GiftCardItem {
    activity: GiftCardActivityRow[];
}

export interface GiftCardList {
    items: GiftCardItem[];
    total: number;
    pages: number;
    currency: string;
    expiry_months: number;
}

export type NewGiftCard = {
    amount: number;
    recipient_email: string;
    recipient_name: string;
    sender_name: string;
    message: string;
    /** YYYY-MM-DD; empty sends now. */
    deliver_on: string;
    /** YYYY-MM-DD; empty never expires. Left out, the store default applies. */
    expires_on?: string;
    send: boolean;
    note: string;
};

export type GiftCardChanges = Partial<Pick<GiftCardItem, "status" | "recipient_email" | "recipient_name" | "sender_name" | "message" | "expires_on">>;

export const GIFT_CARDS_PER_PAGE = 20;

const KEY = ["gift-cards"] as const;

export function useGiftCardList(search: string, status: GiftCardFilter, page: number) {
    return useQuery({
        queryKey: [...KEY, "list", search, status, page],
        queryFn: () => api.get<GiftCardList>("admin/gift-cards", { search, status, page, per_page: GIFT_CARDS_PER_PAGE }),
        placeholderData: keepPreviousData,
    });
}

export function useGiftCard(id: number | null) {
    return useQuery({
        queryKey: [...KEY, "card", id],
        queryFn: () => api.get<GiftCardDetail>(`admin/gift-cards/${id}`),
        enabled: id !== null,
    });
}

function useSettle() {
    const qc = useQueryClient();
    return (card: GiftCardDetail) => {
        qc.setQueryData([...KEY, "card", card.id], card);
        void qc.invalidateQueries({ queryKey: [...KEY, "list"] });
    };
}

export function useCreateGiftCard() {
    const settle = useSettle();
    return useMutation({
        mutationFn: (input: NewGiftCard) => api.post<GiftCardDetail>("admin/gift-cards", input),
        onSuccess: settle,
    });
}

export function useUpdateGiftCard(id: number) {
    const settle = useSettle();
    return useMutation({
        mutationFn: (changes: GiftCardChanges) => api.patch<GiftCardDetail>(`admin/gift-cards/${id}`, changes),
        onSuccess: settle,
    });
}

export function useAdjustGiftCard(id: number) {
    const settle = useSettle();
    return useMutation({
        mutationFn: (input: { amount: number; note: string }) => api.post<GiftCardDetail>(`admin/gift-cards/${id}/adjust`, input),
        onSuccess: settle,
    });
}

export function useSendGiftCard(id: number) {
    const settle = useSettle();
    return useMutation({
        mutationFn: () => api.post<GiftCardDetail>(`admin/gift-cards/${id}/send`, {}),
        onSuccess: settle,
    });
}
