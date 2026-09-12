export type PlanUsageDimension = {
    used: number;
    max: number | null;
};

export type PlanUsageVolume = {
    used: number;
    max: number | null;
    remaining: number | null;
};

export type PlanUsage = {
    users: PlanUsageDimension;
    clients: PlanUsageDimension;
    volume: PlanUsageVolume;
    modules: string[];
};

export type PlatformPermissions = {
    'manage-users': boolean;
    'manage-clients': boolean;
    'manage-platform': boolean;
    operate: boolean;
};
