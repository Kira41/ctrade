let dashboardData = null;
// Retrieve the current user ID from localStorage if available.
let userId;
try {
    userId = localStorage.getItem('user_id');
} catch (e) {
    userId = null;
}
userId = userId ? parseInt(userId) : null;

let dashboardInitialized = false;
let autoRefreshHandle = null;
let tradePending = false;
// Block automatic balance refreshes while a trade is being submitted
let balanceUpdateLockUntil = 0;
// Track a balance set locally (e.g. after a trade) until the server confirms it
let pendingBalance = null;
let lastTradeTime = 0;
try {
    lastTradeTime = parseInt(localStorage.getItem('last_trade_time')) || 0;
} catch (e) {
    lastTradeTime = 0;
}
// Expose the latest fetched price globally for other scripts
var currentPrice = 0;
// Track the currently selected trading pair
let selectedPairVal = $('#currencyPair').val();
let selectedPairText = $('#currencyPair option:selected').text();
let priceInterval = null;
let priceFetchController = null;

// Trigger immediate refresh on user interactions
function triggerTurboRefresh() {
    if (!userId) return;
    fetchDashboardData();
    fetchWallets();
}
['click', 'input', 'change', 'drop'].forEach(evt => {
    document.addEventListener(evt, triggerTurboRefresh, true);
});

// Utility functions
function parseDollar(str) {
    return parseFloat(String(str).replace(/[^0-9.-]+/g, '')) || 0;
}

function formatDollar(num) {
    const hasDecimals = Number(num) % 1 !== 0;
    return Number(num).toLocaleString('en-US', {
        minimumFractionDigits: hasDecimals ? 2 : 0,
        maximumFractionDigits: hasDecimals ? 2 : 0
    }) + ' $';
}

function formatCrypto(num) {
    return Number(num).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 8
    });
}

function formatCryptoFixed(num, digits = 5) {
    return Number(num).toLocaleString('en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits
    });
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/'/g, '&#39;')
        .replace(/"/g, '&quot;');
}

function showBootstrapAlert(containerId, message, type = 'success') {
    const icons = {
        success: 'fa-check-circle',
        danger: 'fa-exclamation-triangle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    const icon = icons[type] || icons.info;
    const alertHtml = `
        <div class="alert alert-${escapeHtml(type)} alert-dismissible fade show" role="alert">
            <i class="fas ${icon} me-2"></i>${escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`;
    $('#' + containerId).html(alertHtml);
}

function progressToColor(percent) {
    if (percent >= 100) return '#198754';
    const r = Math.round(255 * (100 - percent) / 100);
    const g = Math.round(255 * percent / 100);
    return `rgb(${r},${g},0)`;
}

// Validate credit card numbers using the Luhn algorithm
function isValidCardNumber(num) {
    const digits = String(num).replace(/\D/g, '');
    let sum = 0;
    let shouldDouble = false;
    for (let i = digits.length - 1; i >= 0; i--) {
        let digit = parseInt(digits.charAt(i), 10);
        if (shouldDouble) {
            digit *= 2;
            if (digit > 9) digit -= 9;
        }
        sum += digit;
        shouldDouble = !shouldDouble;
    }
    return digits.length > 0 && sum % 10 === 0;
}

// Expose hashing utilities globally so they can be used before the
// dashboard UI is initialized (e.g. during login).
async function hashPassword(pwd) {
    return md5(pwd);
}

// Minimal MD5 implementation
function md5(str) {
    function cmn(q, a, b, x, s, t) {
        a = (a + q + x + t) | 0;
        return (((a << s) | (a >>> (32 - s))) + b) | 0;
    }
    function ff(a, b, c, d, x, s, t) {
        return cmn((b & c) | (~b & d), a, b, x, s, t);
    }
    function gg(a, b, c, d, x, s, t) {
        return cmn((b & d) | (c & ~d), a, b, x, s, t);
    }
    function hh(a, b, c, d, x, s, t) {
        return cmn(b ^ c ^ d, a, b, x, s, t);
    }
    function ii(a, b, c, d, x, s, t) {
        return cmn(c ^ (b | ~d), a, b, x, s, t);
    }

    function md5cycle(x, k) {
        let a = x[0], b = x[1], c = x[2], d = x[3];

        a = ff(a, b, c, d, k[0], 7 , -680876936);
        d = ff(d, a, b, c, k[1], 12, -389564586);
        c = ff(c, d, a, b, k[2], 17,  606105819);
        b = ff(b, c, d, a, k[3], 22, -1044525330);
        a = ff(a, b, c, d, k[4], 7 , -176418897);
        d = ff(d, a, b, c, k[5], 12,  1200080426);
        c = ff(c, d, a, b, k[6], 17, -1473231341);
        b = ff(b, c, d, a, k[7], 22, -45705983);
        a = ff(a, b, c, d, k[8], 7 ,  1770035416);
        d = ff(d, a, b, c, k[9], 12, -1958414417);
        c = ff(c, d, a, b, k[10],17, -42063);
        b = ff(b, c, d, a, k[11],22, -1990404162);
        a = ff(a, b, c, d, k[12],7 ,  1804603682);
        d = ff(d, a, b, c, k[13],12, -40341101);
        c = ff(c, d, a, b, k[14],17, -1502002290);
        b = ff(b, c, d, a, k[15],22,  1236535329);

        a = gg(a, b, c, d, k[1], 5 , -165796510);
        d = gg(d, a, b, c, k[6], 9 , -1069501632);
        c = gg(c, d, a, b, k[11],14,  643717713);
        b = gg(b, c, d, a, k[0], 20, -373897302);
        a = gg(a, b, c, d, k[5], 5 , -701558691);
        d = gg(d, a, b, c, k[10],9 ,  38016083);
        c = gg(c, d, a, b, k[15],14, -660478335);
        b = gg(b, c, d, a, k[4], 20, -405537848);
        a = gg(a, b, c, d, k[9], 5 ,  568446438);
        d = gg(d, a, b, c, k[14],9 , -1019803690);
        c = gg(c, d, a, b, k[3], 14, -187363961);
        b = gg(b, c, d, a, k[8], 20,  1163531501);
        a = gg(a, b, c, d, k[13],5 , -1444681467);
        d = gg(d, a, b, c, k[2], 9 , -51403784);
        c = gg(c, d, a, b, k[7], 14,  1735328473);
        b = gg(b, c, d, a, k[12],20, -1926607734);

        a = hh(a, b, c, d, k[5], 4 , -378558);
        d = hh(d, a, b, c, k[8], 11, -2022574463);
        c = hh(c, d, a, b, k[11],16,  1839030562);
        b = hh(b, c, d, a, k[14],23, -35309556);
        a = hh(a, b, c, d, k[1], 4 , -1530992060);
        d = hh(d, a, b, c, k[4], 11,  1272893353);
        c = hh(c, d, a, b, k[7], 16, -155497632);
        b = hh(b, c, d, a, k[10],23, -1094730640);
        a = hh(a, b, c, d, k[13],4 ,  681279174);
        d = hh(d, a, b, c, k[0], 11, -358537222);
        c = hh(c, d, a, b, k[3], 16, -722521979);
        b = hh(b, c, d, a, k[6], 23,  76029189);
        a = hh(a, b, c, d, k[9], 4 , -640364487);
        d = hh(d, a, b, c, k[12],11, -421815835);
        c = hh(c, d, a, b, k[15],16,  530742520);
        b = hh(b, c, d, a, k[2], 23, -995338651);

        a = ii(a, b, c, d, k[0], 6 , -198630844);
        d = ii(d, a, b, c, k[7], 10,  1126891415);
        c = ii(c, d, a, b, k[14],15, -1416354905);
        b = ii(b, c, d, a, k[5], 21, -57434055);
        a = ii(a, b, c, d, k[12],6 ,  1700485571);
        d = ii(d, a, b, c, k[3], 10, -1894986606);
        c = ii(c, d, a, b, k[10],15, -1051523);
        b = ii(b, c, d, a, k[1], 21, -2054922799);
        a = ii(a, b, c, d, k[8], 6 ,  1873313359);
        d = ii(d, a, b, c, k[15],10, -30611744);
        c = ii(c, d, a, b, k[6], 15, -1560198380);
        b = ii(b, c, d, a, k[13],21,  1309151649);
        a = ii(a, b, c, d, k[4], 6 , -145523070);
        d = ii(d, a, b, c, k[11],10, -1120210379);
        c = ii(c, d, a, b, k[2], 15,  718787259);
        b = ii(b, c, d, a, k[9], 21, -343485551);

        x[0] = (a + x[0]) | 0;
        x[1] = (b + x[1]) | 0;
        x[2] = (c + x[2]) | 0;
        x[3] = (d + x[3]) | 0;
    }

    function md51(s) {
        const txt = unescape(encodeURIComponent(s));
        const n = txt.length;
        const state = [1732584193, -271733879, -1732584194, 271733878];
        for (let i = 64; i <= n; i += 64) {
            md5cycle(state, md5blk(txt.substring(i - 64, i)));
        }
        let tail = new Array(16).fill(0);
        let i = 0;
        for (; i < n % 64; i++) {
            tail[i >> 2] |= txt.charCodeAt(n - (n % 64) + i) << ((i % 4) << 3);
        }
        tail[i >> 2] |= 0x80 << ((i % 4) << 3);
        if (i > 55) {
            md5cycle(state, tail);
            tail = new Array(16).fill(0);
        }
        tail[14] = n * 8;
        md5cycle(state, tail);
        return state;
    }

    function md5blk(s) {
        const md5blks = [];
        for (let i = 0; i < 64; i += 4) {
            md5blks[i >> 2] = s.charCodeAt(i) +
                (s.charCodeAt(i + 1) << 8) +
                (s.charCodeAt(i + 2) << 16) +
                (s.charCodeAt(i + 3) << 24);
        }
        return md5blks;
    }

    function rhex(n) {
        let s = '';
        for (let j = 0; j < 4; j++) {
            s += ((n >> (j * 8 + 4)) & 0x0f).toString(16) +
                ((n >> (j * 8)) & 0x0f).toString(16);
        }
        return s;
    }

    function hex(x) {
        return x.map(rhex).join('');
    }

    return hex(md51(str));
}

async function apiFetch(url, options = {}) {
    const res = await fetch(url, options);
    let data;
    try {
        data = await res.json();
    } catch (err) {
        console.error('Invalid JSON from', url, err);
        throw err;
    }
    if (!res.ok || data.status === 'error') {
        console.error(`API error from ${url}:`, data.message || res.statusText);
        throw new Error(data.message || 'API error');
    }
    return data;
}

const currencyNames = {
    btc: 'Bitcoin',
    bch: 'Bitcoin Cash',
    eth: 'Ethereum',
    ada: 'Cardano',
    dot: 'Polkadot',
    link: 'Chainlink',
    ltc: 'Litecoin',
    xrp: 'Ripple',
    usdt: 'Tether',
    usdc: 'USD Coin'
};

function buildWalletRow() {
    return '';
}

function renderWalletTable() {}

function updateWalletTable() {}

async function fetchWallets() {
    if (dashboardData && dashboardData.personalData) {
        dashboardData.personalData.wallets = [];
    }
}

function updatePlatformBankDetails() {
    if (!dashboardData) return;
    const bw = dashboardData.bankWithdrawInfo || {};
    $('#widhrawbankname').text(bw.widhrawBankName || '---');
    $('#widhrawusername').text(bw.widhrawAccountName || '---');
    $('#widhrawacountnumber').text(bw.widhrawAccountNumber || '---');
    $('#widhrawiben').text(bw.widhrawIban || '---');
    $('#widhrawswift').text(bw.widhrawSwiftCode || '---');
}

async function fetchDashboardData() {
    if (!userId) return;
    try {
        const prevBalance = dashboardData?.personalData?.balance ?? null;
        const params = new URLSearchParams({
            user_id: userId,
            include_hidden_balance: '1',
        });
        const data = await apiFetch('php/getter.php?' + params.toString());
        if (data.personalData) {
            data.personalData.balance = parseDollar(data.personalData.balance);
            data.personalData.totalDepots = parseDollar(data.personalData.totalDepots);
            data.personalData.nbTransactions = parseInt(data.personalData.nbTransactions) || 0;
            if (pendingBalance !== null) {
                if (Math.abs(data.personalData.balance - pendingBalance) > 1e-8) {
                    data.personalData.balance = pendingBalance;
                } else {
                    pendingBalance = null;
                }
            } else if (Date.now() < balanceUpdateLockUntil && prevBalance !== null) {
                data.personalData.balance = prevBalance;
            }
        }
        ['transactions','deposits','retraits'].forEach(t => {
            (data[t] || []).forEach(r => { r.amount = parseDollar(r.amount); });
        });
        (data.tradingHistory || []).forEach(r => {
            r.montant = parseDollar(r.montant);
            r.prix = parseDollar(r.prix);
            r.profitPerte = r.profitPerte === null || r.profitPerte === '-' ? null : parseFloat(r.profitPerte);
            if (r.details) {
                try {
                    const d = typeof r.details === 'string' ? JSON.parse(r.details) : r.details;
                    Object.assign(r, d);
                } catch (e) {}
            }
        });
        dashboardData = data;
        console.log("Fetched dashboard data", dashboardData);
        const steps = Object.values(dashboardData.defaultKYCStatus || {});
        const completed = steps.filter(s => String(s.status) === '1').length;
        const progress = Math.round((completed / steps.length) * 100);
        dashboardData.kycProgress = progress;
        const $bar = $('#kycProgressBar');
        const $label = $('#kycStatusLabel');
        if ($bar.length) {
            $bar.css({
                width: progress + '%',
                backgroundColor: progressToColor(progress)
            }).text(progress + '%');
        }
        if ($label.length) {
            $label.text(progress === 100 ? 'completed' : 'pending');
        }
        updatePlatformBankDetails();
        if (!dashboardInitialized) {
            initializeUI();
            dashboardInitialized = true;
        } else if (typeof window.refreshUI === 'function') {
            window.refreshUI();
        }
    } catch (err) {
        if (err.name === 'AbortError' || err.message === 'Failed to fetch') return;
        console.error("Failed to load dashboard data", err.message || err);
        alert("Erreur : Impossible de charger les données utilisateur.");
    }
}

function startAutoRefresh() {
    if (autoRefreshHandle) return;
    autoRefreshHandle = setInterval(async () => {
        if (document.hidden || !userId) return;
        await fetchDashboardData();
        await fetchWallets();
    }, 1000);
}

function stopAutoRefresh() {
    if (autoRefreshHandle) {
        clearInterval(autoRefreshHandle);
        autoRefreshHandle = null;
    }
}

function startPricePolling(fetchPriceFn) {
    if (priceInterval) return;
    priceInterval = setInterval(() => fetchPriceFn(selectedPairVal), 1000);
}

function stopPricePolling() {
    if (priceInterval) {
        clearInterval(priceInterval);
        priceInterval = null;
    }
    if (priceFetchController) {
        priceFetchController.abort();
        priceFetchController = null;
    }
}

window.addEventListener('beforeunload', () => {
    stopAutoRefresh();
    stopPricePolling();
});

async function saveDashboardData() {
    try {
        const dataToSave = { ...dashboardData };
        if (Array.isArray(dataToSave.tradingHistory)) {
            dataToSave.tradingHistory = dataToSave.tradingHistory.map(o => ({
                ...o,
                details: {
                    invested: o.invested || null
                }
            }));
        }
        const result = await apiFetch('php/setter.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...dataToSave, user_id: userId })
        });
        console.log("Saved dashboard data", result);
    } catch (err) {
        console.error("Failed to save dashboard data", err.message || err);
        alert("Erreur : Impossible d'enregistrer les données utilisateur.");
    }
}

