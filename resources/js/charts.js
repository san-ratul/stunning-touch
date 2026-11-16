import {
    Chart,
    LineController,
    BarController,
    DoughnutController,
    LineElement,
    BarElement,
    ArcElement,
    PointElement,
    Filler,
    Tooltip,
    Legend,
    CategoryScale,
    LinearScale,
} from 'chart.js';
import { geoNaturalEarth1, geoPath } from 'd3-geo';
import { feature as topoFeature } from 'topojson-client';
import worldAtlas from 'world-atlas/countries-110m.json';

Chart.register(
    LineController,
    BarController,
    DoughnutController,
    LineElement,
    BarElement,
    ArcElement,
    PointElement,
    Filler,
    Tooltip,
    Legend,
    CategoryScale,
    LinearScale,
);

const worldCollection = topoFeature(worldAtlas, worldAtlas.objects.countries);
const worldFeatures = worldCollection.features;

// ISO 3166-1 alpha-2 -> numeric (world-atlas feature ids are numeric)
const ISO2_TO_NUMERIC = {
    AD:'20', AE:'784', AF:'4', AG:'28', AI:'660', AL:'8', AM:'51', AO:'24', AQ:'10', AR:'32', AS:'16', AT:'40',
    AU:'36', AW:'533', AX:'248', AZ:'31', BA:'70', BB:'52', BD:'50', BE:'56', BF:'854', BG:'100', BH:'48',
    BI:'108', BJ:'204', BL:'652', BM:'60', BN:'96', BO:'68', BQ:'535', BR:'76', BS:'44', BT:'64', BV:'74',
    BW:'72', BY:'112', BZ:'84', CA:'124', CC:'166', CD:'180', CF:'140', CG:'178', CH:'756', CI:'384', CK:'184',
    CL:'152', CM:'120', CN:'156', CO:'170', CR:'188', CU:'192', CV:'132', CW:'531', CX:'162', CY:'196',
    CZ:'203', DE:'276', DJ:'262', DK:'208', DM:'212', DO:'214', DZ:'12', EC:'218', EE:'233', EG:'818',
    EH:'732', ER:'232', ES:'724', ET:'231', FI:'246', FJ:'242', FK:'238', FM:'583', FO:'234', FR:'250',
    GA:'266', GB:'826', GD:'308', GE:'268', GF:'254', GG:'831', GH:'288', GI:'292', GL:'304', GM:'270',
    GN:'324', GP:'312', GQ:'226', GR:'300', GS:'239', GT:'320', GU:'316', GW:'624', GY:'328', HK:'344',
    HM:'334', HN:'340', HR:'191', HT:'332', HU:'348', ID:'360', IE:'372', IL:'376', IM:'833', IN:'356',
    IO:'86', IQ:'368', IR:'364', IS:'352', IT:'380', JE:'832', JM:'388', JO:'400', JP:'392', KE:'404',
    KG:'417', KH:'116', KI:'296', KM:'174', KN:'659', KP:'408', KR:'410', KW:'414', KY:'136', KZ:'398',
    LA:'418', LB:'422', LC:'662', LI:'438', LK:'144', LR:'430', LS:'426', LT:'440', LU:'442', LV:'428',
    LY:'434', MA:'504', MC:'492', MD:'498', ME:'499', MF:'663', MG:'450', MH:'584', MK:'807', ML:'466',
    MM:'104', MN:'496', MO:'446', MP:'580', MQ:'474', MR:'478', MS:'500', MT:'470', MU:'480', MV:'462',
    MW:'454', MX:'484', MY:'458', MZ:'508', NA:'516', NC:'540', NE:'562', NF:'574', NG:'566', NI:'558',
    NL:'528', NO:'578', NP:'524', NR:'520', NU:'570', NZ:'554', OM:'512', PA:'591', PE:'604', PF:'258',
    PG:'598', PH:'608', PK:'586', PL:'616', PM:'666', PN:'612', PR:'630', PS:'275', PT:'620', PW:'585',
    PY:'600', QA:'634', RE:'638', RO:'642', RS:'688', RU:'643', RW:'646', SA:'682', SB:'90', SC:'690',
    SD:'729', SE:'752', SG:'702', SH:'654', SI:'705', SJ:'744', SK:'703', SL:'694', SM:'674', SN:'686',
    SO:'706', SR:'740', SS:'728', ST:'678', SV:'222', SX:'534', SY:'760', SZ:'748', TC:'796', TD:'148',
    TF:'260', TG:'768', TH:'764', TJ:'762', TK:'772', TL:'626', TM:'795', TN:'788', TO:'776', TR:'792',
    TT:'780', TV:'798', TW:'158', TZ:'834', UA:'804', UG:'800', UM:'581', US:'840', UY:'858', UZ:'860',
    VA:'336', VC:'670', VE:'862', VG:'92', VI:'850', VN:'704', VU:'548', WF:'876', WS:'882', YE:'887',
    YT:'175', ZA:'710', ZM:'894', ZW:'716',
};

