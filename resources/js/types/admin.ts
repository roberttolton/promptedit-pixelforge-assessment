export type SpendFigures = {
    generations: number;
    charged: string;
    charged_exact: string;
    provider: string;
    provider_exact: string;
    profit: string;
    profit_exact: string;
    pixelforge: string | null;
    pixelforge_exact: string | null;
    matches: boolean | null;
};

export type SpendUserRow = Omit<
    SpendFigures,
    'pixelforge' | 'pixelforge_exact' | 'matches'
> & {
    user_id: string;
    name: string;
    email: string;
};

export type SpendDayRow = SpendFigures & {
    date: string;
    users: SpendUserRow[];
};
