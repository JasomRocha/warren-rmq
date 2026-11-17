<script setup lang="ts">
/** The few icons the interface needs, drawn as 1.6px outlines so they sit quietly next to text. */
defineProps<{ name: string; size?: number }>();

const icons: Record<string, string> = {
    dashboard: '<rect x="3.5" y="3.5" width="7" height="9" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="5" rx="1.5"/><rect x="13.5" y="12.5" width="7" height="8" rx="1.5"/><rect x="3.5" y="16.5" width="7" height="4" rx="1.5"/>',
    flow: '<circle cx="5.5" cy="6.5" r="2.3"/><circle cx="5.5" cy="17.5" r="2.3"/><circle cx="18.5" cy="12" r="2.3"/><path d="M7.6 7.2 16.4 11M7.6 16.8l8.8-3.8"/>',
    insights: '<path d="M5 20V11M12 20V4M19 20v-6"/>',
    inbox: '<path d="M4 13l2.4-7h11.2L20 13v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/><path d="M4 13h4.5a3.5 3.5 0 0 0 7 0H20"/>',
    alert: '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.2v.1"/>',
    messages: '<path d="M4 6.5h16M4 12h16M4 17.5h10"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.1"/>',
    plug: '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v4"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
    moon: '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    chevron: '<path d="M6 9l6 6 6-6"/>',
    pause: '<path d="M8.5 5v14M15.5 5v14"/>',
    play: '<path d="M7 4.5l12 7.5-12 7.5z"/>',
    logout: '<path d="M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10"/><path d="M15 8l4 4-4 4M19 12H9"/>',
    users: '<circle cx="9" cy="8" r="3"/><path d="M3.5 19c0-3.2 2.4-5 5.5-5s5.5 1.8 5.5 5"/><circle cx="17" cy="9" r="2.3"/><path d="M17 14c2.4.2 4 1.6 4 4"/>',
    edit: '<path d="M4 20h4L19.2 8.8a2.1 2.1 0 0 0-3-3L5 17z"/>',
    trash: '<path d="M5 7h14M10 7V4.5h4V7M7 7l1 13h8l1-13"/>',
    refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
    server: '<rect x="3.5" y="4" width="17" height="6.5" rx="2"/><rect x="3.5" y="13.5" width="17" height="6.5" rx="2"/><path d="M7 7.25v.1M7 16.75v.1"/>',
    eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
    'eye-off': '<path d="M3 3l18 18M10.6 5.7A9 9 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a16 16 0 0 1-2.6 3.4M6.5 7.3A15 15 0 0 0 2.5 12S6 18.5 12 18.5a9 9 0 0 0 4-.9"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    lanes: '<rect x="3" y="5" width="4" height="4" rx="1"/><rect x="8.5" y="5" width="4" height="4" rx="1"/><path d="M15 7h6"/><rect x="3" y="15" width="4" height="4" rx="1"/><path d="M9.5 17H21"/>',
    sidebar: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M9.5 4.5v15"/>',
};
</script>

<template>
    <svg
        class="icon"
        :width="size ?? 18"
        :height="size ?? 18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        v-html="icons[name] ?? ''"
    />
</template>
