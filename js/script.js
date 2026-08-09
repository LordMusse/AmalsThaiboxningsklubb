// Mobilmeny
const hamburger = document.querySelector('.hamburger');
const navLinks = document.querySelector('.nav-links');

hamburger.addEventListener('click', () => {
    navLinks.classList.toggle('active');
});

// Smooth scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelector(this.getAttribute('href')).scrollIntoView({
            behavior: 'smooth'
        });
        navLinks.classList.remove('active'); // Stäng meny på mobil
    });
});

// Kommande pass-info (inställt/upplägg/tid satt via /redigera)
(async function ladda_kommande_passinfo() {
    try {
        const response = await fetch('data/schedule-overrides.json', { cache: 'no-store' });
        if (!response.ok) return;
        const overrides = await response.json();

        const now = new Date();
        const idag = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

        const standardtider = { 2: '18:00–20:00', 4: '18:00–20:00', 7: '14:00–16:00' };
        const svenska_manader = ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];

        function veckodag_for_datum(datum_iso) {
            const [year, month, day] = datum_iso.split('-').map(Number);
            const veckodag = new Date(year, month - 1, day).getDay();
            return veckodag === 0 ? 7 : veckodag;
        }

        function formatera_datum(datum_iso) {
            const [, month, day] = datum_iso.split('-').map(Number);
            return `${day} ${svenska_manader[month - 1]}`;
        }

        function ar_notervard(pass, veckodag) {
            if (pass.installt) return true;
            if (pass.beskrivning && pass.beskrivning.trim()) return true;
            if (pass.tid && pass.tid !== standardtider[veckodag]) return true;
            return false;
        }

        for (const veckodag of [2, 4, 7]) {
            const kommande_datum = Object.keys(overrides)
                .filter(datum => datum >= idag && veckodag_for_datum(datum) === veckodag && ar_notervard(overrides[datum], veckodag))
                .sort()[0];
            if (!kommande_datum) continue;

            const rad = document.querySelector(`#traning li[data-weekday="${veckodag}"]`);
            if (!rad) continue;

            const pass = overrides[kommande_datum];
            const datum_text = formatera_datum(kommande_datum);

            if (pass.installt) {
                const badge = document.createElement('span');
                badge.className = 'pass-installt';
                badge.textContent = pass.beskrivning
                    ? `INSTÄLLT ${datum_text}: ${pass.beskrivning}`
                    : `INSTÄLLT ${datum_text}`;
                rad.appendChild(badge);
            } else {
                const details = document.createElement('details');
                details.className = 'pass-upplagg';
                const summary = document.createElement('summary');
                summary.textContent = `Upplägg ${datum_text}`;
                details.appendChild(summary);

                if (pass.tid && pass.tid !== standardtider[veckodag]) {
                    const tid_rad = document.createElement('p');
                    tid_rad.textContent = `Tid: ${pass.tid}`;
                    details.appendChild(tid_rad);
                }
                if (pass.beskrivning && pass.beskrivning.trim()) {
                    const text = document.createElement('p');
                    text.textContent = pass.beskrivning;
                    details.appendChild(text);
                }
                rad.appendChild(details);
            }
        }
    } catch (fel) {
        // Sajten ska fungera även om schedule-overrides.json saknas eller är trasig.
    }
})();
