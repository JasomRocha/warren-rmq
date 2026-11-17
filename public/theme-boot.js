// Applies the saved theme before the first paint, so a dark-mode user never sees a white flash.
try {
    var t = localStorage.getItem('warren.theme');
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
} catch (e) {}
