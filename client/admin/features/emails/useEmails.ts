import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/lib/api";

export interface EmailValues {
    enabled: boolean;
    recipients: string;
    subject: string;
    heading: string;
}

export interface OrderEmail extends EmailValues {
    id: string;
    title: string;
    to_customer: boolean;
}

export interface EmailsResponse {
    emails: OrderEmail[];
    admin_email: string;
    placeholders: string[];
}

const EMAILS_KEY = ["emails"] as const;

export function useEmails() {
    return useQuery({
        queryKey: EMAILS_KEY,
        queryFn: () => api.get<EmailsResponse>("admin/emails"),
    });
}

/** Sends only the changed values; the server merges them over what is stored. */
export function useSaveEmail() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ id, changes }: { id: string; changes: Partial<EmailValues> }) =>
            api.patch<OrderEmail>(`admin/emails/${encodeURIComponent(id)}`, changes),
        onSuccess: (email) =>
            qc.setQueryData<EmailsResponse>(EMAILS_KEY, (data) =>
                data ? { ...data, emails: data.emails.map((e) => (e.id === email.id ? email : e)) } : data,
            ),
    });
}
