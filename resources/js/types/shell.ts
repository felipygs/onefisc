export type SwitchableAccount = {
    id: number;
    name: string;
};

export type ShellNotificationSender = {
    name: string;
};

export type ShellNotification = {
    id: number;
    sender: ShellNotificationSender;
    body: string;
    date: string | null;
};
