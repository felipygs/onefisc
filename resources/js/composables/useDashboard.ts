import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { dashboard } from '@/routes';
import { index as clientsIndex } from '@/routes/clients';
import { edit as editProfile } from '@/routes/profile';

const isNotificationsSlideoverOpen = ref(false);

export function useDashboard(): {
    isNotificationsSlideoverOpen: typeof isNotificationsSlideoverOpen;
} {
    return { isNotificationsSlideoverOpen };
}

function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return (
        tag === 'INPUT' ||
        tag === 'TEXTAREA' ||
        tag === 'SELECT' ||
        target.isContentEditable
    );
}

let pendingG = false;
let pendingTimer: ReturnType<typeof setTimeout> | undefined;

function resetPending(): void {
    pendingG = false;

    if (pendingTimer !== undefined) {
        clearTimeout(pendingTimer);
        pendingTimer = undefined;
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    if (pendingG) {
        const key = event.key.toLowerCase();

        if (key === 'h') {
            resetPending();
            router.visit(dashboard.url());
            return;
        }

        if (key === 'c') {
            resetPending();
            router.visit(clientsIndex.url());
            return;
        }

        if (key === 's') {
            resetPending();
            router.visit(editProfile.url());
            return;
        }

        resetPending();
        return;
    }

    if (isEditableTarget(event.target)) {
        return;
    }

    if (event.key === 'n') {
        isNotificationsSlideoverOpen.value =
            !isNotificationsSlideoverOpen.value;
        return;
    }

    if (event.key.toLowerCase() === 'g') {
        pendingG = true;
        pendingTimer = setTimeout(resetPending, 1000);
    }
}

function onNavigate(): void {
    isNotificationsSlideoverOpen.value = false;
}

/**
 * Registers dashboard shell shortcuts and navigation hooks once per shell
 * mount. Returns a cleanup function for unmount.
 */
export function setupDashboardShortcuts(): () => void {
    window.addEventListener('keydown', onKeydown);
    const removeNavigateListener = router.on('navigate', onNavigate);

    return () => {
        window.removeEventListener('keydown', onKeydown);
        removeNavigateListener();
        resetPending();
    };
}
