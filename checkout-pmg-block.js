const settingsPMG = window.wc.wcSettings.getSetting(pmgEnvVars.PMG_PLUGIN_CODE + '_data', {});
const labelPMG = window.wp.htmlEntities.decodeEntities(settingsPMG.title) || window.wp.i18n.__('Payments Gateway', pmgEnvVars.PMG_PLUGIN_CODE);
const ContentPMG = () => {
    return window.wp.htmlEntities.decodeEntities(settingsPMG.description || '');
};
const Block_Gateway_PMG = {
    name: pmgEnvVars.PMG_PLUGIN_CODE,
    label: labelPMG,
    content: Object(window.wp.element.createElement)(ContentPMG, null),
    edit: Object(window.wp.element.createElement)(ContentPMG, null),
    canMakePayment: () => true,
    ariaLabel: labelPMG,
    supports: {
        features: settingsPMG.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway_PMG);