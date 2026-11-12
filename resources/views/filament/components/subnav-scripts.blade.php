<style>
    .fi-page-sub-navigation-sidebar-ctn {
        width: var(--sidebar-width);
    }

    .fi-page-sub-navigation-sidebar-ctn.fi-subnav-collapsed,
    html.fi-subnav-collapsed .fi-page-sub-navigation-sidebar-ctn {
        width: var(--collapsed-sidebar-width);
    }
</style>

<script>
    (function () {
        var match = document.cookie.split('; ').find(function (row) {
            return row.startsWith('subnav_collapsed=');
        });

        if (match && match.split('=')[1] === 'true') {
            document.documentElement.classList.add('fi-subnav-collapsed');
        }
    })();
</script>

<script>
    document.addEventListener('alpine:init', () => {
        if (Alpine.store('subnav')) {
            return;
        }

        const persist = (isOpen) => {
            document.cookie = `subnav_collapsed=${isOpen ? 'false' : 'true'}; path=/; max-age=31536000; SameSite=Lax`;
        };

        const syncTooltips = (isOpen) => {
            const sidebar = document.querySelector('.fi-page-sub-navigation-sidebar');

            if (! sidebar) {
                return;
            }

            sidebar.querySelectorAll('.fi-sidebar-item-btn').forEach((button) => {
                button.removeAttribute('data-subnav-tooltip');
                button.removeAttribute('aria-label');
            });

            if (isOpen) {
                return;
            }

            sidebar.querySelectorAll('.fi-sidebar-item').forEach((item) => {
                const button = item.querySelector('.fi-sidebar-item-btn');
                const label = item.querySelector('.fi-sidebar-item-label');

                if (! button || ! label) {
                    return;
                }

                const labelText = label.textContent.trim();

                if (! labelText) {
                    return;
                }

                button.setAttribute('data-subnav-tooltip', labelText);
                button.setAttribute('aria-label', labelText);
            });
        };

        Alpine.store('subnav', {
            isOpen: ! document.documentElement.classList.contains('fi-subnav-collapsed'),

            open() {
                this.isOpen = true;
                document.documentElement.classList.remove('fi-subnav-collapsed');
                persist(true);
                syncTooltips(true);
            },

            close() {
                this.isOpen = false;
                document.documentElement.classList.add('fi-subnav-collapsed');
                persist(false);
                syncTooltips(false);
            },

            toggle() {
                this.isOpen ? this.close() : this.open();
            },
        });

        const sync = () => {
            document.documentElement.classList.add('fi-subnav-ready');
            const store = Alpine.store('subnav');

            if (! store) {
                return;
            }

            if (store.isOpen) {
                document.documentElement.classList.remove('fi-subnav-collapsed');
            } else {
                document.documentElement.classList.add('fi-subnav-collapsed');
            }

            syncTooltips(store.isOpen);
        };

        setTimeout(sync, 50);
        document.addEventListener('livewire:navigated', sync);
    });
</script>
