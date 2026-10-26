import template from './rc-media-toolkit-overview.html.twig';

const { Component, Mixin } = Shopware;

/*
 * Die Übersichtsseite des Werkzeugkastens — eine Auskunft, kein Bedienpult.
 *
 * Sie löst bewusst nichts aus. Der gefährlichste Vorgang des Plugins (`rc:media:gc:purge`)
 * ist der einzige ohne Rückweg, und auf der Konsole steht davor eine Rückfrage, die man
 * tippen muss. Ein Knopf im Browser wäre der bequemere Weg zum unumkehrbaren Löschen —
 * genau den soll es hier nicht geben. Deshalb kommt die Seite mit dem lesenden Recht aus.
 */
Component.register('rc-media-toolkit-overview', {
    template,

    /*
     * `syncService` und nicht `this.$http`: Letzteres gibt es in Shopware 6.7 nicht — es steht
     * in den globalen Eigenschaften der Anwendung schlicht nicht drin. Mit ihm scheiterte der
     * Aufruf sofort, der `catch` verwandelte das in eine Meldung, und die Seite bliebe leer,
     * ohne dass irgendwo ein Fehler stünde.
     */
    inject: ['acl', 'syncService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            status: null,
            isLoading: false,
        };
    },

    computed: {
        /* Die Modulnamen kommen als interne Schlüssel herein und werden hier übersetzt. */
        moduleRows() {
            const modules = this.status?.modules;
            if (!modules) {
                return [];
            }

            return Object.keys(modules).map((key) => ({
                name: this.$tc(`rc-media-toolkit.overview.module.${key}`),
                state: modules[key]
                    ? this.$tc('rc-media-toolkit.overview.stateActive')
                    : this.$tc('rc-media-toolkit.overview.stateInactive'),
            }));
        },

        severityRows() {
            const bySeverity = this.status?.health?.openBySeverity ?? {};

            return Object.keys(bySeverity).map((severity) => ({
                severity,
                count: bySeverity[severity],
            }));
        },

        /*
         * Die Bestandszahlen sind eine Stichprobe — die Abfrage sieht sich höchstens 1000
         * Medien an. Ohne diesen Hinweis liest sich „mit WebP: 279" auf einem Shop mit
         * 50.000 Bildern als Gesamtzahl und ist keine. Die Gesamtzahl der Medien ist
         * dagegen echt gezählt, deshalb steht sie getrennt.
         */
        webpRows() {
            const stats = this.status?.webp?.stats;
            if (!stats) {
                return [];
            }

            return [
                { label: this.$tc('rc-media-toolkit.overview.webpTotal'), value: stats.total },
                { label: this.$tc('rc-media-toolkit.overview.webpEligible'), value: stats.eligible },
                { label: this.$tc('rc-media-toolkit.overview.webpWith'), value: stats.withWebp },
                { label: this.$tc('rc-media-toolkit.overview.webpWithout'), value: stats.withoutWebp },
            ];
        },

        encoderRows() {
            const encoders = this.status?.webp?.encoders ?? {};

            return Object.keys(encoders).map((name) => ({
                name,
                state: encoders[name]
                    ? this.$tc('rc-media-toolkit.overview.encoderAvailable')
                    : this.$tc('rc-media-toolkit.overview.encoderMissing'),
            }));
        },
    },

    created() {
        this.loadStatus();
    },

    methods: {
        async loadStatus() {
            this.isLoading = true;
            try {
                // Die Grundadresse des Clients endet bereits auf `/api`.
                const response = await this.syncService.httpClient.get(
                    '/_action/rc-media-toolkit/status',
                    { headers: this.syncService.getBasicHeaders() },
                );
                this.status = response.data;
            } catch (e) {
                // Der Grund gehört in die Meldung. Ohne ihn sah ein fehlender Aufrufweg genauso
                // aus wie ein Rechteproblem oder ein Serverfehler — und genau daran ist der
                // Ausfall in 6.7 unentdeckt geblieben.
                this.createNotificationError({
                    message: `${this.$tc('rc-media-toolkit.overview.loadError')} ${e?.message ?? e}`,
                });
            } finally {
                this.isLoading = false;
            }
        },
    },
});
