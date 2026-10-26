/* Rechte des Plugins für die Rollen-Verwaltung.
 *
 * Ohne diese Registrierung fordern die sieben Admin-Endpunkte zwar
 * `rc_media_toolkit.viewer` bzw. `.editor`, aber in der Rollen-Verwaltung taucht nichts
 * auf, dem sich das zuweisen ließe. Wer keine Administrator-Rolle hat, bekommt 403 —
 * und niemand kann es beheben. Sicher war das, brauchbar nicht.
 *
 * Der Schnitt ist bewusst grob: `viewer` liest, `editor` löst aus. Feiner zu trennen
 * hieße, dem Betreiber Entscheidungen abzuverlangen, die er ohne Kenntnis der Endpunkte
 * nicht treffen kann — und die Endpunkte selbst kennen ohnehin nur diese beiden Stufen.
 *
 * Die zweite Stufe heißt `editor` und nicht etwa `manager`, obwohl „auslösen" das besser
 * träfe: Das Rechte-Raster des Cores hat vier feste Spalten (`viewer`, `editor`,
 * `creator`, `deleter`). Ein Name daneben bekommt keine Spalte und ist nur über „Alle"
 * zu vergeben — alles oder nichts. Im Browser nachgesehen, nicht vermutet. Wie die Spalte
 * für dieses Plugin zu lesen ist, sagt die Beschriftung aus der Sprachdatei.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'content',
    key: 'rc_media_toolkit',
    roles: {
        viewer: {
            privileges: [
                'rc_media_toolkit.viewer',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'rc_media_toolkit.editor',
            ],
            // „Auslösen" ohne „Lesen" ergibt keinen Zustand, den jemand haben will:
            // Wer einen Lauf startet, muss sein Ergebnis sehen können.
            dependencies: [
                'rc_media_toolkit.viewer',
            ],
        },
    },
});