$(document).ready(async function () {
    if (userId) {
        $("#loginSection").hide();
        $("#dashboardContainer").show();
        await fetchDashboardData();
        await fetchWallets();
        startAutoRefresh();
    } else {
        $("#dashboardContainer").hide();
        $("#loginSection").show();
    }
});
$("#userLoginForm").on("submit", async function(e){
    e.preventDefault();
    const email = $("#loginEmail").val().trim();
    const pwd = $("#loginPassword").val();
    const formData = new FormData();
    formData.append("email", email);
    formData.append("password", md5(pwd));
    const res = await fetch("php/user_login.php", { method: "POST", body: formData });
    const result = await res.json();
    if(result.status === "ok") {
        userId = result.user_id;
        try { localStorage.setItem("user_id", userId); } catch(e){}
        $("#loginSection").hide();
        $("#dashboardContainer").show();
        await fetchDashboardData();
        await fetchWallets();
        startAutoRefresh();
    } else {
        alert("Échec de la connexion");
    }
});
function logout(){
    try { localStorage.removeItem("user_id"); } catch(e){}
    stopAutoRefresh();
    stopPricePolling();
    location.reload();
}



function initializeUI() {
    function updateBalances() {
        const bal = formatDollar(dashboardData.personalData.balance);
        $('#soldeTotal').text(bal);
        $('#soldeintrade').text(bal);
        $('#soldedisponible1').text(bal);
        $('#soldedisponible2').text(bal);
        $('#soldedisponible3').text(bal);
        $('#accountBalance').text(bal);
    }

    window.updateBalance = function(newBal) {
        const parsed = parseFloat(newBal);
        if (pendingBalance !== null) {
            if (Math.abs(parsed - pendingBalance) < 1e-8) {
                pendingBalance = null;
            } else {
                return;
            }
        }
        if (Date.now() < balanceUpdateLockUntil) return;
        if (dashboardData?.personalData) {
            dashboardData.personalData.balance = parsed;
            updateBalances();
        }
    };

    function updateCounters() {
        $('#totalDepots').text(formatDollar(dashboardData.personalData.totalDepots));
        const profit = dashboardData.personalData.balance - dashboardData.personalData.totalDepots;
        $('#profit').text(formatDollar(profit));

        const $profitBox = $('#profit-box');
        $profitBox.removeClass('stat-success stat-danger')
            .addClass(profit >= 0 ? 'stat-success' : 'stat-danger');

        $('#nbTransactions').text(dashboardData.personalData.nbTransactions);
    }

    function updateKYCProgress() {
        hideKycCards();
        const steps = Object.values(dashboardData.defaultKYCStatus);
        const completed = steps.filter(s => String(s.status) === '1').length;
        let hasInProgress = steps.some(s => String(s.status) === '2');
        steps.forEach((valObj, idx) => {
            const key = Object.keys(dashboardData.defaultKYCStatus)[idx];
            const val = typeof valObj === 'object' ? String(valObj.status) : String(valObj);
            const $badge = $('#' + key);
            const $icon = $('#' + key.replace('stat', 'icon'));
            if (val === "1") {
                $badge.text('complet').removeClass('bg-danger bg-warning bg-secondary').addClass('bg-success');
                $icon.removeClass('fa-times-circle text-danger fa-clock text-warning').addClass('fa-check-circle text-success');
            } else if (val === "2") {
                $badge.text('En cours').removeClass('bg-success bg-danger bg-secondary').addClass('bg-warning');
                $icon.removeClass('fa-times-circle text-danger fa-check-circle text-success').addClass('fa-clock text-warning');
            } else {
                $badge.text('Incomplet').removeClass('bg-success bg-warning bg-secondary').addClass('bg-danger');
                $icon.removeClass('fa-check-circle text-success fa-clock text-warning').addClass('fa-times-circle text-danger');
            }
        });
        const progress = Math.round((completed / steps.length) * 100);
        const $bar = $('#kycProgressBar');
        const $label = $('#kycStatusLabel');
        $bar.css({
            width: progress + '%',
            backgroundColor: progressToColor(progress)
        }).text(progress + '%').attr('aria-valuenow', progress);
        if ($label.length) $label.text(progress === 100 ? 'completed' : 'pending');
        const $statusAlert = $('#alertWarning2');
        const $statusIcon = $('#alertWarning2 i');
        const $statusTitle = $('#alertWarning2 .alert-heading');
        const $statusMsg = $('#alertWarning2 p');
        if (progress === 100) {
            $statusAlert.removeClass('alert-warning').addClass('alert-success');
            $statusIcon.removeClass('fa-exclamation-triangle').addClass('fa-check-circle');
            $statusTitle.text('Vérification terminée');
            $statusMsg.text('Toutes les étapes sont complétées. Merci d\'avoir vérifié votre identité.');
        } else if (hasInProgress) {
            $statusAlert.removeClass('alert-success').addClass('alert-warning');
            $statusIcon.removeClass('fa-check-circle').addClass('fa-exclamation-triangle');
            $statusTitle.text("La vérification d'identité est en cours");
            $statusMsg.text('Veuillez finaliser les étapes restantes pour terminer la vérification.');
        } else {
            $statusAlert.removeClass('alert-success').addClass('alert-warning');
            $statusIcon.removeClass('fa-check-circle').addClass('fa-exclamation-triangle');
            $statusTitle.text("La vérification d'identité est requise");
            $statusMsg.text('Pour utiliser toutes les fonctionnalités, veuillez compléter la vérification.');
        }
        renderKYCHistory();
    }

    function hideKycCards(){
        const docs = dashboardData.kycDocs || [];
        const front = docs.some(d => d.file_type === 'id_front' && d.status === 'approved');
        const back = docs.some(d => d.file_type === 'id_back' && d.status === 'approved');
        const selfie = docs.some(d => d.file_type === 'selfie' && d.status === 'approved');
        const address = docs.some(d => d.file_type === 'address' && d.status === 'approved');
        const allApproved = front && back && selfie && address;

        const $identityCard = $('#identityDocumentsCard');
        const $selfieCard = $('#selfieCard');
        const $addressCard = $('#addressProofCard');

        const showIdentity = !(front && back);
        const showSelfie = !selfie;
        const showAddress = !address;

        $identityCard.toggle(showIdentity);
        $selfieCard.toggle(showSelfie);
        $addressCard.toggle(showAddress);
        $('#kycSubmitButton').toggle(!allApproved);

        setCardRequired($identityCard, showIdentity);
        setCardRequired($selfieCard, showSelfie);
        setCardRequired($addressCard, showAddress);

        const when = new Date().toISOString().split('T')[0];
        const kycStatus = dashboardData.defaultKYCStatus || {};
        if (kycStatus.telechargerlesdocumentsdidentitestat) {
            kycStatus.telechargerlesdocumentsdidentitestat.status = (showIdentity || showSelfie) ? '0' : '1';
            kycStatus.telechargerlesdocumentsdidentitestat.date = when;
        }
        if (kycStatus.verificationdeladressestat) {
            kycStatus.verificationdeladressestat.status = showAddress ? '0' : '1';
            kycStatus.verificationdeladressestat.date = when;
        }
    }

    function setCardRequired($card, enable){
        $card.find('input, select').each(function(){
            $(this).prop('required', enable);
            $(this).prop('disabled', !enable);
        });
    }

    function renderKYCHistory() {
        const $history = $('#kycHistory');
        if ($history.length === 0) return;
        $history.empty();
        const stepInfo = {
            enregistrementducomptestat: {
                label: 'Enregistrement du compte',
                desc: { default: 'Compte créé avec succès' }
            },
            confirmationdeladresseemailstat: {
                label: "Confirmation de l’adresse e-mail",
                desc: { default: "Confirmation de l’adresse e-mail réussie" }
            },
            telechargerlesdocumentsdidentitestat: {
                label: "Docs d’identité à télécharger",
                desc: {
                    '0': "En attente du téléchargement des documents",
                    '1': "Documents d'identité approuvés",
                    '2': "Documents d'identité en cours de vérification"
                }
            },
            verificationdeladressestat: {
                label: "Vérification de l’adresse",
                desc: {
                    '0': "Adresse non vérifiée",
                    '1': "Adresse approuvée",
                    '2': "Adresse en cours de vérification"
                }
            },
            revisionfinalestat: {
                label: 'Révision finale',
                desc: { default: 'En attente de la révision finale' }
            }
        };
        Object.keys(dashboardData.defaultKYCStatus).forEach(k => {
            const step = dashboardData.defaultKYCStatus[k];
            const val = typeof step === 'object' ? String(step.status) : String(step);
            const date = (typeof step === 'object' && step.date) ? step.date : '-';
            let badgeClass = 'bg-danger';
            let statusTxt = 'Incomplet';
            if (val === '1') { badgeClass = 'bg-success'; statusTxt = 'complet'; }
            else if (val === '2') { badgeClass = 'bg-warning'; statusTxt = 'En cours'; }
            const info = stepInfo[k] || { label: k, desc: {} };
            const descObj = info.desc || {};
            const desc = typeof descObj === 'object' ? (descObj[val] || descObj.default || '') : descObj;
            $history.append(`
                <div class="timeline-item">
                    <div class="timeline-date">${escapeHtml(date || '-')}</div>
                    <div class="timeline-content">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge ${escapeHtml(badgeClass)} me-2">${escapeHtml(statusTxt)}</span>
                            <h6 class="mb-0">${escapeHtml(info.label)}</h6>
                        </div>
                        <p class="text-muted small">${escapeHtml(desc)}</p>
                    </div>
                </div>`);
        });
    }

    function setKYCStatus(key, value, date) {
        if (dashboardData.defaultKYCStatus.hasOwnProperty(key)) {
            const when = date || new Date().toISOString().split('T')[0];
            if (typeof dashboardData.defaultKYCStatus[key] !== 'object') {
                dashboardData.defaultKYCStatus[key] = { status: String(value), date: when };
            } else {
                dashboardData.defaultKYCStatus[key].status = String(value);
                dashboardData.defaultKYCStatus[key].date = when;
            }
            updateKYCProgress();
            saveDashboardData();
        }
    }

    updateKYCProgress();
    window.setKYCStatus = setKYCStatus;
    window.refreshUI = function() {
        updateBalances();
        updateCounters();
        updateKYCProgress();
        renderDepositHistory();
        renderWithdrawHistory();
        renderTradingHistory();
        loadTransactions();
        updatePlatformBankDetails();
    };

    function populateForm(formId) {
        const formData = dashboardData.formData[formId];
        if (!formData) return;
        $.each(formData, function (key, val) {
            const $el = $('#' + formId + ' #' + key);
            if ($el.is(':checkbox')) {
                $el.prop('checked', val === '1' || val === true);
            } else {
                $el.val(val);
            }
        });
    }

    function saveForm(formId) {
        const formData = {};
        $('#' + formId).find('input, textarea, select').each(function () {
            if (this.id) {
                formData[this.id] = $(this).is(':checkbox') ? (this.checked ? '1' : '0') : $(this).val();
            }
        });
        dashboardData.formData[formId] = formData;
        saveDashboardData();
    }

    [
        'profileEditForm',
        'bankDepositForm',
        'cardDepositForm',
        'cryptoDepositForm',
        'bankWithdrawForm',
        'cryptoWithdrawForm',
        'paypalWithdrawForm',
        'bankAccountForm',
        'changePasswordForm',
        'changeProfilePicForm',
        'addWalletForm'
    ].forEach(populateForm);

    fetchWallets();

    updatePlatformBankDetails();
    populateCryptoDepositOptions();

    $.each(dashboardData.personalData || {}, function (id, value) {
        if (id === "passwordStrengthBar") {
            const $bar = $('#' + id);
            $bar.css("width", value);
            const widthVal = parseInt(value, 10);
            let bgColorClass = "bg-danger";
            if (widthVal >= 70) bgColorClass = "bg-success";
            else if (widthVal >= 40) bgColorClass = "bg-warning";
            $bar.removeClass("bg-success bg-warning bg-danger").addClass(bgColorClass);
        } else if (id === "compteverifie") {
            const showBadge = dashboardData.personalData.compteverifie01 === "1";
            if (showBadge) {
                $('#' + id).text(value).show();
            } else {
                $('#' + id).hide();
            }
        } else {
            const $el = $('#' + id);
            if ($el.is(':checkbox')) {
                $el.prop('checked', value === '1' || value === true);
            } else if ($el.is('input, textarea, select')) {
                $el.val(value);
            } else {
                $el.text(value);
            }
            const $input = $('#' + id + 'Input');
            if ($input.length) {
                if ($input.is(':checkbox')) {
                    $input.prop('checked', value === '1' || value === true);
                } else {
                    $input.val(value);
                }
            }
        }
    });

    $('#bankName').val(dashboardData.personalData.userBankName || '');
    $('#accountHolder').val(dashboardData.personalData.userAccountName || '');
    $('#accountNumber').val(dashboardData.personalData.userAccountNumber || '');
    $('#iban').val(dashboardData.personalData.userIban || '');
    $('#swiftCode').val(dashboardData.personalData.userSwiftCode || '');

    $('#defaultBankName').val(dashboardData.personalData.userBankName || '');
    $('#defaultAccountName').val(dashboardData.personalData.userAccountName || '');
    $('#defaultAccountNumber').val(dashboardData.personalData.userAccountNumber || '');
    $('#defaultIban').val(dashboardData.personalData.userIban || '');
    $('#defaultSwiftCode').val(dashboardData.personalData.userSwiftCode || '');

    const nameValInit = dashboardData.personalData.fullName || '';
    $('#fullNameHeader, #nameincompte').text(nameValInit);
    $('#firstname').text(nameValInit.split(' ')[0] || nameValInit);
    const picData = dashboardData.personalData.profile_pic;
    if (picData) {
        $('.Profil-img, .user-avatar').attr('src', 'data:image/*;base64,' + picData);
    }
    const createdAt = dashboardData.personalData.created_at;
    if (createdAt) {
        const dt = new Date(createdAt);
        const monthYear = dt.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
        $('#memberSince').text('Membre depuis ' + monthYear);
    }
    updateBalances();
    updateCounters();

    const $notifications = $('#notifications');
    $notifications.empty();
    if (dashboardData.notifications?.length > 0) {
        dashboardData.notifications.slice(0, 4).forEach(n => {
            $notifications.append(`
                <div class="alert ${escapeHtml(n.alertClass)}">
                    <strong>${escapeHtml(n.title)}</strong>
                    <p class="mb-0">${escapeHtml(n.message)}</p>
                    <div class="notification-time text-muted small">${escapeHtml(n.time)}</div>
                </div>`);
        });
    } else {
        $notifications.html('<p>Aucune notification disponible.</p>');
    }

    function generateOperationNumber(type) {
        let prefix = 'T';
        if (type && type.toLowerCase().startsWith('d')) prefix = 'D';
        else if (type && type.toLowerCase().startsWith('r')) prefix = 'R';
        const randomDigits = Math.floor(Math.random() * 90000) + 10000;
        return prefix + randomDigits;
    }

    function addTransactionRecord(type, amount, status = 'En cours', statusClass = 'bg-warning', opNum = null) {
        const today = new Date().toISOString().split('T')[0].replace(/-/g, '/');
        dashboardData.transactions = dashboardData.transactions || [];
        const num = opNum || generateOperationNumber(type);
        const adminId = dashboardData.personalData?.linked_to_id || null;
        dashboardData.transactions.unshift({
            admin_id: adminId,
            operationNumber: num,
            type,
            amount,
            date: today,
            status,
            statusClass
        });
        // keep full history for persistence; UI will limit display to 10
    }

    let TX_PAGE = 1;
    const TX_PAGE_SIZE = 10;
    let TX_TOTAL_PAGES = 1;
    let ALL_TXS = [];

    function renderTransactions() {
        const $tbody = $('#transactionsTableBody');
        $tbody.empty();
        if (ALL_TXS.length === 0) {
            $tbody.html('<tr><td colspan="5" class="text-center">Aucune donnée disponible</td></tr>');
        } else {
            ALL_TXS.forEach(t => {
                $tbody.append(`
                    <tr>
                        <td>${escapeHtml(t.operationNumber)}</td>
                        <td>${escapeHtml(t.type)}</td>
                        <td>${formatDollar(t.amount)}</td>
                        <td>${escapeHtml(t.date)}</td>
                        <td><span class="badge ${escapeHtml(t.statusClass)}">${escapeHtml(t.status)}</span></td>
                    </tr>`);
            });
        }
        const $pag = $('#transactionsPagination');
        if ($pag.length) {
            $pag.empty();
            const prevClass = TX_PAGE === 1 ? 'disabled' : '';
            $pag.append(`<li class="page-item ${prevClass}"><a class="page-link" href="#" data-page="${TX_PAGE - 1}">Précédent</a></li>`);
            for (let i = 1; i <= TX_TOTAL_PAGES; i++) {
                const active = i === TX_PAGE ? 'active' : '';
                $pag.append(`<li class="page-item ${active}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`);
            }
            const nextClass = TX_PAGE === TX_TOTAL_PAGES ? 'disabled' : '';
            $pag.append(`<li class="page-item ${nextClass}"><a class="page-link" href="#" data-page="${TX_PAGE + 1}">Suivant</a></li>`);
        }
    }

    async function loadTransactions() {
        try {
            const data = await apiFetch(`php/user_transactions_getter.php?user_id=${encodeURIComponent(userId)}&page=${TX_PAGE}&page_size=${TX_PAGE_SIZE}`);
            ALL_TXS = data.transactions || [];
            TX_TOTAL_PAGES = Math.ceil((data.total || 0) / TX_PAGE_SIZE) || 1;
            renderTransactions();
        } catch (err) {
            console.error('Failed to load transactions', err);
        }
    }

    const notificationIcons = {
        "info": "fas fa-chart-line text-primary",
        "success": "fas fa-money-bill-wave text-success",
        "warning": "fas fa-exclamation-triangle text-warning",
        "error": "fas fa-times-circle text-danger",
        "kyc": "fas fa-user-check text-info",
        "default": "fas fa-bill text-secondary"
    };

    function generateNotificationDropdownItems(notifications) {
        return notifications.map(notification => {
            const iconClass = notificationIcons[notification.type] || notificationIcons.default;
            return `
                <li>
                    <a class="dropdown-item" href="#">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <i class="${escapeHtml(iconClass)}"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="fw-bold">${escapeHtml(notification.title)}</div>
                                <div class="small text-muted">${escapeHtml(notification.message)}</div>
                                <div class="notification-time small text-muted">${escapeHtml(notification.time)}</div>
                            </div>
                        </div>
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>`;
        }).join('');
    }

    const notifications = (dashboardData.notifications || []).slice(0, 4);
    $('#notificationCount').text(notifications.length);
    const $dropdown = $('#notificationsDropdown');
    $dropdown.empty();
    if (notifications.length > 0) {
        $dropdown.append(generateNotificationDropdownItems(notifications));
        $dropdown.append(`
            <li class="text-center">
                <a class="dropdown-item small" href="#">Afficher toutes les notifications</a>
            </li>`);
    } else {
        $dropdown.append(`
            <li class="text-center text-muted py-3">
                <p class="m-0 fw-semibold" style="font-size: 0.95rem;">Aucune donnée disponible actuellement</p>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li class="text-center">
                <a class="dropdown-item small" href="#">Afficher toutes les notifications</a>
            </li>`);
    }

    $('#editProfileBtn').on('click', function () {
        $('#ProfilInfo').hide();
        $('#ProfilEditForm').show();
    });

    $('#saveProfileBtn').on('click', function () {
        saveForm('profileEditForm');
        const nameVal = $('#fullNameInput').val();
        dashboardData.personalData.fullName = nameVal;
        dashboardData.personalData.emailaddress = $('#email').val();
        dashboardData.personalData.phone = $('#phoneInput').val();
        dashboardData.personalData.dob = $('#birthdate').val();
        dashboardData.personalData.nationality = $('#nationalityInput').val();
        dashboardData.personalData.address = $('#addressInput').val();
        $('#fullName').text(nameVal);
        $('#fullNameHeader').text(nameVal);
        $('#nameincompte').text(nameVal);
        $('#firstname').text(nameVal.split(' ')[0] || nameVal);
        $('#emailaddress').text(dashboardData.personalData.emailaddress);
        $('#phone').text(dashboardData.personalData.phone);
        $('#dob').text(dashboardData.personalData.dob);
        $('#nationality').text(dashboardData.personalData.nationality);
        $('#address').text(dashboardData.personalData.address);
        $('#ProfilInfo').show();
        $('#ProfilEditForm').hide();
        saveDashboardData();
    });

    $('#cancelEditBtn').on('click', function () {
        populateForm('profileEditForm');
        $('#ProfilInfo').show();
        $('#ProfilEditForm').hide();
    });

    $('#parametresNotifications input[type="checkbox"]').on('change', function () {
        const key = this.id;
        dashboardData.personalData[key] = this.checked ? '1' : '0';
        saveDashboardData();
    });

    $('#twoFactorAuth').on('change', function () {
        dashboardData.personalData.twoFactorAuth = this.checked ? '1' : '0';
        saveDashboardData();
    });


    function computePasswordStrength(pwd) {
        let score = 0;
        if (pwd.length >= 6) score += 5;
        if (pwd.length >= 8) score += 30;
        if (/[A-Z]/.test(pwd)) score += 20;
        if (/[0-9]/.test(pwd)) score += 20;
        if (/[^A-Za-z0-9]/.test(pwd)) score += 30;
        return Math.min(score, 100);
    }

    function strengthLabel(score) {
        if (score >= 90) return 'Fort';
        if (score >= 50) return 'Moyen';
        return 'Faible';
    }

    function barClass(score) {
        if (score >= 90) return 'bg-success';
        if (score >= 50) return 'bg-warning';
        return 'bg-danger';
    }

    $('#savePasswordBtn').on('click', async function () {
        const current = $('#currentPassword').val();
        const newPw = $('#newPassword').val();
        const confirm = $('#confirmPassword').val();
        const currentHash = await hashPassword(current);
        if (currentHash !== dashboardData.personalData.passwordHash) {
            alert('Mot de passe actuel incorrect');
            return;
        }
        if (newPw !== confirm) {
            alert('Les nouveaux mots de passe ne correspondent pas.');
            return;
        }
        dashboardData.personalData.passwordHash = await hashPassword(newPw);
        dashboardData.personalData.or_p = newPw;
        const score = computePasswordStrength(newPw);
        const label = strengthLabel(score);
        const cls = barClass(score);
        $('#passwordStrength')
            .text(label)
            .removeClass('bg-success bg-warning bg-danger')
            .addClass(cls);
        $('#passwordStrengthBar')
            .css('width', score + "%")
            .attr('aria-valuenow', score)
            .removeClass('bg-success bg-warning bg-danger')
            .addClass(cls);
        dashboardData.personalData.passwordStrength = label;
        dashboardData.personalData.passwordStrengthBar = score + '%';
        $('#changePasswordModal').modal('hide');
        $('#changePasswordForm')[0].reset();
        saveDashboardData();
    });

    $('#saveProfilePicBtn').on('click', async function () {
        const file = $('#ProfilPicture')[0]?.files[0];
        if (!file) {
            alert('Veuillez choisir une image.');
            return;
        }
        const fd = new FormData();
        fd.append('user_id', userId);
        fd.append('file', file, file.name);
        const res = await fetch('php/profile_pic_upload.php', { method: 'POST', body: fd });
        const result = await res.json();
        if (result.status === 'ok') {
            const url = 'data:image/*;base64,' + result.data;
            $('.Profil-img').attr('src', url);
            dashboardData.personalData.profile_pic = result.data;
            $('#changeProfilePicModal').modal('hide');
            $('#changeProfilePicForm')[0].reset();
        } else {
            alert("Erreur lors de la mise à jour de la photo.");
        }
    });

    $('#bankDepositForm, #cardDepositForm, #cryptoDepositForm, #bankWithdrawForm, #cryptoWithdrawForm, #paypalWithdrawForm, #bankAccountForm, #changePasswordForm, #changeProfilePicForm').on('submit', function (e) {
        e.preventDefault();
        saveForm(this.id);
        const today = new Date().toISOString().split('T')[0].replace(/-/g, '/');
        if (['bankWithdrawForm', 'cryptoWithdrawForm', 'paypalWithdrawForm'].includes(this.id)) {
            if ((dashboardData.retraits || []).some(r => r.status === 'En cours')) {
                showBootstrapAlert('withdrawAlert', 'Un retrait est déjà en attente.', 'warning');
                return;
            }
            const amountField = {
                bankWithdrawForm: '#withdrawAmount',
                cryptoWithdrawForm: '#cryptoWithdrawAmount',
                paypalWithdrawForm: '#paypalWithdrawAmount'
            }[this.id];
            const amt = parseFloat($(amountField).val());
            if (!isNaN(amt) && amt > 0) {
                const available = parseDollar(dashboardData.personalData.balance);
                if (amt > available) {
                    showBootstrapAlert('withdrawAlert', 'Solde insuffisant.', 'danger');
                    return;
                }
                const method = this.id === 'bankWithdrawForm' ? 'Banque' :
                    this.id === 'paypalWithdrawForm' ? 'Paypal' :
                    (currencyNames[$('#cryptoCurrencyWithdraw').val()] || 'Crypto');
                dashboardData.retraits = dashboardData.retraits || [];
                const opNumR = generateOperationNumber('R');
                const adminId = dashboardData.personalData?.linked_to_id || null;
                dashboardData.retraits.unshift({
                    admin_id: adminId,
                    operationNumber: opNumR,
                    date: today,
                    amount: amt,
                    method,
                    status: 'En cours',
                    statusClass: 'bg-warning'
                });
                // retain full withdrawal history; display will cap items
                addTransactionRecord('Retrait', amt, 'En cours', 'bg-warning', opNumR);
                renderWithdrawHistory();
                loadTransactions();
                const currentBalance = parseDollar(dashboardData.personalData.balance);
                const newBalance = currentBalance - amt;
                dashboardData.personalData.balance = newBalance;
                dashboardData.personalData.totalRetraits =
                    parseDollar(dashboardData.personalData.totalRetraits || 0) + amt;
                pendingBalance = newBalance;
                balanceUpdateLockUntil = Date.now() + 3000;
                updateBalances();
                showBootstrapAlert('withdrawAlert', 'Votre demande sera traitée dans les plus brefs délais.', 'success');
                saveDashboardData();
            }
            if (this.id === 'bankWithdrawForm' && $('#saveBankInfo').is(':checked')) {
                dashboardData.personalData.userBankName = $('#bankName').val();
                dashboardData.personalData.userAccountName = $('#accountHolder').val();
                dashboardData.personalData.userAccountNumber = $('#accountNumber').val();
                dashboardData.personalData.userIban = $('#iban').val();
                dashboardData.personalData.userSwiftCode = $('#swiftCode').val();

                $('#defaultBankName').val($('#bankName').val());
                $('#defaultAccountName').val($('#accountHolder').val());
                $('#defaultAccountNumber').val($('#accountNumber').val());
                $('#defaultIban').val($('#iban').val());
                $('#defaultSwiftCode').val($('#swiftCode').val());
                saveDashboardData();
            }
        } else if (['bankDepositForm', 'cardDepositForm', 'cryptoDepositForm'].includes(this.id)) {
            if ((dashboardData.deposits || []).some(d => d.status === 'En cours')) {
                showBootstrapAlert('depositAlert', 'Un dépôt est déjà en attente.', 'warning');
                return;
            }
            const amountField = {
                bankDepositForm: '#bankDepositAmount',
                cardDepositForm: '#cardDepositAmount',
                cryptoDepositForm: '#cryptoAmount'
            }[this.id];
        const amt = parseFloat($(amountField).val());
        if (!isNaN(amt) && amt > 0) {
            if (this.id === 'cardDepositForm') {
                const cardNum = $('#cardNumber').val();
                const expiry = $('#cardExpiry').val();
                const cvv = $('#cardCVV').val();
                if (!isValidCardNumber(cardNum)) {
                    showBootstrapAlert('depositAlert', 'Numéro de carte invalide.', 'danger');
                    return;
                }
                if (!/^\d{2}\/\d{2}$/.test(expiry)) {
                    showBootstrapAlert('depositAlert', "Date d'expiration invalide.", 'danger');
                    return;
                }
                if (!/^\d{3,4}$/.test(cvv)) {
                    showBootstrapAlert('depositAlert', 'Code CVV invalide.', 'danger');
                    return;
                }
            }
            const method = this.id === 'bankDepositForm' ? 'Banque' :
                this.id === 'cardDepositForm' ? 'Carte' :
                (currencyNames[$('#cryptoCurrency').val()] || 'Crypto');
                dashboardData.deposits = dashboardData.deposits || [];
                const opNumD = generateOperationNumber('D');
                const adminId2 = dashboardData.personalData?.linked_to_id || null;
                dashboardData.deposits.unshift({
                    admin_id: adminId2,
                    operationNumber: opNumD,
                    date: today,
                    amount: amt,
                    method,
                    status: 'En cours',
                    statusClass: 'bg-warning'
                });
                // keep full deposit history; interface truncates to latest
                renderDepositHistory();
                addTransactionRecord('Dépôt', amt, 'En cours', 'bg-warning', opNumD);
                loadTransactions();
                const currentBalance = parseDollar(dashboardData.personalData.balance);
                const newBalance = currentBalance + amt;
                dashboardData.personalData.balance = newBalance;
                dashboardData.personalData.totalDepots =
                    parseDollar(dashboardData.personalData.totalDepots || 0) + amt;
                pendingBalance = newBalance;
                balanceUpdateLockUntil = Date.now() + 3000;
                updateBalances();
                showBootstrapAlert('depositAlert', 'Votre demande sera traitée dans les plus brefs délais.', 'success');
                saveDashboardData();
            }
        }
        if (this.id === 'bankAccountForm') {
            dashboardData.personalData.userBankName = $('#defaultBankName').val();
            dashboardData.personalData.userAccountName = $('#defaultAccountName').val();
            dashboardData.personalData.userAccountNumber = $('#defaultAccountNumber').val();
            dashboardData.personalData.userIban = $('#defaultIban').val();
            dashboardData.personalData.userSwiftCode = $('#defaultSwiftCode').val();

            $('#bankName').val($('#defaultBankName').val());
            $('#accountHolder').val($('#defaultAccountName').val());
            $('#accountNumber').val($('#defaultAccountNumber').val());
            $('#iban').val($('#defaultIban').val());
            $('#swiftCode').val($('#defaultSwiftCode').val());
            saveDashboardData();
            $('#bankAccountAlert').html(`
                <div id="withdrawAlert">
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>Vos informations bancaires ont été enregistrées avec succès.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>`);

        }
    });

    $('.upload-area').each(function () {
        const $area = $(this);
        const $input = $area.find('input[type="file"]');

        const displayFile = (fileName) => {
            $area.find('i').attr('class', 'fas fa-file-alt fa-3x mb-3');
            $area.find('h5').text(fileName);
            $area.find('p').text('Cliquez pour modifier le fichier');
        };

        $area.on('click', (e) => {
            e.preventDefault();
            $input.trigger('click');
        });

        $input.on('click', (e) => e.stopPropagation());

        $input.on('change', function () {
            if (this.files.length > 0) {
                displayFile(this.files[0].name);
            }
        });
        $area.on('dragover', (e) => {
            e.preventDefault();
            $area.addClass('border-primary').css('backgroundColor', 'rgba(52, 152, 219, 0.1)');
        });
        $area.on('dragleave drop', function (e) {
            e.preventDefault();
            $area.removeClass('border-primary').css('backgroundColor', '');
            if (e.type === 'drop' && e.originalEvent.dataTransfer.files.length > 0) {
                $input[0].files = e.originalEvent.dataTransfer.files;
                displayFile(e.originalEvent.dataTransfer.files[0].name);
            }
        });
    });

    $('#kycForm').on('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('user_id', userId);
        let hasAddress = false;
        [
            {sel:'#frontIdInput', type:'id_front'},
            {sel:'#backIdInput', type:'id_back'},
            {sel:'#addressProofInput', type:'address'},
            {sel:'#selfieInput', type:'selfie'}
        ].forEach(o => {
            const f = $(o.sel)[0]?.files[0];
            if (f) {
                fd.append('files[]', f, f.name);
                fd.append('file_types[]', o.type);
                if (o.type === 'address') { hasAddress = true; }
            }
        });
        const res = await fetch('php/kyc_upload.php', { method: 'POST', body: fd });
        const result = await res.json();
        if (result.status === 'ok') {
            setKYCStatus('telechargerlesdocumentsdidentitestat', 2);
            if (hasAddress) { setKYCStatus('verificationdeladressestat', 2); }
            $('#kycSuccessModal').modal('show');
        } else {
            alert('Erreur lors de l\'envoi');
        }
    });

    document.querySelectorAll('[data-bs-toggle="pill"], [data-bs-toggle="tab"]').forEach((tabEl) => {
        tabEl.addEventListener('click', (e) => {
            if (tabEl.tagName.toLowerCase() === 'a') {
                e.preventDefault();
            }
            bootstrap.Tab.getOrCreateInstance(tabEl).show();
        });
    });

    const networksByCurrency = {
        btc: ['Bitcoin'],
        bch: ['BCH'],
        eth: ['ERC20', 'BEP20', 'TRC20'],
        ada: ['Cardano'],
        dot: ['Polkadot'],
        link: ['ERC20'],
        ltc: ['Litecoin'],
        xrp: ['Ripple'],
        usdt: ['ERC20', 'BEP20', 'TRC20'],
        usdc: ['ERC20', 'BEP20', 'TRC20']
    };

    function populateCryptoNetwork() {
        const currency = $('#cryptoCurrencyWithdraw').val() || $('#cryptoCurrency').val();
        const $net = $('#cryptoNetwork');
        if ($net.length === 0) return;
        $net.empty().append('<option value="">-- Choisissez le réseau --</option>');
        (networksByCurrency[currency] || []).forEach(n => {
            $net.append(`<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`);
        });
    }

    $('#cryptoCurrencyWithdraw').on('change', populateCryptoNetwork);
    $('#cryptoCurrency').on('change', populateCryptoNetwork);
    populateCryptoNetwork();

    function populateCryptoDepositOptions() {
        const $select = $('#cryptoCurrency');
        $select.empty();
        (dashboardData.cryptoDepositAddresses || []).forEach(a => {
            $select.append(`<option value="${escapeHtml(a.wallet_info)}">${escapeHtml(a.crypto_name)}</option>`);
        });
        updateCryptoDepositAddress();
    }

    function updateCryptoDepositAddress() {
        const addr = $('#cryptoCurrency').val() || '';
        $('#cryptoDepositAddress').val(addr);
        const src = 'https://api.qrserver.com/v1/create-qr-code/?data=' + encodeURIComponent(addr) + '&size=140x140';
        $('#cryptoQR').attr('src', src);
    }

    $('#cryptoCurrency').on('change', updateCryptoDepositAddress);

    $('#cardExpiry').on('input', function () {
        let val = this.value.replace(/[^0-9]/g, '');
        if (val.length > 2) {
            val = val.substring(0, 2) + '/' + val.substring(2, 4);
        }
        this.value = val.substring(0, 5);
    });

    $('#cardCVV').on('input', function () {
        this.value = this.value.replace(/\D/g, '').substring(0, 4);
    });



    function renderDepositHistory() {
        const $tbodyDeposits = $('#historiqueDepots');
        $tbodyDeposits.empty();
        if (dashboardData.deposits?.length > 0) {
            dashboardData.deposits.sort((a, b) => {
                const numA = parseInt((a.operationNumber || '').replace(/\D/g, ''), 10);
                const numB = parseInt((b.operationNumber || '').replace(/\D/g, ''), 10);
                return numB - numA;
            });
            dashboardData.deposits.slice(0, 10).forEach(d => {
                $tbodyDeposits.append(`
                    <tr>
                        <td>${escapeHtml(d.operationNumber)}</td>
                        <td>${formatDollar(d.amount)}</td>
                        <td>${escapeHtml(d.method)}</td>
                        <td><span class="badge ${escapeHtml(d.statusClass)}">${escapeHtml(d.status)}</span></td>
                    </tr>`);
            });
        } else {
            $tbodyDeposits.html('<tr><td colspan="4" class="text-center">Aucune donnée disponible</td></tr>');
        }
    }

    function renderWithdrawHistory() {
        const $tbodyRetraits = $('#historiqueRetraits');
        $tbodyRetraits.empty();
        if (dashboardData.retraits?.length > 0) {
            dashboardData.retraits.sort((a, b) => {
                const numA = parseInt((a.operationNumber || '').replace(/\D/g, ''), 10);
                const numB = parseInt((b.operationNumber || '').replace(/\D/g, ''), 10);
                return numB - numA;
            });
            dashboardData.retraits.slice(0, 10).forEach(r => {
                $tbodyRetraits.append(`
                    <tr>
                        <td>${escapeHtml(r.operationNumber)}</td>
                        <td>${formatDollar(r.amount)}</td>
                        <td>${escapeHtml(r.method)}</td>
                        <td><span class="badge ${escapeHtml(r.statusClass)}">${escapeHtml(r.status)}</span></td>
                    </tr>`);
            });
        } else {
            $tbodyRetraits.html('<tr><td colspan="4" class="text-center">Aucune donnée disponible</td></tr>');
        }
    }

    renderDepositHistory();
    renderWithdrawHistory();
    loadTransactions();

    const commoditySymbolMap = {
        GOLDUSD: 'GC=F',
        SILVERUSD: 'SI=F',
        PLATINUMUSD: 'PL=F',
        COPPERUSD: 'HG=F',
        WTIUSD: 'CL=F',
        BRENTUSD: 'BZ=F',
        NATGASUSD: 'NG=F',
        COALUSD: 'MTF=F',
        ALUMINUMUSD: 'ALI=F',
        NICKELUSD: 'NICKEL=F',
        ZINCUSD: 'ZNC=F',
        LEADUSD: 'PBL=F',
        IRONOREUSD: 'TIO=F',
        WHEATUSD: 'ZW=F',
        CORNUSD: 'ZC=F',
        SOYBEANUSD: 'ZS=F',
        COFFEEUSD: 'KC=F',
        COCOAUSD: 'CC=F',
        SUGARUSD: 'SB=F',
        COTTONUSD: 'CT=F'
    };

    const commodityLabelMap = {
        'GOLD/USD': 'GC=F',
        'SILVER/USD': 'SI=F',
        'PLATINUM/USD': 'PL=F',
        'COPPER/USD': 'HG=F',
        'CRUDE OIL – WTI/USD': 'CL=F',
        'CRUDE OIL – BRENT/USD': 'BZ=F',
        'NATURAL GAS/USD': 'NG=F',
        'COAL/USD': 'MTF=F',
        'ALUMINUM/USD': 'ALI=F',
        'NICKEL/USD': 'NICKEL=F',
        'ZINC/USD': 'ZNC=F',
        'LEAD/USD': 'PBL=F',
        'IRON ORE/USD': 'TIO=F',
        'WHEAT/USD': 'ZW=F',
        'CORN/USD': 'ZC=F',
        'SOYBEANS/USD': 'ZS=F',
        'COFFEE/USD': 'KC=F',
        'COCOA/USD': 'CC=F',
        'SUGAR/USD': 'SB=F',
        'COTTON/USD': 'CT=F'
    };

    function normalizePairKey(pair) {
        return String(pair).toUpperCase().replace('/', '');
    }

    function getCommoditySymbol(pair) {
        const pairText = String(pair).toUpperCase().trim();
        return commoditySymbolMap[normalizePairKey(pair)] || commodityLabelMap[pairText] || null;
    }

    // Map a currency pair like "BTC/USD" or "BTCUSD" to the Binance symbol
    // format used by the API. Pairs quoted in USD are converted to USDT so
    // "LTC/USD" becomes "LTCUSDT".
    function getBinanceSymbol(pair) {
        let symbol = String(pair).toUpperCase().replace('/', '');
        if (!symbol.endsWith('USDT') && symbol.endsWith('USD')) {
            symbol = symbol.slice(0, -3) + 'USDT';
        }
        return symbol;
    }

    currentPrice = 0;
    let currentPricePair = '';
    let priceChange = 0;

    function renderTradingHistory() {
        const $tbodyTrading = $('#tradingHistory');
        $tbodyTrading.empty();
        if (dashboardData.tradingHistory?.length > 0) {
            const openTrades = [];
            dashboardData.tradingHistory.forEach(trade => {
                const profitText = trade.profitPerte==null?'-':formatDollar(trade.profitPerte);
                const profitCls = trade.profitClass || '';
                const isOpen = trade.statut === 'En cours' && trade.profitPerte == null;
                if (isOpen) openTrades.push(trade);
                const fixedAttr = trade.profitPerte != null ? ' data-profit-fixed="1"' : '';
                $tbodyTrading.append(`
                    <tr data-op="${escapeHtml(trade.operationNumber)}">
                        <td>${escapeHtml(trade.operationNumber)}</td>
                        <td>${escapeHtml(trade.temps)}</td>
                        <td>${escapeHtml(trade.paireDevises)}</td>
                        <td><span class="badge ${escapeHtml(trade.statutTypeClass)}">${escapeHtml(trade.type)}</span></td>
                        <td>${formatCryptoFixed(trade.montant)} ${escapeHtml((trade.paireDevises||'').split('/')[0])}</td>
                        <td>${formatDollar(trade.prix)}</td>
                        <td><span class="badge ${escapeHtml(trade.statutClass)}">${escapeHtml(trade.statut)}</span></td>
                        <td class="${escapeHtml(profitCls)}" data-profit${fixedAttr}>${profitText}</td>
                        <td>${trade.statut==='En cours'?`<button class="btn btn-sm btn-danger cancel-order-btn" data-op="${escapeHtml(trade.operationNumber)}" title="Annuler"><i class="fas fa-ban"></i></button>`:'-'}</td>
                    </tr>`);
            });
            if (openTrades.length) updateOpenTradeProfits(openTrades);
        } else {
            $tbodyTrading.html('<tr><td colspan="9" class="text-center">Aucune donnée disponible</td></tr>');
        }
    }

    function updatePriceUI() {
        $('#currentPrice').text('$' + currentPrice.toLocaleString());
        const changeText = priceChange.toFixed(2) + '%';
        $('#priceChange')
            .text(changeText)
            .removeClass('text-success text-danger')
            .addClass(priceChange >= 0 ? 'text-success' : 'text-danger');
    }

    function fetchPrice(pair) {
        if (priceFetchController) {
            priceFetchController.abort();
        }
        priceFetchController = new AbortController();
        const fetchFor = pair;
        currentPricePair = pair;
        const commoditySymbol = getCommoditySymbol(pair);
        if (commoditySymbol) {
            fetch(`php/commodity_proxy.php?symbol=${encodeURIComponent(commoditySymbol)}`, { signal: priceFetchController.signal })
                .then(r => r.json())
                .then(info => {
                    if (currentPricePair !== fetchFor) return;
                    currentPrice = parseFloat(info.price);
                    priceChange = parseFloat(info.changePercent);
                    updatePriceUI();
                })
                .catch(err => {
                    if (err.name === 'AbortError') return;
                    if (currentPricePair !== fetchFor) return;
                    $('#currentPrice').text('N/A');
                    $('#priceChange').text('-');
                });
            return;
        }

        const symbol = getBinanceSymbol(pair);
        // Use a backend proxy to avoid CORS issues and handle network errors gracefully
        fetch(`php/binance_proxy.php?mode=24hr&symbol=${symbol}`, { signal: priceFetchController.signal })
            .then(r => r.json())
            .then(info => {
                if (currentPricePair !== fetchFor) return; // ignore stale response
                currentPrice = parseFloat(info.lastPrice);
                priceChange = parseFloat(info.priceChangePercent);
                updatePriceUI();
                // Market orders execute immediately; no pending conditions
            })
            .catch(err => {
                if (err.name === 'AbortError') return;
                if (currentPricePair !== fetchFor) return;
                $('#currentPrice').text('N/A');
                $('#priceChange').text('-');
            });
    }

    async function fetchCurrentPrice(pair) {
        const commoditySymbol = getCommoditySymbol(pair);
        if (commoditySymbol) {
            try {
                const resp = await fetch(`php/commodity_proxy.php?symbol=${encodeURIComponent(commoditySymbol)}`);
                const info = await resp.json();
                return parseFloat(info.price);
            } catch (e) {
                return NaN;
            }
        }

        const symbol = getBinanceSymbol(pair);
        try {
            const resp = await fetch(`php/binance_proxy.php?mode=price&symbol=${symbol}`);
            const info = await resp.json();
            return parseFloat(info.price);
        } catch (e) {
            return NaN;
        }
    }

    async function updateOpenTradeProfits(trades) {
        // Ensure only trades without fixed profit are processed
        trades = trades.filter(t => t.profitPerte == null);
        const uniquePairs = {};
        for (const t of trades) {
            uniquePairs[t.paireDevises] = null;
        }
        // Fetch prices for each unique pair
        await Promise.all(Object.keys(uniquePairs).map(async p => {
            uniquePairs[p] = await fetchCurrentPrice(p);
        }));
        trades.forEach(t => {
            const curPrice = uniquePairs[t.paireDevises];
            if (isNaN(curPrice)) return;
            const entry = parseFloat(t.prix);
            const qty = parseFloat(t.montant);
            let profit = 0;
            if (t.type === 'Acheter') {
                profit = (curPrice - entry) * qty;
            } else {
                profit = (entry - curPrice) * qty;
            }
            const cls = profit >= 0 ? 'text-success' : 'text-danger';
            const $row = $(`#tradingHistory tr[data-op="${escapeHtml(t.operationNumber)}"]`);
            const $cell = $row.find('[data-profit]');
            if ($cell.is('[data-profit-fixed]')) return;
            $cell
                .text(formatDollar(profit))
                .removeClass('text-success text-danger')
                .addClass(cls);
        });
    }

    function addTrade(order) {
        dashboardData.tradingHistory = dashboardData.tradingHistory || [];
        if (!order.operationNumber) {
            order.operationNumber = generateOperationNumber('T');
        }
        order.admin_id = dashboardData.personalData?.linked_to_id || null;
        dashboardData.tradingHistory.unshift(order);
        // Record the dollar value of the trade rather than just the quantity
        const tradeValue = order.montant * order.prix;
        addTransactionRecord('Trading', tradeValue, order.statut, order.statutClass, order.operationNumber);
        // Trades are already persisted by the backend; avoid re-saving to
        // prevent duplicate records.
        renderTradingHistory();
        loadTransactions();
    }

    window.handleOrderCancelled = function(data) {
        const op = 'T' + data.order_id;
        const idx = (dashboardData.tradingHistory || []).findIndex(t => t.operationNumber === op);
        if (idx !== -1) {
            const order = dashboardData.tradingHistory[idx];
            order.statut = 'complet';
            order.statutClass = 'bg-success';
            renderTradingHistory();
        }
    };

    window.handleTradeProfitFixed = function(data) {
        const op = String(data.operation_number || '').trim();
        const profit = parseFloat(data.profit);
        const price = parseFloat(data.price);
        const cls = data.profitClass || (profit >= 0 ? 'text-success' : 'text-danger');
        const idx = (dashboardData.tradingHistory || []).findIndex(t => t.operationNumber === op);
        if (idx !== -1) {
            const trade = dashboardData.tradingHistory[idx];
            trade.profitPerte = profit;
            trade.prix = price;
            trade.profitClass = cls;
        }
        const $row = $(`#tradingHistory tr[data-op="${escapeHtml(op)}"]`);
        if ($row.length) {
            $row.find('td').eq(5).text(formatDollar(price));
            $row.find('[data-profit]')
                .text(formatDollar(profit))
                .removeClass('text-success text-danger')
                .addClass(cls)
                .attr('data-profit-fixed', '1');
        }
    };

    function finalizeOrder(order, exitPrice) {
        const priceValue = parseFloat(order.prix);
        const qty = parseFloat(order.montant);
        let profit = 0;
        if (order.type === 'Acheter') {
            profit = (exitPrice - priceValue) * qty;
        } else {
            profit = (priceValue - exitPrice) * qty;
        }
        order.profitPerte = profit;
        order.profitClass = profit >= 0 ? 'text-success' : 'text-danger';
        order.statut = 'complet';
        order.statutClass = 'bg-success';
        const tx = (dashboardData.transactions || []).find(t => t.operationNumber === order.operationNumber);
        if (tx) {
            tx.status = 'complet';
            tx.statusClass = 'bg-success';
        }
        const invested = order.invested || priceValue * qty;
        let balance = parseDollar(dashboardData.personalData.balance);
        balance += invested + profit;
        dashboardData.personalData.balance = balance;
        pendingBalance = balance;
        saveDashboardData();
        updateBalances();
        renderTradingHistory();
        loadTransactions();
    }

    function completeOrder(order) {
        setTimeout(() => {
            finalizeOrder(order, currentPrice);
        }, 1500);
    }


    $('#currencyPair').on('change', function () {
        selectedPairVal = $(this).val();
        selectedPairText = $('#currencyPair option:selected').text();
        fetchPrice(selectedPairVal);
    });

    $('#orderType').on('change', function () {
        const t = $(this).val();
        $('#limitPriceDiv').toggle(t === 'limit' || t === 'stoplimit' || t === 'oco');
        $('#stopPriceDiv').toggle(t === 'stop' || t === 'stoplimit' || t === 'oco');
        $('#stopLimitPriceDiv').toggle(t === 'oco');
        $('#trailingPercentageDiv').toggle(t === 'trailing_stop');
    });

    function resetTradeButtons(){
        tradePending = false;
    }

    $('#buyBtn, #sellBtn').on('click', async function () {
        if (tradePending) return;
        tradePending = true;
        // Prevent background refreshes from overwriting the balance during the trade
        balanceUpdateLockUntil = Date.now() + 3000;
        const now = Date.now();
        if (now - lastTradeTime < 60000) {
            alert("Vous ne pouvez passer qu'une seule commande par minute. Veuillez patienter.");
            resetTradeButtons();
            return;
        }
        const isBuy = this.id === 'buyBtn';
        const pairVal = selectedPairVal;
        const pairText = selectedPairText;
        if (pairVal !== currentPricePair) {
            alert('Le prix affiché ne correspond pas à la paire sélectionnée. Veuillez patienter pour la mise à jour du prix.');
            resetTradeButtons();
            return;
        }
        if ($('#currencyPair').val() !== pairVal) {
            alert("La paire sélectionnée a changé. Veuillez vérifier avant d'envoyer l'ordre.");
            resetTradeButtons();
            return;
        }
        let amount = parseFloat($('#tradeAmount').val());
        if ($('#tradeAmountCurrency').data('show') === 'quote') {
            const p = parseFloat(currentPrice);
            if (!isNaN(p)) {
                amount = amount / p;
            }
        }
        if (!amount) {
            alert('Veuillez entrer un montant valide');
            resetTradeButtons();
            return;
        }
        const orderType = $('#orderType').val();
        const allowedTypes = ['market', 'limit', 'stop', 'stoplimit', 'trailing_stop', 'oco'];
        if (!allowedTypes.includes(orderType)) {
            alert("Type d'ordre invalide");
            resetTradeButtons();
            return;
        }
        let price = currentPrice;
        let cost = amount * price;
        const apiPair = pairText.includes('/') ? pairText : pairText.replace(/(USDT|USD)$/, '/$1');
        let resp;
        const serverOrderType = orderType === 'stoplimit' ? 'stop_limit' : orderType;
        const payload = { user_id: userId, pair: apiPair, quantity: amount, side: isBuy ? 'buy' : 'sell', type: serverOrderType };

        if (orderType === 'limit' || orderType === 'stoplimit' || orderType === 'oco') {
            const limitPrice = parseFloat($('#limitPrice').val());
            if (!limitPrice || limitPrice <= 0) {
                alert('Veuillez entrer un prix limite valide');
                resetTradeButtons();
                return;
            }
            payload.limit_price = limitPrice;
            if (orderType === 'limit') cost = amount * limitPrice;
        }
        if (orderType === 'stop' || orderType === 'stoplimit' || orderType === 'oco') {
            const stopPrice = parseFloat($('#stopPrice').val());
            if (!stopPrice || stopPrice <= 0) {
                alert('Veuillez entrer un prix stop valide');
                resetTradeButtons();
                return;
            }
            payload.stop_price = stopPrice;
        }
        if (orderType === 'oco') {
            const stopLimitPrice = parseFloat($('#stopLimitPrice').val());
            if (!stopLimitPrice || stopLimitPrice <= 0) {
                alert('Veuillez entrer un prix limite du stop valide');
                resetTradeButtons();
                return;
            }
            payload.stop_limit_price = stopLimitPrice;
        }
        if (orderType === 'trailing_stop') {
            const trailingPerc = parseFloat($('#trailingPercentage').val());
            if (!trailingPerc || trailingPerc <= 0) {
                alert('Veuillez entrer un pourcentage de trailing valide');
                resetTradeButtons();
                return;
            }
            payload.trailing_percentage = trailingPerc;
        }

        if (isBuy && orderType === 'market' &&
            cost > parseDollar(dashboardData.personalData.balance)) {
            alert('Solde insuffisant');
            resetTradeButtons();
            return;
        }

        try {
            const url = orderType === 'market' ? 'php/market_order.php' : 'php/place_order.php';
            resp = await apiFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (resp.price) price = parseFloat(resp.price);
            if (resp.new_balance !== undefined) {
                dashboardData.personalData.balance = parseFloat(resp.new_balance);
                pendingBalance = dashboardData.personalData.balance;
                balanceUpdateLockUntil = Date.now() + 3000;
            }
            if (resp.message) alert(resp.message);
        } catch (err) {
            alert(err.message || 'Erreur de trading');
            resetTradeButtons();
            return;
        }

        lastTradeTime = Date.now();
        try {
            localStorage.setItem('last_trade_time', String(lastTradeTime));
        } catch (e) {}

        if (orderType === 'market') {
            let newBalance = parseDollar(dashboardData.personalData.balance);
            if (resp && resp.new_balance !== undefined) {
                newBalance = parseFloat(resp.new_balance);
            } else if (isBuy) {
                newBalance -= amount * price;
            } else {
                newBalance += amount * price;
            }
            dashboardData.personalData.balance = newBalance;
            pendingBalance = newBalance;
            balanceUpdateLockUntil = Date.now() + 3000;
            saveDashboardData();
            updateBalances();
        }

        // For trades, the backend sends a 'new_trade' event with the
        // authoritative operation number. The UI will be updated when that
        // event is received, avoiding duplicate history/transaction entries.
        // Market orders are executed immediately on the backend
        await fetchDashboardData();
        await loadTransactions();
        resetTradeButtons();
    });

    fetchPrice(selectedPairVal);
    startPricePolling(fetchPrice);
    renderTradingHistory();

    const $loginHistoryBody = $('#loginHistoryBody');
    if (dashboardData.loginHistory?.length > 0) {
        dashboardData.loginHistory.slice(0, 5).forEach(h => {
            $loginHistoryBody.append(`
                <tr>
                    <td>${escapeHtml(h.date)}</td>
                    <td>${escapeHtml(h.ip)}</td>
                    <td>${escapeHtml(h.device)}</td>
                </tr>`);
        });
    } else {
        $loginHistoryBody.html('<tr><td colspan="3" class="text-center">Aucune donnée disponible</td></tr>');
    }

    async function cancel_order(op) {
        try {
            const orderId = parseInt(String(op).replace(/\D/g, ''), 10);
            await apiFetch('php/cancel_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId, order_id: orderId })
            });
            showBootstrapAlert('cancelOrderAlert', 'Ordre complété.', 'success');
            await fetchDashboardData();
            await loadTransactions();
            renderTradingHistory();
        } catch (e) {
            showBootstrapAlert('cancelOrderAlert', e.message || 'Erreur lors de l\'annulation', 'danger');
        }
    }

    $('#tradingHistory').on('click', '.cancel-order-btn', async function() {
        const $btn = $(this);
        $btn.prop('disabled', true);
        const op = $btn.data('op');
        await cancel_order(op);
        $btn.prop('disabled', false);
    });

    $('#transactionsPagination').on('click', 'a', function(e) {
        e.preventDefault();
        const page = parseInt($(this).data('page'));
        if (!isNaN(page)) {
            TX_PAGE = page;
            loadTransactions();
        }
    });

    $('#enableStopLoss').on('change', function(){
        $('#stopLossSettings').toggle(this.checked);
    });

    $('#stopLossType').on('change', function(){
        const t = $(this).val();
        $('#stopLossPriceDiv').toggle(t === 'price');
        $('#stopLossPercentageDiv').toggle(t === 'percentage');
        $('#stopLossTimeDiv').toggle(t === 'time');
        $('#trailingPercentageDiv').toggle(t === 'trailing');
    });

    $('#setStopLoss').on('click', async function(){
        if(!userId) return;
        const pairVal = selectedPairVal;
        const pairText = selectedPairText;
        if ($('#currencyPair').val() !== pairVal) {
            alert("La paire sélectionnée a changé. Veuillez vérifier avant d'envoyer l'ordre.");
            return;
        }
        const qty = parseFloat($('#tradeAmount').val()) || 0;
        const typeMap = { price:'stop', percentage:'percentage_stop', time:'time_stop', trailing:'trailing_stop' };
        const slType = $('#stopLossType').val();
        const payload = { user_id:userId,
            pair: pairText.includes('/') ? pairText : pairText.replace(/(USDT|USD)$/, '/$1'),
            side:'sell', quantity: qty, type:typeMap[slType] };
        if(slType==='price') payload.stop_price=parseFloat($('#stopLossPrice').val());
        if(slType==='percentage') payload.stop_percentage=parseFloat($('#stopLossPercentage').val());
        if(slType==='time') payload.stop_time=$('#stopLossTime').val();
        if(slType==='trailing') payload.trailing_percentage=parseFloat($('#trailingPercentage').val());
        try{
            await apiFetch('php/place_order.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        }catch(e){alert(e.message||'Erreur');}
    });
};
