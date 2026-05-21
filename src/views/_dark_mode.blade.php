@php
    $kcDark = \Kamva\Crud\KamvaCrud::getDarkMode(); // 'auto' | 'dark' | 'light'
    $kcAttr = $kcDark !== 'auto' ? ' data-kc-dark="' . e($kcDark) . '"' : '';
@endphp
{{-- Dark-mode CSS for kamva-crud package elements.
     Wrap the view's root element with class="kamva-crud-wrap" and
     optionally data-kc-dark="dark|light" (omit for system auto). --}}
<style>
    .kamva-crud-wrap {
        --kc-bg:          #ffffff;
        --kc-surface:     #f4f5f7;
        --kc-card-bg:     #ffffff;
        --kc-card-border: rgba(0, 0, 0, 0.08);
        --kc-text:        #212529;
        --kc-text-muted:  #718096;
        --kc-header-bg:   #f8f9fa;
        --kc-link:        inherit;
    }

    @media (prefers-color-scheme: dark) {
        .kamva-crud-wrap:not([data-kc-dark="light"]) {
            --kc-bg:          #0f0f1a;
            --kc-surface:     #1c1c2e;
            --kc-card-bg:     #1e1e30;
            --kc-card-border: rgba(255, 255, 255, 0.08);
            --kc-text:        #e2e8f0;
            --kc-text-muted:  #a0aec0;
            --kc-header-bg:   #252540;
            --kc-link:        #e2e8f0;
        }
    }

    .kamva-crud-wrap[data-kc-dark="dark"] {
        --kc-bg:          #0f0f1a;
        --kc-surface:     #1c1c2e;
        --kc-card-bg:     #1e1e30;
        --kc-card-border: rgba(255, 255, 255, 0.08);
        --kc-text:        #e2e8f0;
        --kc-text-muted:  #a0aec0;
        --kc-header-bg:   #252540;
        --kc-link:        #e2e8f0;
    }

    /* Kanban board */
    .kamva-crud-wrap .kamva-kanban-col {
        background: var(--kc-surface) !important;
        color: var(--kc-text);
    }
    .kamva-crud-wrap .kamva-kanban-card {
        background: var(--kc-card-bg) !important;
        border-color: var(--kc-card-border) !important;
        color: var(--kc-text);
    }
    .kamva-crud-wrap .kamva-kanban-card a {
        color: var(--kc-link);
    }
    .kamva-crud-wrap .kamva-kanban-card .kc-muted {
        color: var(--kc-text-muted);
    }

    /* Bootstrap card overrides for detail view */
    .kamva-crud-wrap .card {
        background-color: var(--kc-card-bg);
        border-color: var(--kc-card-border);
        color: var(--kc-text);
    }
    .kamva-crud-wrap .card-header {
        background-color: var(--kc-header-bg);
        border-color: var(--kc-card-border);
        color: var(--kc-text);
    }
    .kamva-crud-wrap .text-muted {
        color: var(--kc-text-muted) !important;
    }
    .kamva-crud-wrap .border-bottom {
        border-color: var(--kc-card-border) !important;
    }
    .kamva-crud-wrap .alert-light {
        background-color: var(--kc-surface);
        border-color: var(--kc-card-border);
        color: var(--kc-text);
    }
</style>
