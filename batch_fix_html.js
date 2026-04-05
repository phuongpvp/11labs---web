const fs = require('fs');

const files = [
    'customer.html',
    'dubbing.html',
    'isolator.html',
    'music.html',
    'speech-to-text.html',
    'voice_changer.html',
    'sound-effect.html'
];

files.forEach(f => {
    if (!fs.existsSync(f)) return;
    let content = fs.readFileSync(f, 'utf8');

    // 1. Fix "Hết hạn:" hardcodes to use I18n.t
    // Pattern: `Hết hạn: \${
    content = content.replace(
        /`Hết hạn: \$\{/g,
        '`${window.I18n && window.I18n.t ? window.I18n.t("customer.expire", "Hết hạn:") : "Hết hạn:"} ${'
    );
    // Pattern for conversation.html where string is 'Hết hạn:'
    content = content.replace(
        /'Hết hạn:'/g,
        'window.I18n && window.I18n.t ? window.I18n.t("customer.expire", "Hết hạn:") : "Hết hạn:"'
    );
    
    // 2. Ensure I18n translation variables are properly defined if they're currently missing
    // In some pages, they might have let quotaPrefix = 'Còn lại:' which needs to be replaced.
    content = content.replace(
        /let quotaPrefix = 'Còn lại:';/g,
        "let quotaPrefix = window.I18n && window.I18n.t ? window.I18n.t('customer.quota_left', 'Còn lại:') : 'Còn lại:';"
    );
    content = content.replace(
        /let quotaSuffix = 'ký tự';/g,
        "let quotaSuffix = window.I18n && window.I18n.t ? window.I18n.t('tool_shared.char_label', 'Ký tự').toLowerCase() : 'ký tự';"
    );

    // 3. Inject event listener
    // If we have updateUserInfo
    if (content.includes('function updateUserInfo()') && !content.includes("addEventListener('languageChanged'")) {
        content = content.replace(
            /(function updateUserInfo\(\) \{[\s\S]*?^\s*\})/m,
            "$1\n        window.addEventListener('languageChanged', () => { updateUserInfo(); });"
        );
    }
    
    // If we have updateDashboard
    if (content.includes('function updateDashboard()') && !content.includes("addEventListener('languageChanged'")) {
        content = content.replace(
            /(function updateDashboard\(\) \{[\s\S]*?^\s*\})/m,
            "$1\n        window.addEventListener('languageChanged', () => { updateDashboard(); });"
        );
    }

    fs.writeFileSync(f, content);
    console.log('Patched', f);
});
