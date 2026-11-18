export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type ImageAvailability = 'ready' | 'preparing' | 'unavailable';

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    estimated_cost: string;
    estimated_cost_max: string;
    charged_cost: string | null;
    charged_cost_exact: string | null;
    error_message: string | null;
    image_availability: ImageAvailability | null;
    image_url: string | null;
    is_active: boolean;
    created_at: string;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number };
};

export type Estimate = {
    min: string;
    expected: string;
    max: string;
};

export type EstimateResponse = {
    estimate: Estimate;
    available: string;
    can_afford: boolean;
};

export type Option = {
    value: string;
    label: string;
};

export type CreditPack = {
    value: string;
    label: string;
};

export type CreditTransaction = {
    id: string;
    type: 'purchase' | 'charge';
    amount: string;
    amount_exact: string;
    created_at: string;
};
