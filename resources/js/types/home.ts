export type HomePeriod = 'daily' | 'weekly' | 'monthly';

export interface HomeRange {
    start: Date;
    end: Date;
}

export interface HomeStat {
    title: string;
    icon: string;
    value: number | string;
    variation: number;
}

export interface HomeChartPoint {
    date: string;
    amount: number;
}

export type HomeSaleStatus = 'paid' | 'failed' | 'refunded';

export interface HomeSale {
    id: string;
    date: string;
    status: HomeSaleStatus;
    email: string;
    amount: number;
}

export interface HomeProps {
    range: {
        start: string;
        end: string;
    };
    period: HomePeriod;
    stats: HomeStat[];
    chart: HomeChartPoint[];
    sales: HomeSale[];
}