const NUMERIC_TO_ISO2 = Object.fromEntries(
    Object.entries(ISO2_TO_NUMERIC).map(([k, v]) => [String(v).padStart(3, '0'), k]),
);

function countryColor(value, max) {
    if (!value) return 'rgba(148, 163, 184, 0.4)';
    const ratio = Math.min(1, Math.sqrt(value / max));
    const from = [165, 180, 252];
    const to = [77, 77, 232];
    const mix = from.map((v, i) => Math.round(v + (to[i] - v) * ratio));
    return `rgb(${mix.join(',')})`;
}

const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    layout: { padding: 6 },
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: '#0f172a',
            padding: 10,
            cornerRadius: 8,
            titleSpacing: 4,
            bodySpacing: 4,
        },
    },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: '#64748b', maxTicksLimit: 12, padding: 6 },
        },
        y: {
            grid: { color: 'rgba(100, 116, 139, 0.14)' },
            border: { display: false, dash: [4, 6] },
            ticks: { color: '#64748b', precision: 0, padding: 8 },
            beginAtZero: true,
            grace: '12%',
        },
    },
};

window.STCharts = {
    line(canvasId, { labels, datasets, stacked = false }) {
        const el = document.getElementById(canvasId);
        if (!el) return;

        new Chart(el, {
            type: 'line',
            data: {
                labels,
                datasets: datasets.map((ds) => ({
                    label: ds.label,
                    data: ds.data,
                    borderColor: ds.color,
                    borderWidth: 2.5,
                    backgroundColor: (ctx) => (ds.fill ? gradientFill(ctx, ds.color) : 'transparent'),
                    fill: ds.fill ? 'origin' : false,
                    tension: 0.45,
                    pointRadius: labels.length > 20 ? 0 : 3.5,
                    pointHoverRadius: 6,
                    pointBackgroundColor: ds.color,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 1.5,
                    pointHoverBorderWidth: 2,
                })),
            },
            options: {
                ...baseOptions,
                plugins: {
                    ...baseOptions.plugins,
                    legend: {
                        display: datasets.length > 1,
                        position: 'top',
                        align: 'end',
                        labels: { color: '#475569', boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 16 },
                    },
                },
                scales: {
                    x: { ...baseOptions.scales.x, stacked },
                    y: { ...baseOptions.scales.y, stacked },
                },
            },
        });
    },

    doughnut(canvasId, { labels, data, colors }) {
        const el = document.getElementById(canvasId);
        if (!el) return;

        new Chart(el, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#ffffff', hoverOffset: 6 }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#475569', boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 14 } },
                },
            },
        });
    },

    bar(canvasId, { labels, data, color = '#4d4de8', horizontal = false }) {
        const el = document.getElementById(canvasId);
        if (!el) return;

        new Chart(el, {
            type: 'bar',
            data: { labels, datasets: [{ data, backgroundColor: `${color}cc`, hoverBackgroundColor: color, borderRadius: 6, maxBarThickness: 32 }] },
            options: {
                ...baseOptions,
                indexAxis: horizontal ? 'y' : 'x',
                scales: horizontal
                    ? { x: { ...baseOptions.scales.y }, y: { ...baseOptions.scales.x } }
                    : baseOptions.scales,
            },
        });
    },

    /**
     * Choropleth-style SVG world map of visitor counts per ISO2 country.
     * Rendered with d3-geo (no Chart.js dependency) so every country is a
     * hit zone with a floating tooltip: flag, country name, visitors, views.
     */
    map(containerId, { data } = {}) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const viewsByCode = new Map(
            (data || []).map((d) => [String(d.code).toUpperCase(), d]),
        );
        const max = Math.max(1, ...(data || []).map((d) => Number(d.views) || 0));

        const width = Math.max(320, container.clientWidth || 800);
        const height = Math.max(240, container.clientHeight || Math.round(width / 2));

        const projection = geoNaturalEarth1().fitSize([width, height], worldCollection);
        const path = geoPath(projection);

        const svgNS = 'http://www.w3.org/2000/svg';

        // Floating tooltip (country name + visitors + views)
        const tip = document.createElement('div');
        tip.className = 'map-tooltip';
        tip.style.display = 'none';
        container.appendChild(tip);

        const showTip = (e, info) => {
            const flag = info.code
                ? String.fromCodePoint(...[...info.code.toUpperCase()].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65))
                : '';
            tip.innerHTML =
                `<span class="map-tip-name">` +
                (flag ? `<span class="map-tip-flag">${flag}</span>` : '') +
                `${info.name || 'Unknown'}` +
                `</span>` +
                `<span class="map-tip-count">` +
                `<b>${info.visitors ?? 0}</b>&nbsp;visitor${(info.visitors ?? 0) === 1 ? '' : 's'}` +
                `<i>·</i>` +
                `<b>${info.views ?? 0}</b>&nbsp;view${(info.views ?? 0) === 1 ? '' : 's'}` +
                `</span>`;
            const rect = container.getBoundingClientRect();
            let x = e.clientX - rect.left + 14;
            let y = e.clientY - rect.top + 14;
            // keep the tooltip inside the container bounds
            const tw = tip.offsetWidth || 180;
            const th = tip.offsetHeight || 48;
            if (x + tw > rect.width - 4) x = e.clientX - rect.left - tw - 14;
            if (y + th > rect.height - 4) y = e.clientY - rect.top - th - 14;
            tip.style.left = `${Math.max(4, x)}px`;
            tip.style.top = `${Math.max(4, y)}px`;
            tip.style.display = 'flex';
        };

        const hideTip = () => { tip.style.display = 'none'; };

        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svg.setAttribute('style', 'width:100%;height:100%;display:block');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'World map of visitors by country');

        for (const feature of worldFeatures) {
            const iso2 = NUMERIC_TO_ISO2[String(feature.id)];
            const info = iso2 ? (viewsByCode.get(iso2) ?? { code: iso2, name: null, views: 0, visitors: 0 }) : null;
            const value = info ? Number(info.views) || 0 : 0;

            const d = path(feature);
            if (!d) continue;

            const p = document.createElementNS(svgNS, 'path');
            p.setAttribute('d', d);
            p.setAttribute('fill', countryColor(value, max));
            p.setAttribute('stroke', 'rgba(255, 255, 255, 0.6)');
            p.setAttribute('stroke-width', '0.5');
            p.style.cursor = info ? 'pointer' : 'default';
            p.style.transition = 'fill 0.12s ease';

            if (info && (value > 0 || info.visitors > 0)) {
                p.addEventListener('mouseenter', () => {
                    p.setAttribute('stroke', '#7e98fa');
                    p.setAttribute('stroke-width', '1.5');
                });
                p.addEventListener('mousemove', (e) => showTip(e, {
                    code: iso2,
                    name: info.name || feature.properties?.name,
                    views: info.views,
                    visitors: info.visitors,
                }));
                p.addEventListener('mouseleave', () => {
                    p.setAttribute('stroke', 'rgba(255, 255, 255, 0.6)');
                    p.setAttribute('stroke-width', '0.5');
                    hideTip();
                });
            }

            svg.appendChild(p);
        }

        container.querySelectorAll('svg').forEach((s) => s.remove());
        container.appendChild(svg);
    },
};

function gradientFill(ctx, color) {
    const { chart } = ctx;
    const area = chart.chartArea;
    if (!area) return 'transparent';

    const g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0, `${color}3d`);
    g.addColorStop(0.7, `${color}14`);
    g.addColorStop(1, `${color}00`);
    return g;
}

