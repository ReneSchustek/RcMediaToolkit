import './page/rc-media-toolkit-overview';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('rc-media-toolkit', {
    type: 'plugin',
    name: 'RcMediaToolkit',
    title: 'rc-media-toolkit.general.mainMenuItemGeneral',
    description: 'rc-media-toolkit.general.description',
    color: '#7d8b9c',
    icon: 'regular-image',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        overview: {
            component: 'rc-media-toolkit-overview',
            path: 'overview',
            meta: {
                privilege: 'rc_media_toolkit.viewer',
            },
        },
    },

    navigation: [{
        label: 'rc-media-toolkit.general.mainMenuItemGeneral',
        color: '#7d8b9c',
        path: 'rc.media.toolkit.overview',
        icon: 'regular-image',
        parent: 'sw-content',
        position: 80,
    }],
});
